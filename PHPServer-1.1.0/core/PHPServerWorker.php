<?php 

/**
 * Worker
 * @author liangl
 * 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * $socket = socket_client("protocol://ip:port");
 * $worker = new PHPServerWorker($socket);
 * $worker->setChannel(fopen('file', 'r'));
 * $worker->serve();
 * <code>
 * </pre>
 */
class PHPServerWorker
{
    // worker状态相关
    const STATUS_RUNNING = 2;
    const STATUS_SHUTDOWN = 4;
    
    // 逻辑超时异常返回码
    const CODE_PROCESS_TIMEOUT = 504;
    // udp最大包长 linux:65507 mac:9216
    const MAX_UDP_PACKEG_SIZE = 65507;
    // 停止服务后等待EXIT_WAIT_TIME秒后还没退出则强制退出
    const EXIT_WAIT_TIME = 5;
    // 强制退出状态码
    const FORCE_EXIT_CODE = 117;
    // 进程意外退出状态码
    const EXIT_UNEXPECT_CODE = 118;
    
    // server的协议
    protected $protocol = "tcp";
    // worker监听端口的Socket
    protected $mainSocket = null;
    // worker的所有链接
    protected $connections = array();
    // worker的所有读buffer
    protected $recvBuffers = array();
    
    // 当前处理的fd
    protected $currentDealFd = 0;
    // worker与master间通信通道
    protected $channel = null;
    // UDP当前处理的客户端地址
    protected $currentClientAddress = '';
    
    // worker的服务状态
    protected $workerStatus = 2;
    
    // 从socket读数据超时时间,单位毫秒
    protected $recvTimeOut = 1000;
    // 发送数据超时时间
    protected $sendTimeOut = 1000;
    // 逻辑处理超时时间
    protected $processTimeout = 300000;
    // 是否是长链接，(短连接每次请求后服务器主动断开，长连接一般是客户端主动断开)
    protected $isPersistentConnection = false;
    
    // 事件轮询库的名称
    protected $eventLoopName = 'Select';
    
    // 该worker进程处理多少请求后退出，0表示不自动退出
    protected $maxRequests = 0;
    
    // 服务名
    protected $serviceName = __CLASS__;
    
    // 协议 text or thrift
    protected $currentProtocol = 'text';
    
    // thrift子协议
    protected $subProtocol = 'Thrift\Protocol\TBinaryProtocol';
    
    // 鉴权数据
    protected static $auth = array();
    
    // 统计信息
    protected $statusInfo = array(
        'start_time'      => 0,
        'total_request'   => 0,
        'recv_timeout'    => 0,
        'proc_timeout'    => 0,
        'packet_err'      => 0,
        'throw_exception' => 0,
        'thunder_herd'    => 0,
        'client_close'    => 0,
        'send_fail'       => 0,
    );
    
    // 统计使用的数据
    public static $currentModule = '';
    
    public static $currentInterface = '';
    
    public static $currentRequestBuffer = '';
    
    public static $currentClientIp = '';
    
