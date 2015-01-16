<?php 
require_once SERVER_BASE . 'thirdparty/MNLogger/Base.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/Exception.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/MNLogger.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/TraceLogger.php';

class JumeiWorker extends RpcWorker
{
    public static $appName = 'JumeiWorker';
    public static $rpcTraceLogger = null;

    public function onServe()
    {
        $app_name = PHPServerConfig::get('workers.'.$this->serviceName.'.framework.app_name');
        if($app_name)
        {
            self::$appName = $app_name;
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
        catch(Exception $e)
        {
        
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
        
        if (!class_exists('Core\Lib\RpcServer')) {
            $frameworkBootstrap = PHPServerConfig::get('workers.'.$this->serviceName.'.framework.path') .
                '/Serverroot/Autoload.php';
            require_once $frameworkBootstrap;
        }

        StatisticHelper::tick();
        
        $rpcServer = new Core\Lib\RpcServer;
        $ctx = $rpcServer->run($data);
        
        StatisticHelper::report($data, $ctx, $this->getRemoteIp());
        
        $this->send($ctx);
        
        $ctx_string = json_encode($ctx);
        if(is_array($ctx) && isset($ctx['exception']))
        {
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('EXCEPTION', strlen($ctx_string), $ctx_string);
        }
        elseif(is_array($ctx) && (isset($ctx['error']) || isset($ctx['errors'])))
        {
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('EXCEPTION', strlen($ctx_string), $ctx_string);
        }
        else 
        {
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('SUCCESS', strlen($ctx_string));
        }
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
class StatisticHelper
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

