<?php
namespace Thrift;
/**
 * 
 * thrift异步客户端实例
 * @author liangl
 *
 */
class ThriftInstance
{
    /**
     * 异步发送前缀
     * @var string
     */
    const ASYNC_SEND_PREFIX = 'asend_';
    
    /**
     * 异步接收后缀
     * @var string
     */
    const ASYNC_RECV_PREFIX = 'arecv_';
    
    /**
     * 服务名（被调用的类名）
     * @var string
     */
    public $serviceName = '';
    
    /**
     * 项目名
     * @var string
     */
    public $projectName = '';
    
    /**
     * thrift实例
     * @var array
     */
    protected $thriftInstance = null;
    
    /**
     * thrift异步实例['asend_method1'=>thriftInstance1, 'asend_method2'=>thriftInstance2, ..]
     * @var array
     */
    protected $thriftAsyncInstances = array();
    
    /**
     * 远程地址
     * @var string
     */
    protected $remoteAddress = '';
    
    /**
     * traceLogger
     * @var traceLogger
     */
    protected static $traceLogger = null;
    
    /**
     * 初始化工作
     * @return void
     */
    public function __construct($serviceName, $projectName = '')
    {
        if(empty($serviceName))
        {
            $e = new \Exception('serviceName can not be empty', 500);
            if(\Thrift\Client::$logger)
            {
                \Thrift\Client::$logger->logError($e);
            }
            throw $e;
        }
        
        if(!self::$traceLogger)
        {
            $config = array(
                    'on' => true,
                    'app' => defined('JM_APP_NAME') ? JM_APP_NAME : 'php-rpc-client',
                    'logdir' => \Thrift\Client::$traceLogPath,
            );
            try
            {
                self::$traceLogger = @\MNLogger\TraceLogger::instance($config);
            }
            catch(\Exception $e){
            }
        }
        
        $this->serviceName = $serviceName;
        $this->projectName = $projectName ? $projectName : $serviceName;
        $classname = '\Provider\\' . $this->serviceName . "\\" . $this->serviceName . "Client";
        if(!class_exists($classname, false))
        {
            $this->includeFile($classname);
        }
        
    }
    