    /**
     * 构造函数
     * @param int $port
     * @param string $ip
     * @param string $protocol
     */
    public function __construct($socket = null, $recv_timeout = 1000, $process_timeout = 30000, $send_timeout = 1000, $persistent_connection = false, $max_requests = 0)
    {
        // 初始化
        $this->mainSocket = $socket;
        $this->recvTimeOut = $recv_timeout >= 0 ? $recv_timeout : $this->recvTimeOut;
        $this->processTimeout = $process_timeout > 0 ? $process_timeout : $this->processTimeout;
        $this->sendTimeOut = $send_timeout > 0 ? $send_timeout : $this->sendTimeOut;
        $this->isPersistentConnection = (bool)$persistent_connection;
        $this->maxRequests = $max_requests <= 0 ? PHP_INT_MAX : $max_requests;
        
        if($socket)
        {
            // 设置监听socket非阻塞
            stream_set_blocking($this->mainSocket, 0);
            // 获取协议
            $mata_data = stream_get_meta_data($socket);
            $this->protocol = substr($mata_data['stream_type'], 0, 3);
        }
        // worker启动时间
        $this->statusInfo['start_time'] = time();
        
        // 进程退出时增加状态判断
        register_shutdown_function(array($this, 'checkStatus'));
        
        // 初始化权限
        $this->initAuth();
    }
    
    
    /**
     * 让该worker实例开始服务
     *
     * @param bool $is_daemon 告诉 Worker 当前是否运行在 daemon 模式, 非 daemon 模式不拦截 signals
     * @return void
     */
    public function serve($is_daemon = true)
    {
        // 触发该worker进程onServe事件，该进程整个生命周期只触发一次
        if($this->onServe())
        {
            return;
        }

        // 安装信号处理函数
        if ($is_daemon === true) {
            $this->installSignal();
        }

        // 初始化事件轮询库
        // $this->event = new Libevent();
        // $this->event = new Libev();
        // $this->event = new Select();
        // $this->event = new Libuv();
        $this->event = new $this->eventLoopName();
        
        if($this->mainSocket)
        {
            if($this->protocol == 'udp')
            {
                // 添加读udp事件
                $this->event->add($this->mainSocket,  BaseEvent::EV_ACCEPT, array($this, 'recvUdp'));
            }
            else
            {
                // 添加accept事件
                $this->event->add($this->mainSocket,  BaseEvent::EV_ACCEPT, array($this, 'accept'));
            }
        }
        
        // 添加管道可读事件
        $this->event->add($this->channel,  BaseEvent::EV_READ, array($this, 'dealCmd'), null, 0, 0);
        
        // 主体循环
        $ret = $this->event->loop();
        $this->notice("evet->loop returned " . var_export($ret, true));
        
        exit(self::EXIT_UNEXPECT_CODE);
    }
    
    /**
     * 停止服务
     * @param bool $exit 是否退出
     * @return void
     */
    public function stopServe($exit = true)
    {
        // 触发该worker进程onStopServe事件
        if($this->onStopServe())
        {
            return;
        }
        
        // 标记这个worker开始停止服务
        if($this->workerStatus != self::STATUS_SHUTDOWN)
        {
            $this->workerStatus = self::STATUS_SHUTDOWN;
        }
        
        // 停止接收连接
        if($this->mainSocket)
        {
            $this->event->del($this->mainSocket, BaseEvent::EV_ACCEPT);
            @fclose($this->mainSocket);
        }
        
        // 当前任务都完成了
        if($this->allTaskHasDone())
        {
            if($exit)
            {
                exit(0);
            }
        }
    }
    
    
    /**
     * 设置master与worker间通信通道
     * @param resource $channel
     * @return void
     */
    public function setChannel($channel)
    {
        $this->channel = $channel;
        stream_set_blocking($this->channel, 0);
    }
    
    /**
     * 设置worker的事件轮询库的名称
     * @param string 
     * @return void
     */
    public function setEventLoopName($event_loop_name)
    {
        $this->eventLoopName = $event_loop_name;
    }
    
