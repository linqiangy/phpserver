<?php
// owl
require_once SERVER_BASE . 'thirdparty/MNLogger/Base.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/Exception.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/MNLogger.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/TraceLogger.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/EXLogger.php';

class JmTextWorker extends RpcWorker
{
    public static $appName = 'JmTextWorker';
    public static $rpcTraceLogger = null;
    public static $exLogger = null;

    public function onServe()
    {
        // 业务引导程序bootstrap初始化（没有则忽略）
        $bootstrap = PHPServerConfig::get('workers.'.$this->serviceName.'.bootstrap');
        if(is_file($bootstrap))
        {
            require_once $bootstrap;
        }
        
        // 如果配置了服务名
        if(defined('JM_APP_NAME'))
        {
            self::$appName = JM_APP_NAME;
        }
        elseif($app_name = PHPServerConfig::get('workers.'.$this->serviceName.'.app_name'))
        {
            self::$appName = $app_name;
        }
        else
        {
            // 服务名
            self::$appName = $this->serviceName;
        }
        
        $on = PHPServerConfig::get('trace_log_on') !== null ? PHPServerConfig::get('trace_log_on') : true;
        $logdir = PHPServerConfig::get('trace_log_path') ? PHPServerConfig::get('trace_log_path') : '/home/logs/monitor';
        $config = array(
                        'on' => $on,
                        'app' => self::$appName,
                        'logdir' => $logdir,
        );
        try{
            self::$rpcTraceLogger = @thirdparty\MNLogger\TraceLogger::instance($config);
            if(self::$rpcTraceLogger && $sample = PHPServerConfig::get('trace_log_sample'))
            {
                self::$rpcTraceLogger->setSamplePerRequest($sample);
            }
        }
        catch(Exception $e){}
        
        $on = PHPServerConfig::get('exception_log_on') !== null ? PHPServerConfig::get('exception_log_on') : true;
        $logdir = PHPServerConfig::get('exception_log_path') ? PHPServerConfig::get('exception_log_path') : '/home/logs/monitor';
        $config = array(
                        'on' => $on,
                        'app' => self::$appName,
                        'logdir' => $logdir
        );
        try{
            self::$exLogger = @thirdparty\MNLogger\EXLogger::instance($config);
        }
        catch(Exception $e){
        }
        
        // 初始化统计上报地址
        $report_address = PHPServerConfig::get('workers.'.$this->serviceName.'.report_address');
        if($report_address)
        {
            StatisticClient::config(array('report_address'=>$report_address));
        }
        // 没有配置则使用本地StatisticWorker中的配置
        else
        {
            if($config = PHPServerConfig::get('workers.StatisticWorker'))
            {
                if(!isset($config['ip']))
                {
                    $config['ip'] = '127.0.0.1';
                }
                StatisticClient::config(array('report_address'=>'udp://'.$config['ip'].':'.$config['port']));
            }
        }
    }
    
    protected function process($data)
    {
        self::$currentModule = $data['class'];
        self::$currentInterface = $data['method'];
        self::$currentClientIp = $this->getRemoteIp();
        self::$currentRequestBuffer = json_encode($data);
        
        self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SR($data['class'], $data['method'], $data['params']);
        
        JmTextStatistic::tick();
        $class_name = '\\Handler\\'.$data['class'];
        
        try
        {
            // 请求开始时执行的函数，on_request_start一般在bootstrap初始化
            if(function_exists('on_phpserver_request_start'))
            {
                \on_phpserver_request_start();
            }
            if(class_exists($class_name))
            {
                $call_back = array(new $class_name, $data['method']);
                if(method_exists($call_back[0], 'setRequestInfo'))
                {
                	$call_back[0]->setRequestInfo(array('user'=>$data['user'], 'class'=>$data['class'], 'method'=>$data['method'], 'params'=>$data['params']));
                }
                if(is_callable($call_back))
                {
                    $ctx = call_user_func_array($call_back, $data['params']);
                }
                else
                {
                    throw new Exception("method $class_name::{$data['method']} not exist");
                }
            }
            else
            {
                throw new Exception("class $class_name not exist");
            }
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('SUCCESS', strlen(json_encode($ctx)));
        }
        catch (RpcBusinessException $ex) {
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('EXCEPTION', strlen($ex->__toString()), $ex->__toString());
            $ctx = isset($ctx) && is_array($ctx) ? $ctx : array();
            if ($ex->hasErrors()) {
                $ctx['errors'] = $ex->getErrors();
            } else {
                $ctx['error'] = array(
                    'message' => $ex->getMessage(),
                    'code' => $ex->getCode(),
                );
            }
        }
        catch (Exception $ex)
        {
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('EXCEPTION', strlen($ex->__toString()), $ex->__toString());
            self::$exLogger && self::$exLogger->log($ex);
            $ctx = array(
                'exception' => array(
                    'class' => get_class($ex),
                    'message' => $ex->getMessage(),
                    'code' => $ex->getCode(),
                    'file' => $ex->getFile(),
                    'line' => $ex->getLine(),
                    'traceAsString' => $ex->getTraceAsString(),
                )
            );
        }
        
        // 请求结束时执行的函数，on_request_start一般在bootstrap中初始化
        if(function_exists('on_phpserver_request_finish'))
        {
            // 这里一般是关闭数据库链接等操作
            \on_phpserver_request_finish();
        }
        
        JmTextStatistic::report($data, $ctx, self::$currentClientIp);
        
        $this->send($ctx);
    }

