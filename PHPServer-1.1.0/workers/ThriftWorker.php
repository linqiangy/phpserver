<?php
/**
 * thriftWorke
 * @author libingw <libingw@jumei.com>
 * @author liangl  <liangl3@jumei.com>
 * 
 */
require_once SERVER_BASE . 'thirdparty/Thrift/Thrift/Context.php';
require_once SERVER_BASE . 'thirdparty/Thrift/Thrift/ContextSerialize.php';
require_once SERVER_BASE . 'thirdparty/Thrift/Thrift/ContextReader.php';
require_once SERVER_BASE . 'thirdparty/Thrift/Thrift/ClassLoader/ThriftClassLoader.php';

// owl 相关
require_once SERVER_BASE . 'thirdparty/MNLogger/Base.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/Exception.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/MNLogger.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/TraceLogger.php';
require_once SERVER_BASE . 'thirdparty/MNLogger/EXLogger.php';

use Thrift\ClassLoader\ThriftClassLoader;

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', SERVER_BASE . 'thirdparty/Thrift');
$loader->register();

define('IN_THRIFT_WORKER', true);

class ThriftWorker extends PHPServerWorker
{
    /**
     * traceLogge
     * @var object
     */
    public static $rpcTraceLogger = null;
    
    /**
     * exLogger
     * @var exLogger
     */
    public static $exLogger = null;
    
    /**
     * 存放thrift生成文件的目录
     * @var string
     */
    protected $providerDir = null;
    
    /**
     * 存放对thrift生成类的实现的目录
     * @var string
     */
    protected $handlerDir = null;
    
    /**
     * thrift生成类的命名空间
     * @var string
     */
    protected $providerNamespace = 'Provider';
    
    /**
     * thrift生成类实现的命名空间
     * @var string
     */
    protected $handlerNamespace = 'Provider';
    
    /**
     * 服务名
     * @var string
     */
    public static $appName = 'ThriftWorker';
    
    /**
     * 进程启动时的一些初始化
     * @see PHPServerWorker::onServe()
     */
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
        
        // 初始化thrift生成文件存放目录
        $provider_dir = PHPServerConfig::get('workers.'.$this->serviceName.'.provider');
        if($provider_dir)
        {
            if($this->providerDir = realpath($provider_dir))
            {
                if($path_array = explode('/', $this->providerDir))
                {
                    $this->providerNamespace = $path_array[count($path_array)-1];
                }
            }
            else
            {
                $this->providerDir = $provider_dir;
                $this->notice('provider_dir '.$provider_dir. ' not exsits');
            }
        }
        
