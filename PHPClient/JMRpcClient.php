<?php
namespace PHPClient;

class JMRpcResultError
{
    public $error;
    public $code;
    public $debugData;

    public function __construct($error = '', $code = -1, $debugData = null)
    {
        $this->error = $error;
        $this->code = $code;
        $this->debugData = $debugData;
    }

    public function __toString()
    {
        return $this->error;
    }

    static public function FromRpcResultError($rpcError)
    {
        $err = new JMRpcResultError($rpcError['error'], $rpcError['code'], $rpcError['debugData']);
        return $err;
    }
}

class JMRpcException extends \Exception {}
class JMRpcServerInternalException extends JMRpcException {}
class JMRpcDelegatedException extends JMRpcException
{
    static public function FromRpcException($rpcEx)
    {
        $ex = new JMRpcDelegatedException($rpcEx['class'] . ':' . $rpcEx['message'], $rpcEx['code']);
        $ex->delegatedExceptionClass = $rpcEx['class'];
        $ex->delegatedCode = $rpcEx['code'];
        $ex->delegatedFile  = $rpcEx['file'];
        $ex->delegatedLine = $rpcEx['line'];
        $ex->delegatedMessage = $rpcEx['message'];
        $ex->delegatedTrace = $rpcEx['trace'];
        $ex->delegatedTraceAsString = $rpcEx['traceAsString'];
        return $ex;
    }

    protected $delegatedExceptionClass;
    protected $delegatedMessage;
    protected $delegatedCode;
    protected $delegatedFile;
    protected $delegatedLine;
    protected $delegatedTrace;
    protected $delegatedTraceAsString;
    final public function getDelegatedExceptionClass () {return $this->delegatedExceptionClass;}
    final public function getDelegatedMessage () {return $this->delegatedMessage;}
    final public function getDelegatedCode () {return $this->delegatedCode;}
    final public function getDelegatedFile () {return $this->delegatedFile;}
    final public function getDelegatedLine () {return $this->delegatedLine;}
    final public function getDelegatedTrace () {return $this->delegatedTrace;}
    final public function getDelegatedTraceAsString () {return $this->delegatedTraceAsString;}
}
class JMRpcClientInternalException extends JMRpcException
{
    public $responseText;
    public $result;
}

class JMRpcClient {
    const Protocol_Json = 'json';
    const Protocol_Php = 'php';

    const HandleExceptionAs_Exception = 'exception';
    const HandleExceptionAs_Null = 'null';
    const HandleExceptionAs_Error = 'error';

    public $endpoint;
    public $currentMethodName;
    public $lastResult;
    public $lastResponseText;
    public $arguments;

    protected static $config;
    
    static public $Default_HandleExceptionAs = false;
    static public $Default_Protocol = 'php';
    static public $Default_ShowOutput = false;
    static public $Default_SessionId = null;
    static public $Default_RpcOptions = null;

    static public $StatsCallback;

    protected $handleExceptionAs = null;
    protected $protocol = null;
    protected $showOutput = null;
    protected $sessionId = null;
    protected $rpcOptions = null;
    protected $rpcClass;
    protected $lastException;
    

    public function __construct($endpoint = 'default') {
        if(!static::$config)
        {
            static::$config = (array) new \Config\PHPClient();
        }

        $this->_setEndpoint($endpoint);
        $this->rpcClass = get_class($this);
    }

    /**
     * Setup/Get configurations.
     *
     * @param array|null $configs
     * @return array
     */
    public static function config($configs=null)
    {
        if(is_null($configs)) return static::$config;
        return static::$config = $configs;
    }

    public function _setEndpoint($endpoint)
    {
        if(is_array($endpoint))
        {
            $this->endpoint =  $endpoint;
        }
        else if(isset(static::$config['Rpc'][$endpoint]))
        {
            $this->endpoint = static::$config['Rpc'][$endpoint];
        }
        else
        {
            throw new JMRpcDelegatedException('endpoint "'.$endpoint.'" not configured!');
        }

        return $this;
    }