    /**
     * 接受一个链接
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function accept($socket, $null_one = null, $null_two = null)
    {
        // 获得一个连接
        $new_connection = @stream_socket_accept($socket, 0);
        // 可能是惊群效应
        if(false === $new_connection)
        {
            $this->statusInfo['thunder_herd']++;
            return false;
        }
        
        // 接受请求数加1
        $this->statusInfo['total_request'] ++;
        
        // 连接的fd序号
        $fd = (int) $new_connection;
        $this->connections[$fd] = $new_connection;
        // 非阻塞
        stream_set_blocking($this->connections[$fd], 0);
        $this->event->add($this->connections[$fd], BaseEvent::EV_READ , array($this, 'dealInputBase'), $fd, $this->recvTimeOut);
    }
    
    /**
     * 接收Udp数据 
     * 如果数据超过一个udp包长，需要业务自己解析包体，判断数据是否全部到达
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function recvUdp($socket, $null_one = null, $null_two = null)
    {
         $data = stream_socket_recvfrom($socket , self::MAX_UDP_PACKEG_SIZE, 0, $address);
         // 可能是惊群效应
         if(false === $data || empty($address))
         {
             $this->statusInfo['thunder_herd']++;
             return false;
         }
         
         // 接受请求数加1
         $this->statusInfo['total_request'] ++;
         
         $this->currentClientAddress = $address;
         if(0 === $this->dealInput($data))
         {
             $this->dealProcess($data);
         }
    }
    
    /**
     * 处理master发过来的命令
     * @param resource $socket
     * @param int $flag
     */
    public function dealCmd($channel, $length, $buffer)
    {
        // 主进程挂了，完蛋了
        if($length == 0)
        {
            $this->event->delAll($this->channel);
            $this->notice("!!!!!!!!!!!!!!!!!!!!Master has gone !!!!!!!!!!!!!!!!!");
            $this->onMasterDead();
            return false;
        }
        // master发过来的命令字只有一个字节
        $cmd = Cmd::decodeForWorker($buffer);
        // 判断是哪个命令字
        switch($cmd)
        {
            // 测试
            case Cmd::CMD_TEST:
                $this->reportToMaster($cmd, 'test ok');
                break;
            // 获取该worker进程包含的文件
            case Cmd::CMD_REPORT_INCLUDE_FILE:
                $files = get_included_files();
                $this->reportToMaster($cmd, $files);
                $this->onAlarm();
                break;
            // 命令该worker停止服务
            case Cmd::CMD_STOP_SERVE:
                $this->reportToMaster($cmd, 1);
                $this->stopServe();
                break;
            // 命令重启服务
            case Cmd::CMD_RESTART:
                $this->reportToMaster($cmd, 1);
                $this->stopServe();
                break;
            // 命令关闭通信管道
            case Cmd::CMD_CLOSE_CHANNEL:
                break;
            // 命令上报worker状态信息给master
            case Cmd::CMD_REPORT_STATUS_FOR_MASTER:
                $this->reportToMaster($cmd, array_merge($this->statusInfo, array('memory'=>memory_get_usage(true))));
                break;
            // 上报worker状态信息给FileMonitor
            case Cmd::CMD_REPORT_WORKER_STATUS:
                Reporter::reportWorkerStatus(array_merge($this->statusInfo, array('memory'=>memory_get_usage(true), 'worker_name'=>get_class($this))));
                break;
            case Cmd::CMD_PING:
                $this->reportToMaster(Cmd::CMD_PONG, '');
                $this->onAlarm();
                break;
            // 未知命令
            default :
                $this->reportToMaster(Cmd::CMD_UNKNOW, 'CMD UNKONW!!');
        }
    }
    
    /**
     * 向master发送对应命令字的结果
     * 协议如下char<cmd>/unsigned int<pack_len>/string<serialize(result)>
     * @param char $cmd
     * @param mix $result
     * @return false/int
     */
    protected function reportToMaster($cmd, $result)
    {
        $pack_data = Cmd::encodeForWorker($cmd, $result);
        return $this->blockWrite($this->channel, $pack_data);
    }
    