    public function handleFatalErrors() {
        if ($errors = error_get_last()) {
            $this->send(array(
                'exception' => array(
                    'class' => 'ServiceFatalException',
                    'message' => $errors['message'],
                    'file' => $errors['file'],
                    'line' => $errors['line'],
                ),
            ));
        }
    }
    
}


/**
 * 针对JumeiWorker对统计模块的一层封装
 * @author liangl
 */
class JmTextStatistic
{
    protected static $timeStart = 0;
    
    public static function tick()
    {
        self::$timeStart = StatisticClient::tick();
    }

    public static function report($data, $ctx, $source_ip)
    {
        $module = $data['class'];
        $interface = $data['method'];
        $code = 0;
        $msg = '';
        $success = true;
        if(is_array($ctx) && isset($ctx['exception']))
        {
            $success = false;
            $code = isset($ctx['exception']['code']) ? $ctx['exception']['code'] : 40404;
            $msg = isset($ctx['exception']['class']) ? $ctx['exception']['class'] . "::" : '';
            $msg .= isset($ctx['exception']['message']) ? $ctx['exception']['message'] : '';
            $msg .= "\n" . $ctx['exception']['traceAsString'];
            $msg .= "\nREQUEST_DATA:[" . json_encode($data) . "]\n";
        }
        StatisticClient::report($module, $interface, $code, $msg, $success, $source_ip);
        PHPServerWorker::$currentModule = PHPServerWorker::$currentInterface = PHPServerWorker::$currentClientIp = PHPServerWorker::$currentRequestBuffer = '';
    }
}

/**
 * RpcBusinessException
 */
class RpcBusinessException extends \Exception
{
    private $errors;

    /**
     * 构造业务异常类.
     *
     * @param string|array $message 错误消息字符串, 或者多字段的错误 key/values 对,
     *                              values 可以为字符串或 int 型错误代码.
     * @param int $code 可选, 当 $message 为字符串时, 可以制定其 int 型错误代码.
     *
     * @example
     *
     *      use \Core\Lib\RpcBusinessException;
     *
     *      # case 1
     *      throw new RpcBusinessException('错误信息');
     *
     *      # case 2
     *      throw new RpcBusinessException('错误信息', 100);
     *
     *      # case 3
     *      throw new RpcBusinessException(array(
     *          'username' => '用户名不存在',
     *          'password' => '密码不正确',
     *      ));
     *
     *      # case 4
     *      throw new RpcBusinessException(array(
     *          'username' => 2001,
     *          'password' => 2002,
     *      ));
     */
    public function __construct($message, $code = 0)
    {
        $args = func_get_args();

        if (is_array($message)) {
            if (empty($message)) {
                throw new \Exception('You won\'t throw RpcBusinessException with an empty array.');
            }
            $this->errors = $message;
            $args[0] = 'Business Errors';
        }

        call_user_func_array(array($this, 'parent::__construct'), $args);
    }

    /**
     * 检查是否为错误 key/values 对.
     *
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * 返回错误 key/values 对.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
}