    public function _setClass($class)
    {
        $this->rpcClass = $class;
        return $this;
    }

    public function __call($name, $arguments)
    {
        return $this->_internalCallWithId($name, 0, $arguments);
    }

    private function _internalCallWithId($name, $id, $arguments) {
        $this->currentMethodName = $name;
        $this->arguments = $arguments;
        
        $protocol = ($this->protocol === null) ? self::$Default_Protocol : $this->protocol;
        $showOutput = ($this->showOutput === null) ? self::$Default_ShowOutput : $this->showOutput;
        $sessionId = ($this->sessionId === null) ? self::$Default_SessionId : $this->sessionId;

        $rpcData = array(
            'version'=>'1.0',
            'class'=>$this->rpcClass,
            'method'=>$name,
            'params'=>$arguments,
            'options'=> ($this->rpcOptions === null) ? self::$Default_RpcOptions : $this->rpcOptions,
            'id'=>$id,
        );

        if($protocol == self::Protocol_Json)
            $rpcDataString = json_encode($rpcData);
        else if($protocol == self::Protocol_Php)
            $rpcDataString = serialize($rpcData);

        $requestGetString = http_build_query(array(
                    'protocol' => $protocol,
                    'sessionId' => $sessionId,
                    'user' => $this->endpoint['User'],
                    'sign' => md5($protocol . $sessionId . $rpcDataString . $this->endpoint['Secret'])
                ), '', '&');

        $url = $this->endpoint['Url'] . '?' . $requestGetString;
        $ex = null;

        $this->lastResult = array();

        /*
        $opts = array('http' =>
            array(
                'method' => 'POST',
                'header' => "Content-type: application/x-rm-rpc\r\nConnection: close\r\n",
                'content' => $rpcDataString,
            )
        );

        $context = stream_context_create($opts);
        $this->lastResponseText = file_get_contents(, false, $context);
        */

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        $requestHeaders = array(
                            'Content-Type: application/x-rm-rpc; charset=utf-8',
                            'Connection: close',
                      );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rpcDataString);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $this->lastResponseText = curl_exec($ch);
        
        $this->rpcMonitor();//新增rpc监控
        if($this->lastResponseText === false)
        {
            $ex = new JMRpcClientInternalException(curl_error($ch), curl_errno($ch));
        }
        curl_close($ch);

        if($this->lastResponseText)
        {
            if($protocol == self::Protocol_Json)
                $this->lastResult = @json_decode($this->lastResponseText, true);
            else if($protocol == self::Protocol_Php)
                $this->lastResult = @unserialize($this->lastResponseText);

            if($this->lastResult)
            {
                if($showOutput && ! empty($this->lastResult['output']))
                    echo $this->lastResult['output'];

                if( isset($this->lastResult['stats']) && self::$StatsCallback)
                    call_user_func(self::$StatsCallback, $this);

                //don't use isset, null may be there
                if(array_key_exists('result', $this->lastResult)){
                    return $this->lastResult['result'];
                }
                //maybe error then
            }
        }

        if( ! $ex && ! isset($this->lastResult['exception']) && ! isset($this->lastResult['resultError']) )
        {
            $ex = new JMRpcClientInternalException('bad rpc call result');
            $ex->responseText = $this->lastResponseText;
            $ex->result = $this->lastResult;
            if($showOutput)
                echo $this->lastResponseText;
        }
        else if(isset($this->lastResult['exception']))
        {
            $ex = JMRpcDelegatedException::FromRpcException($this->lastResult['exception']);
        }
        if(isset($ex))
        {
            $this->lastException = $ex;
        }
        $this->logRpcRequest();
        if( $ex )
        {
            $handleExceptionAs = ($this->handleExceptionAs === null) ? self::$Default_HandleExceptionAs : $this->handleExceptionAs;
            if($handleExceptionAs == self::HandleExceptionAs_Exception)
                throw $ex;
            else if($handleExceptionAs == self::HandleExceptionAs_Error)
            {
                $debugData = array('responseText'=>$this->lastResponseText);
                if(!empty($this->lastResult['output']))
                    $debugData['output'] = $this->lastResult['output'];
                $err = new JMRpcResultError($ex->getMessage(), -1, $debugData);
            }
            else if($handleExceptionAs == self::HandleExceptionAs_Null)
                return null;
            else  // no valid config here ....
                throw $ex;
        }