    /**
     * 处理受到的数据
     * @param event_buffer $event_buffer
     * @param int $fd
     * @return void
     */
    public function dealInputBase($connection, $length, $buffer, $fd = null)
    {
        $this->currentDealFd = $fd;
        
        // 出错了
        if($length == 0)
        {
            if(feof($connection))
            {
                // 客户端提前断开链接
                $this->statusInfo['client_close']++;
            }
            else
            {
                // 超时了
                $this->statusInfo['recv_timeout']++;
            }
            $this->closeClient($fd);
            if($this->workerStatus == self::STATUS_SHUTDOWN)
            {
                $this->stopServe();
            }
            return;
        }
        
        if(isset($this->recvBuffers[$fd]))
        {
            $buffer = $this->recvBuffers[$fd] . $buffer;
        }
        
        $remain_len = $this->dealInput($buffer);
        // 包接收完毕
        if(0 === $remain_len)
        {
            // 逻辑超时处理，逻辑只能执行xxs,xxs后超时放弃当前请求处理下一个请求
            pcntl_alarm(ceil($this->processTimeout/1000));
            
            // 执行处理
            try{
                declare(ticks=1);
                // 业务处理
                $this->dealProcess($buffer);
                // 关闭闹钟
                pcntl_alarm(0);
            }
            catch(Exception $e)
            {
                // 关闭闹钟
                pcntl_alarm(0);
                if($e->getCode() != self::CODE_PROCESS_TIMEOUT)
                {
                    $this->notice($e->getMessage().":\n".$e->getTraceAsString());
                    $this->statusInfo['throw_exception'] ++;
                    $this->sendToClient($e->getMessage());
                }
            }
            
            // 是否是长连接
            if($this->isPersistentConnection)
            {
                // 清空缓冲buffer
                unset($this->recvBuffers[$fd]);
            }
            else
            {
                // 关闭链接
                $this->closeClient($fd);
            }
            
        }
        // 出错
        else if(false === $remain_len)
        {
            // 出错
            $this->statusInfo['packet_err']++;
            $this->sendToClient('packet_err:'.$buffer);
            $this->notice('packet_err:'.$buffer);
            $this->closeClient($fd);
        }
        // 还有数据没收完，则保存收到的数据，等待其它数据
        else
        {
            $this->recvBuffers[$fd] = $buffer;
        }

        // 检查是否到达请求上限或者服务是否是关闭状态
        if($this->statusInfo['total_request'] >= $this->maxRequests || $this->workerStatus == self::STATUS_SHUTDOWN)
        {
            // 停止服务
            $this->stopServe();
            // 5秒后退出进程
            pcntl_alarm(self::EXIT_WAIT_TIME);
        }
    }
    
    /**
     * 根据fd关闭链接
     * @param int $fd
     * @return void
     */
    protected function closeClient($fd)
    {
        // udp忽略
        if($this->protocol != 'udp')
        {
            $this->event->delAll($this->connections[$fd]);
            fclose($this->connections[$fd]);
            
            unset($this->connections[$fd], $this->recvBuffers[$fd]);
        }
    }
    