    /**
     * 方法调用
     * @param string $name
     * @param array $arguments
     * @return mix
     */
    public function __call($method_name, $arguments)
    {
        $time_start = microtime(true);
        // 异步发送
        if(0 === strpos($method_name ,self::ASYNC_SEND_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_SEND_PREFIX));
            \Thrift\Context::put('methodName', $real_method_name);
            $arguments_key = var_export($arguments,true);
            $method_name_key = $method_name . $arguments_key;
            // 判断是否已经有这个方法的异步发送请求
            if(isset($this->thriftAsyncInstances[$method_name_key]))
            {
                // 如果有这个方法发的异步请求，则删除
                $this->thriftAsyncInstances[$method_name_key] = null;
                unset($this->thriftAsyncInstances[$method_name_key]);
                $e = new \Exception($this->serviceName."->$method_name(".implode(',',$arguments).") already has been called, you can't call again before you call ".self::ASYNC_RECV_PREFIX.$real_method_name, 500);
                \Thrift\Client::$logger->logError($e);
            }
            try{
                $instance = $this->__instance();
                self::$traceLogger && self::$traceLogger->RPC_CS($this->remoteAddress, $this->serviceName, $method_name, $arguments);
                $callback = array($instance, 'send_'.$real_method_name);
                if(!is_callable($callback))
                {
                    throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
                }
                $ret = call_user_func_array($callback, $arguments);
            }
            catch (\Exception $e)
            {
                self::$traceLogger && self::$traceLogger->RPC_CR('EXCEPTION', strlen($e), $e->__toString());
                \Thrift\Client::$logger->logError('use time('.(microtime(true)-$time_start).') '.$e);
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, $e->getCode());
                throw $e;
            }
            // 保存实例
            $this->thriftAsyncInstances[$method_name_key] = $instance;
            $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 1);
            return $ret;
        }
        // 异步接收
        if(0 === strpos($method_name, self::ASYNC_RECV_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_RECV_PREFIX));
            $send_method_name = self::ASYNC_SEND_PREFIX.$real_method_name;
            $arguments_key = var_export($arguments,true);
            $method_name_key = $send_method_name . $arguments_key;
            // 判断是否有发送过这个方法的异步请求
            if(!isset($this->thriftAsyncInstances[$method_name_key]))
            {
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, 1500);
                $e = new \Exception($this->serviceName."->$send_method_name(".implode(',',$arguments).") have not previously been called, call " . $this->serviceName."->".self::ASYNC_SEND_PREFIX.$real_method_name."(".implode(',',$arguments).") first", 1500);
                \Thrift\Client::$logger->logError($e);
                throw $e;
            }
            
            // 创建个副本
            $instance = $this->thriftAsyncInstances[$method_name_key];
            // 删除原实例，避免异常时没清除
            $this->thriftAsyncInstances[$method_name_key] = null;
            unset($this->thriftAsyncInstances[$method_name_key]);
            
            try{
                $callback = array($instance, 'recv_'.$real_method_name);
                if(!is_callable($callback))
                {
                    throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
                }
                // 接收请求
                $ret = call_user_func_array($callback, array());
            }catch (\Exception $e)
            {
                \Thrift\Client::$logger->logError('use time('.(microtime(true)-$time_start).') '.$e);
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, $e->getCode());
                self::$traceLogger && self::$traceLogger->RPC_CR('EXCEPTION', strlen($e), $e->__toString());
                throw $e;
            }
            self::$traceLogger && self::$traceLogger->RPC_CR('SUCCESS', strlen(json_encode($ret)));
            $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 1);
            
            return $ret;
        }
        
        \Thrift\Context::put('methodName', $method_name);
        $success = true;
        try {
            // 每次都重新初始化一个实例
            $this->thriftInstance = $this->__instance();
            self::$traceLogger && self::$traceLogger->RPC_CS($this->remoteAddress, $this->serviceName, $method_name, $arguments);
            $callback = array($this->thriftInstance, $method_name);
            if(!is_callable($callback))
            {
                throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
            }
            $ret = call_user_func_array($callback, $arguments);
            $this->thriftInstance = null;
        }
        catch(\Exception $e)
        {
            $this->thriftInstance = null;
            \Thrift\Client::$logger->logError('use time('.(microtime(true)-$time_start).') '.$e);
            $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, $e->getCode());
            self::$traceLogger && self::$traceLogger->RPC_CR('EXCEPTION', strlen($e), $e->__toString());
            throw $e;
        }
        
        self::$traceLogger && self::$traceLogger->RPC_CR('SUCCESS', strlen(json_encode($ret)));
        
        $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 1);
        // 统一日志监控 MNLogger END
        return $ret;
    }
    
    /**
     * 统一日志
     * @param string $service_name
     * @param string $method_name
     * @param integer $count
     * @param float $cost_time
     * @param integer $success
     * @param integer $code
     * @return void
     */
    protected function mnlog($service_name, $method_name, $count, $cost_time, $success, $code = 0)
    {
    }
    
    /**
     * 获取一个实例
     * @return instance
     */
    protected function __instance()
    {
        if (\Thrift\Context::get('serverName') != $this->serviceName){
            \Thrift\Context::put('serverName', $this->serviceName);
        }
        $address = \Thrift\Client::getOneAddress($this->projectName);
        $this->remoteAddress = $address;
        list($ip, $port) = explode(':', $address);
        $socket = new \Thrift\Transport\TSocket($ip, $port);
        // 接收超时
        if(($timeout = \Thrift\Context::get('timeout')) && $timeout >= 1)
        {
            $socket->setRecvTimeout($timeout*1000);
        }
        else 
        {
            // 默认30秒
            $socket->setRecvTimeout(30000);
        }
        // thrift设置链接超时时间，5s
        $socket->setSendTimeout(5000);
        
        $transport = new \Thrift\Transport\TFramedTransport($socket);
        $pname = \Thrift\Context::get('protocol') ? \Thrift\Context::get('protocol') : 'binary';
        $protocolName = self::getProtocol($pname);
        $protocol = new $protocolName($transport);
        
        $classname = '\Provider\\' . $this->serviceName . "\\" . $this->serviceName . "Client";
        if(!class_exists($classname, false))
        {
            $this->includeFile($classname);
        }
        try 
        {
            $transport->open();
        }
        catch(\Exception $e)
        {
            \Thrift\Client::kickAddress($address);
            \Thrift\Client::$logger->logError("kickAddress $address and now bad address list : ".json_encode(\Thrift\Client::getBadAddressList())."\n".$e);
            throw $e;
        }
        
        return new $classname($protocol);
    }
    
    /**
     * 载入provider文件
     * @return void
     */
    protected function includeFile($classname)
    {
        $config = Client::config();
        $provider_dir = $config[$this->projectName]['provider'];
        $include_file_array = glob($provider_dir.'/'.$this->serviceName.'/*.php');
        foreach($include_file_array as $file)
        {
            include_once $file;
        }
        if(!class_exists($classname))
        {
            $e = new \Exception("Can not find class $classname in directory $provider_dir/".$this->serviceName.'/');
            \Thrift\Client::$logger->logError($e);
            throw $e;
        }
    }
    
    /**
     * getProtocol
     * @param string $key
     * @return string
     */
    private static function getProtocol($key=null)
    {
        $protocolArr = array(
            'binary' =>'Thrift\Protocol\TBinaryProtocol',
            'compact'=>'Thrift\Protocol\TCompactProtocol',
            'json'   =>'Thrift\Protocol\TJSONProtocol',
        );
        return isset($protocolArr[$key]) ? $protocolArr[$key] : $protocolArr['binary'];
    }
}