        if(isset($this->lastResult['resultError']))
        {
            $err = JMRpcResultError::FromRpcResultError($this->lastResult['resultError']);
        }

        if(function_exists('fb'))
            fb($err);
        return $err;
    }

    //rpc 监控
    private function rpcMonitor(){
        if(defined('SHOWMONITORS') && SHOWMONITORS){
            $showMonitors = JMRegistry::get('ShowMonitors');
            if(isset($showMonitors['Monitors'])
                    && isset($showMonitors['Monitors']['rpc']) 
                    && isset($showMonitors['Monitors']['rpc']['show']) 
                    && $showMonitors['Monitors']['rpc']['show']){
                if($this->lastResponseText){
                    $protocol = ($this->protocol === null) ? self::$Default_Protocol : $this->protocol;
                    if($protocol == self::Protocol_Json)
                        $temp = @json_decode($this->lastResponseText, true);
                    elseif($protocol == self::Protocol_Php)
                    $temp = @unserialize($this->lastResponseText);
                    else
                        $temp = $this->lastResponseText;
                }
                $classAndFunction = 'Class:'.$this->rpcClass.' Method:'.$this->currentMethodName;
                Utility_Monitors::rpcMonitorCllbackByStack($classAndFunction, $this->arguments, $temp );
            }
        }
    }
    
    static public function IsError($obj)
    {
        return $obj instanceof JMRpcResultError;
    }
    
    static public function IsErrorOrEmpty($obj)
    {
        return empty($obj) || ($obj instanceof JMRpcResultError);
    }

    public function _setShowOutput($v)
    {
        $this->showOutput = $v;
        return $this;
    }

    public function _setHandleExceptionAs($v)
    {
        $this->handleExceptionAs = $v;
        return $this;
    }

    public function _setProtocol($v)
    {
        $this->protocol = $v;
        return $this;
    }

    public function _setSessionId($v)
    {
        $this->sessionId = $v;
        return $this;
    }

    public function _setRpcOption($arg)
    {
        if(func_get_args() == 1)
        {
            foreach($arg as $k=>$v)
                $this->rpcOptions[$k] = $v;
        }
        else
        {
            $this->rpcOptions[$arg] = func_get_arg(1);
        }
        return $this;
    }

    public function _unsetRpcOption($arg)
    {
        unset($this->rpcOptions[$arg]);
    }

    public function _loadRpcOptions($options)
    {
        $this->rpcOptions = $options;
        return $this;
    }

    public function _resetRpcOptions()
    {
        $this->rpcOptions = null;
        return $this;
    }
    
    /**
     * @uses monolog
     * @param int $level
     */
    protected function logRpcRequest($level='100')
    {
        if(!function_exists('monolog'))
	{
	    return false;
	}
        $message = array(date('Ymd H:i:s O'),
                         $this->endpoint['User'],
                         $this->endpoint['Url'],
                         $this->rpcClass,
                         $this->currentMethodName,
                         str_replace(array("\n","\r\n","\r"),'',var_export($this->arguments,true))
                        );
        if(is_a($this->lastException, '\Exception'))
        {
            $message[] = $this->lastException->getMessage();
        }
        $message = implode('|',$message)."\n";
        monolog($level,'RpcClient', $message);
    }    
}