    /**
     * 安装信号处理函数
     * @return void
     */
    protected function installSignal()
    {
        pcntl_signal(SIGALRM, array($this, 'signalHandler'), false);
        // 设置忽略信号
        pcntl_signal(SIGHUP,  SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTERM, SIG_IGN);
        pcntl_signal(SIGUSR1, SIG_IGN);
        pcntl_signal(SIGUSR2, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }
    
    /**
     * 设置server信号处理函数
     * @param null $null
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        switch($signal)
        {
            // 时钟处理函数
            case SIGALRM:
                // 停止服务后EXIT_WAIT_TIME秒还没退出则强制退出
                if($this->workerStatus == self::STATUS_SHUTDOWN)
                {
                    exit(self::FORCE_EXIT_CODE);
                }
                if(self::$currentModule)
                {
	                // 处理逻辑超时
	                $this->statusInfo['proc_timeout']++;
	                $e = new Exception("process_timeout", 504);
	                $this->notice($e->getMessage().":\n".$e->getTraceAsString());
	                throw $e;
                }
                break;
        }
    }
    
    /**
     * 用户worker继承此worker类必须实现该方法，根据具体协议和当前收到的数据决定是否继续收包
     * @param string $recv_str 收到的数据包
     * @return int/false 返回0表示接收完毕/>0表示还有多少字节没有接收到/false出错
     */
    public function dealInput($recv_str)
    {
        return 0;
    }
    
    /**
     * 用户worker继承此worker类必须实现该方法，根据包中的数据处理逻辑
     * 逻辑处理
     * @param string $recv_str 收到的数据包
     * @return void
     */
    public function dealProcess($recv_str)
    {
        
    }
    
    /**
     * 发送数据到客户端
     * @return bool
     */
    public function sendToClient($str_to_send)
    {
        // udp 直接发送，要求数据包不能超过65515
        if($this->protocol == 'udp')
        {
             $len = stream_socket_sendto($this->mainSocket, $str_to_send, 0, $this->currentClientAddress);
             return $len == strlen($str_to_send);
        }

        // tcp 如果一次没写完（一般是缓冲区满的情况），则阻塞写
        if(!$this->blockWrite($this->connections[$this->currentDealFd], $str_to_send, $this->sendTimeOut))
        {
            $this->notice('sendToClient fail ,Data length = ' . strlen($str_to_send));
            $this->statusInfo['send_fail']++;
        }
        return true;
    }
    
    /**
     * 向fd写数据，如果socket缓冲区满了，则改用阻塞模式写数据
     * @param resource $fd
     * @param string $str_to_write
     * @param int $time_out 单位毫秒
     */
    protected function blockWrite($fd, $str_to_write, $timeout_ms = 500)
    {
        $send_len = @fwrite($fd, $str_to_write);
        if($send_len == strlen($str_to_write))
        {
            return true;
        }
        
        // 客户端关闭
        if(feof($fd))
        {
            $this->notice("blockWrite client close");
            return false;
        }
        
        // 设置阻塞
        stream_set_blocking($fd, 1);
        // 设置超时
        $timeout_sec = floor($timeout_ms/1000);
        $timeout_ms = $timeout_ms%1000;
        stream_set_timeout($fd, $timeout_sec, $timeout_ms*1000);
        $send_len += @fwrite($fd, substr($str_to_write, $send_len));
        // 改回非阻塞
        stream_set_blocking($fd, 0);
        
        return $send_len == strlen($str_to_write);
    }
    
    /**
     * 获取客户端ip
     * @return string
     */
    public function getRemoteIp()
    {
        $ip = '';
        if($this->protocol == 'udp')
        {
            $sock_name = $this->currentClientAddress;
        }
        else
        {
            $sock_name = stream_socket_get_name($this->connections[$this->currentDealFd], true);
        }
        
        if($sock_name)
        {
            $tmp = explode(':', $sock_name);
            $ip = $tmp[0];
        }
        
        return $ip;
    }
    
    /**
     * 获取本地ip
     * @return string 
     */
    public function getLocalIp()
    {
        $ip = '';
        $sock_name = '';
        if($this->mainSocket)
        {
            if($this->protocol == 'udp' || !isset($this->connections[$this->currentDealFd]))
            {
                $sock_name = stream_socket_get_name($this->mainSocket, false);
            }
            else
            {
                $sock_name = stream_socket_get_name($this->connections[$this->currentDealFd], false);
            }
        }
        
        if($sock_name)
        {
            $tmp = explode(':', $sock_name);
            $ip = $tmp[0];
        }
        else
        {
            $ip = gethostbyname(trim(`hostname`));
        }
        
        return $ip;
    }
    
    /**
     * 该进程收到的任务是否都已经完成，重启进程时需要判断
     * @return bool
     */
    protected function allTaskHasDone()
    {
        return empty($this->connections);
    }
    
    
    /**
     * 该worker进程开始服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onServe()
    {
        return false;
    }
    
    /**
     * 该worker进程停止服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onStopServe()
    {
        return false;
    }
    
    /**
     * 每隔一段时间(5s)会触发该方法，用于触发worker某些流程
     * @return bool
     */
    protected function onAlarm()
    {
        return false;
    }
    
    /**
     * master进程挂掉会触发该方法
     * @return void
     */
    protected function onMasterDead()
    {
        
    }
    
    /**
     * 检查错误
     * @return void
     */
    public function checkStatus()
    {
        if(self::STATUS_SHUTDOWN != $this->workerStatus)
        {
            $error_msg = "WORKER EXIT UNEXPECTED  ";
            if($errors = error_get_last())
            {
                $error_msg .= $errors['type'] . " {$errors['message']} in {$errors['file']} on line {$errors['line']}";
            }
            $this->notice($error_msg);
            if(E_ERROR == $errors['type'] || E_PARSE == $errors['type'] || E_CORE_ERROR == $errors['type'] || E_COMPILE_ERROR == $errors['type'])
            {
            	if($this->currentProtocol == 'thrift')
            	{
            		$this->writeExceptionToClient(self::$currentInterface, "{$errors['message']} in {$errors['file']} on line {$errors['line']}", $this->subProtocol);
            	}
            	else
            	{
            		if ($errors = error_get_last()) {
            			$ctx = array(
            					'exception' => array(
            							'class' => 'ServiceFatalException',
            							'message' => $errors['message'],
            							'file' => $errors['file'],
            							'line' => $errors['line'],
            					),
            			);
            		}
            		else 
            		{
	            		$ctx = array(
			                'exception' => array(
			                    'class' => 'Exception',
			                    'message' => $error_msg,
			                    'code' => 500,
			                    'file' => ' ',
			                    'line' => ' ',
			                    'traceAsString' => ' ',
			                )
			            );
            		}
            		$this->send($ctx);
            	}
                if(self::$currentModule)
                {
                    StatisticClient::report(self::$currentModule, self::$currentInterface, 500, $error_msg."\nREQUEST_DATA:[".self::$currentRequestBuffer."]\n", 0, self::$currentClientIp);
                }
                else
                {
                    StatisticClient::report($this->serviceName, $this->serviceName, 500, $error_msg."\nREQUEST_DATA:[no_request_data]\n", 0, self::$currentClientIp);
                }
            }
        }
    }
    
    /**
     * 设置服务名
     * @param string $service_name
     * @return void
     */
    public function setServiceName($service_name)
    {
        $this->serviceName = $service_name;
    }
    
    /**
     * 初始化权限
     */
    protected function initAuth()
    {
    	if(is_file(SERVER_BASE.'/config/auth.php'))
    	{
    		$auth_tmp = include SERVER_BASE.'/config/auth.php';
    		if($auth_tmp)
    		{
    			foreach($auth_tmp as $key => $ip_list)
    			{
    				$ip_list = array_unique($ip_list);
    				if($ip_list)
    				{
    					$ip_list = array_combine($ip_list, $ip_list);
    				}
                    $service_class_method = explode('.', $key);
                    $service = $service_class_method[0];
                    if(!isset(self::$auth[$service]))
                    {
                    	self::$auth[$service] = array();
                    }
                    if(!isset($service_class_method[1]))
                    {
                    	self::$auth[$service]['ip-list'] = $ip_list;
                    }
                    else
                    {
                    	$class = $service_class_method[1];
                    	if(!isset($service_class_method[2]))
                    	{
                    		self::$auth[$service][$class] = array('ip-list'=>$ip_list);
                    	}
                    	else 
                    	{
                    		$method = $service_class_method[2];
                    		self::$auth[$service][$class][$method] = array('ip-list'=>$ip_list);
                    	}
                    }
    			}
    		}
    	}
    }
    
    /**
     * 根据服务、类、方法检查ip是否有权限访问
     * @param string $service
     * @param string $class
     * @param string $method
     * @param string $ip
     * @return bool
     */
    public static function hasAuth($service, $class, $method, $ip)
    {
    	if(isset(self::$auth[$service][$class][$method]['ip-list']))
    	{
    		if(isset(self::$auth[$service][$class][$method]['ip-list'][$ip]))
    		{
    			return true;
    		}
    		return false;
    	}
    	if(isset(self::$auth[$service][$class]['ip-list']))
    	{
    		if(isset(self::$auth[$service][$class]['ip-list'][$ip]))
    		{
    			return true;
    		}
    		return false;
    	}
    	if(isset(self::$auth[$service]['ip-list']))
    	{
    		if(isset(self::$auth[$service]['ip-list'][$ip]))
    		{
    			return true;
    		}
    		return false;
    	}
    	return true;
    }
    
    /**
     * debug
     * @param sring $str
     */
    protected function notice($str)
    {
        ServerLog::add('['.get_class($this). '] ' .$str);
    }
    
}