        // 初始化thrift生成类业务实现存放目录
        $handler_dir = PHPServerConfig::get('workers.'.$this->serviceName.'.handler');
        if($handler_dir)
        {
            if($this->handlerDir = realpath($handler_dir))
            {
                if($path_array = explode('/', $this->handlerDir))
                {
                    $this->handlerNamespace = $path_array[count($path_array)-1];
                }
            }
            else
            {
                $this->handlerDir = $handler_dir;
                $this->notice('handler_dir' . $handler_dir. ' not exsits');
            }
        }
        else
        {
            $this->handlerDir = $provider_dir;
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
    
    /**
     * 处理thrift包，判断包是否接收完整
     * 固定使用TFramedTransport，前四个字节是包体长度信息
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str) {
        // 不够4字节
        if(strlen($recv_str) < 4)
        {
            return 1;
        }
        // 如果是文本协议
        if(strpos($recv_str, "\nRPC") === 1 || strpos($recv_str, "\nTEST") === 1 || strpos($recv_str, "\nPING") === 1)
        {
            return $this->dealTextInput($recv_str);
        }
        // thrift协议
        else 
        {
            return $this->dealThriftInput($recv_str);
        }
        
    }
    
    /**
     * 处理文本协议输入
     * @param unknown_type $recv_st
     * @return Ambigous <number, boolean>
     */
    public function dealTextInput($recv_str)
    {
        return Text::input($recv_str);
    }
    
    /**
     * 处理thrift协议输入
     * @param unknown_type $recv_st
     */
    public function dealThriftInput($recv_str)
    {
        $val = unpack('N', $recv_str);
        $length = $val[1] + 4;
        if ($length <= Thrift\Factory\TStringFuncFactory::create()->strlen($recv_str)) {
            return 0;
        }
        return 1;
    }
    
    /**
     * 业务逻辑(non-PHPdoc)
     * @see Worker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $_SERVER['REMOTE_ADDR'] = $this->getRemoteIp();
        
        // 如果是文本协议
        if(strpos($recv_str, "\nRPC") === 1 || strpos($recv_str, "\nTEST") === 1 || strpos($recv_str, "\nPING") === 1)
        {
        	$this->currentProtocol = 'text';
            return $this->dealTextProcess($recv_str);
        }
        // thrift协议
        else
        {
        	$this->currentProtocol = 'thrift';
            return $this->dealThriftProcess($recv_str);
        }
    }
    
    /**
     * 处理数据流.
     *
     * @param string $recv_str 接收到的数据流.
     *
     * @throws Exception 抛出开发时错误.
     *
     * @return void
     */
    public function dealTextProcess($recv_str)
    {
        try {
            if (($data = Text::decode($recv_str)) === false) {
                throw new Exception('RpcWorker: You want to check the RPC protocol.');
            }
    
            if ($data['command'] === 'TEST' && $data['data'] === 'PING') {
                $this->send('PONG');
                return;
            }
    
            $this->rpcCompressor = null;
            if (strpos($data['command'], 'RPC:') === 0) {
                $this->rpcCompressor = substr($data['command'], strpos($data['command'], ':') + 1);
            } elseif ($data['command'] !== 'RPC') {
                throw new Exception('RpcWorker: Oops! I am going to do nothing but RPC.');
            }
    
            $data = $data['data'];
    
            if ($this->rpcCompressor === 'GZ') {
                $data = @gzuncompress($data);
            }
            $packet = json_decode($data, true);
            
            global $context;
            $context = array();
            if(isset($packet['CONTEXT']))
            {
                $context = $packet['CONTEXT'];
            }
    
            if ($this->encrypt($packet['data'], PHPServerConfig::get('rpc_secret_key')) !== $packet['signature']) {
                throw new Exception('RpcWorker: You want to check the RPC secret key, or the packet has broken.');
            }
    
            $data = json_decode($packet['data'], true);
            if (empty($data['version']) || $data['version'] !== '2.0') {
                throw new Exception('RpcWorker: Hmm! We are now expect version 2.0.');
            }
    
            $prefix = 'RpcClient_';
            if (strpos($data['class'], $prefix) !== 0) {
                throw new Exception(sprintf('RpcWorker: Mmm! RPC class name should be prefix with %s.', $prefix));
            }
            $data['class'] = substr($data['class'], strlen($prefix));
            
            // 权限检查
            if($_SERVER['REMOTE_ADDR'] !== '127.0.0.1')
            {
            	if(!PHPServerWorker::hasAuth($this->serviceName, $data['class'], $data['method'], $_SERVER['REMOTE_ADDR']))
            	{
            		throw new \Exception("{$_SERVER['REMOTE_ADDR']} has no permissions to access {$this->serviceName} {$data['class']}->{$data['method']}. Permission denied.");
            	}
            }
    
            $this->process($data);
        } catch (Exception $ex) {
            self::$exLogger && self::$exLogger->log($ex);
            $this->send(
                array(
                    'exception' => array(
                        'class' => get_class($ex),
                        'message' => $ex->getMessage(),
                        'code' => $ex->getCode(),
                        'file' => $ex->getFile(),
                        'line' => $ex->getLine(),
                        'traceAsString' => $ex->getTraceAsString(),
                    )
                )
            );
        }
    }
    
    protected function process($data)
    {
        self::$currentModule = $data['class'];
        self::$currentInterface = $data['method'];
        self::$currentClientIp = $this->getRemoteIp();
        self::$currentRequestBuffer = json_encode($data);
        
        // owl trace 上下文
        global $owl_context;
        $owl_context = \Thrift\Context::get("owl_context");
        if(empty($owl_context))
        {
            $owl_context = null;
        }
        else
        {
            $owl_context = json_decode($owl_context, true);
        }
        self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SR($data['class'], $data['method'], $data['params']);
        
        JmTextStatistic::tick();
        $class_name = '\\Handler\\'.$data['class'];
        
        if(!class_exists($class_name, false))
        {
            $type_file = $this->providerDir.'/'.$data['class'].'/Types.php';
            if(is_file($type_file))
            {
                require_once $type_file;
            }
            $class_file = $this->providerDir.'/'.$data['class'].'/'.$data['class'].'.php';
            if(is_file($class_file))
            {
                require_once $class_file;
            }
        }
        
        $handler_file = $this->handlerNamespace == 'Provider' ? $this->handlerDir.'/'.$data['class'].'/'.$data['class'].'Handler.php' : $this->handlerDir.'/'.$data['class'].'.php';
        if(is_file($handler_file))
        {
            require_once $handler_file;
            if($this->handlerNamespace == 'Provider')
            {
                $class_name = '\\Provider\\'.$data['class'].'\\'.$data['class'].'Handler';
            }
        }
        
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
        
        JmTextStatistic::report($data, $ctx, $this->getRemoteIp());
        
        $this->send($ctx);
    }
    
    /**
     * 发送数据回客户端.
     *
     * @param mixed $data 业务数据.
     *
     * @return void
     */
    protected function send($data)
    {
        $data = json_encode($data);
        $this->sendToClient(Text::encode($data));
    }
    
    /**
     * 数据签名.
     *
     * @param string $data   待签名的数据.
     * @param string $secret 私钥.
     *
     * @return string
     */
    private function encrypt($data, $secret)
    {
        return md5($data . '&' . $secret);
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

    /**
     * 业务处理(non-PHPdoc)
     * @see PHPServerWorker::dealProcess()
     */
    public function dealThriftProcess($recv_str) {
        // 拷贝一份数据包，用来记录日志
        $recv_str_copy = $recv_str;
        // 统计监控记录请求开始时间点，后面用来统计请求耗时
        JmThriftStatistic::tick();
        // 清除上下文信息
        \Thrift\Context::clear();
        // 服务名
        $serviceName = $this->serviceName;
        // 本地调用方法名
        $method_name = 'none';
        // 来源ip
        $source_ip = $this->getRemoteIp();
        // 尝试读取上下文信息
        try{
            // 去掉TFrameTransport头
            $body_str = substr($recv_str, 4);
            // 读上下文,并且把上下文数据从数据包中去掉
            \Thrift\ContextReader::read($body_str);
            // 再组合成TFrameTransport报文
            $recv_str = pack('N', strlen($body_str)).$body_str;
            // 如果是心跳包
            if (\Thrift\Context::get('isHeartBeat')=='true'){
                $thriftsocket = new \Thrift\Transport\TBufferSocket();
                $thriftsocket->setHandle($this->connections[$this->currentDealFd]);
                $thriftsocket->setBuffer($recv_str);
                $framedTrans = new \Thrift\Transport\TFramedTransport($thriftsocket);
                $protocol = new Thrift\Protocol\TBinaryProtocol($framedTrans, false, false);
                $protocol->writeMessageBegin('#$%Heartbeat', 2, 0);
                $protocol->writeMessageEnd();
                $protocol->getTransport()->flush();
                return;
            }
        }
        catch(Exception $e)
        {
            self::$exLogger && self::$exLogger->log($e);
            // 将异常信息发给客户端
            $this->writeExceptionToClient($method_name, $e, self::getProtocol(\Thrift\Context::get('protocol')));
            // 统计上报
            JmThriftStatistic::report($serviceName, $method_name, $source_ip, $e, $recv_str_copy);
            return;
        }
        
        // 客户端有传递超时参数
        if(($timeout = \Thrift\Context::get("timeout")) && $timeout >= 1)
        {
            pcntl_alarm($timeout);
        }
        
        // 客户端有传递服务名
        if(\Thrift\Context::get('serverName'))
        {
            $serviceName = \Thrift\Context::get('serverName');
        }
        
        // 客户端有传递服务名
        if(\Thrift\Context::get('methodName'))
        {
            $method_name = \Thrift\Context::get('methodName');
        }
        
        self::$currentModule = $serviceName;
        self::$currentInterface = $method_name;
        self::$currentClientIp = $source_ip;
        self::$currentRequestBuffer = bin2hex($recv_str);
        
        // owl trace 上下文
        global $owl_context;
        $owl_context = \Thrift\Context::get("owl_context");
        if(empty($owl_context))
        {
            $owl_context = null;
        }
        else
        {
            $owl_context = json_decode($owl_context, true);
        }
        
        self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SR($serviceName, $method_name, '[thrift]');
        
        // 尝试处理业务逻辑
        try {
        	
        	// 检查权限
        	if($_SERVER['REMOTE_ADDR'] !== '127.0.0.1')
        	{
        		if(!PHPServerWorker::hasAuth($this->serviceName, $serviceName, $method_name, $_SERVER['REMOTE_ADDR']))
        		{
        			throw new \Exception("{$_SERVER['REMOTE_ADDR']} has no permissions to access {$this->serviceName} {$serviceName}->{$method_name}. Permission denied.");
        		}
        	}
        	
            // 服务名为空
            if (!$serviceName){
                throw new \Exception('Context[serverName] empty', 400);
            }
            
            // 如果handler命名空间为provide
            if($this->handlerNamespace == 'Provider')
            {
                $handlerClass = $this->handlerNamespace.'\\'.$serviceName.'\\' . $serviceName . 'Handler';
            }
            else
            {
                $handlerClass = $this->handlerNamespace.'\\' . $serviceName;
            }
            
            // processo
            $processorClass = $this->providerNamespace . '\\' . $serviceName . '\\' . $serviceName . 'Processor';
            
            // 文件不存在尝试从磁盘上读取
            if(!class_exists($handlerClass, false))
            {
                clearstatcache();
                if(!class_exists($processorClass, false))
                {
                    require_once $this->providerDir.'/'.$serviceName.'/Types.php';
                    require_once $this->providerDir.'/'.$serviceName.'/'.$serviceName.'.php';
                }
                
                $handler_file = $this->handlerNamespace == 'Provider' ? $this->handlerDir.'/'.$serviceName.'/'.$serviceName.'Handler.php' : $this->handlerDir.'/'.$serviceName.'.php';
                if(is_file($handler_file))
                {
                    require_once $handler_file;
                }
                
                if(!class_exists($handlerClass))
                {
                    throw new \Exception('Class ' . $handlerClass . ' not found', 404);
                }
            }
            
            // 运行thrift
            $handler = new $handlerClass();
            $processor = new $processorClass($handler);
            $pname = \Thrift\Context::get('protocol') ? \Thrift\Context::get('protocol') : 'binary';
            $protocolName = self::getProtocol($pname);
            $this->subProtocol = $protocolName;
            $thriftsocket = new \Thrift\Transport\TBufferSocket();
            $thriftsocket->setHandle($this->connections[$this->currentDealFd]);
            $thriftsocket->setBuffer($recv_str);
            $framedTrans = new \Thrift\Transport\TFramedTransport($thriftsocket, true, true);
            $protocol = new $protocolName($framedTrans, false, false);
            $protocol->setTransport($framedTrans);
            // 请求开始时执行的函数，on_request_start一般在bootstrap初始化
            if(function_exists('on_phpserver_request_start'))
            {
                \on_phpserver_request_start();
            }
            $mem_start = memory_get_usage();
            $processor->process($protocol, $protocol);
            $mem_usage = memory_get_usage() - $mem_start;
            // 请求结束时执行的函数，on_request_start一般在bootstrap中初始化
            if(function_exists('on_phpserver_request_finish'))
            {
                // 这里一般是关闭数据库链接等操作
                \on_phpserver_request_finish();
            }
            $method_name = $protocol->fname;
        }
        catch (Exception $e)
        {
            self::$exLogger && self::$exLogger->log($e);
            // 异常信息返回给客户端
            $method_name = !empty($protocol->fname) ? $protocol->fname : 'none';
            $this->writeExceptionToClient($method_name, $e, !empty($protocolName) ? $protocolName : 'Thrift\Protocol\TBinaryProtocol');
            JmThriftStatistic::report($serviceName, $method_name, $source_ip, $e, $recv_str_copy);
            self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('EXCEPTION', strlen($e->__toString()), $e->__toString());
            return;
        }
        // 统计上报
        JmThriftStatistic::report($serviceName, $method_name, $source_ip);
        self::$rpcTraceLogger && self::$rpcTraceLogger->RPC_SS('SUCCESS', $mem_usage);
    }

    /**
     * 获取协议全名
     * @param string $key
     * @return string
     */
    private static function getProtocol($key=null){
        $protocolArr = array(
          'binary'=>'Thrift\Protocol\TBinaryProtocol',
          'compact'=>'Thrift\Protocol\TCompactProtocol',
          'json'   => 'Thrift\Protocol\TJSONProtocol',
        );
        return isset($protocolArr[$key]) ? $protocolArr[$key] : $protocolArr['binary'];
    }
    
    /**
     * 将异常写会客户端
     * @param string $name
     * @param Exception $e
     * @param string $protocol
     */
    protected function writeExceptionToClient($name, $e, $protocol = 'Thrift\Protocol\TBinaryProtocol')
    {
        try {
            $ex = new \Thrift\Exception\TApplicationException($e);
            $thriftsocket = new \Thrift\Transport\TBufferSocket();
            $thriftsocket->setHandle($this->connections[$this->currentDealFd]);
            $framedTrans = new \Thrift\Transport\TFramedTransport($thriftsocket, true, true);
            $protocol = new $protocol($framedTrans, false, false);
            $protocol->writeMessageBegin($name, \Thrift\Type\TMessageType::EXCEPTION, 0);
            $ex->write($protocol);
            $protocol->writeMessageEnd();
            $protocol->getTransport()->flush();
        }
        catch(Exception $e)
        {
            
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
 * 针对JumeiWorker对统计模块的一层封装
 * @author liangl
 */
class JmThriftStatistic
{
    protected static $timeStart = 0;

    public static function tick()
    {
        self::$timeStart = StatisticClient::tick();
    }

    public static function report($serviceName, $method, $source_ip, $exception = null, $request_data = '')
    {
        $success = empty($exception);
        $code = 0;
        $msg = '';
        $success = true;
        if($exception)
        {
            $success = false;
            $code = $exception->getCode();
            $msg = $exception;
            $msg .= "\nREQUEST_DATA:[" . bin2hex($request_data) . "]\n";
        }

        StatisticClient::report($serviceName, $method, $code, $msg, $success, $source_ip);
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