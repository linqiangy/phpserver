<?php 
error_reporting(E_ALL);
ini_set('display_errors', 'on');
ini_set('limit_memory','256M');
date_default_timezone_set('Asia/Shanghai');

define('SERVER_BASE', realpath(__dir__ . '/..') . '/');

include SERVER_BASE . 'core/events/interfaces.php';
include SERVER_BASE . 'protocols/interfaces.php';
if(is_file(SERVER_BASE . 'workers/interfaces.php'))
{
    include SERVER_BASE . 'workers/interfaces.php';
}

/**
 * 
 * 版本：1.1.8
 * 发布日期：2014-12-23
 * 特性更新：
 * 2014-12-23 禁用libevent；支持客户端来源统计；告警服务；支持鉴权；支持HTTP文件上传（HttpFileReceiver）
 * 2014-11-20 backlog默认10240
 * 2014-10-14 FatalError通过异常机制返回给前端
 * 2014-09-23 JMTextWorker、ThriftWorker支持setRequestInfo;支持FatalErr在监控中查看
 * 2014-07-28 启动过程中向终端输出判断终端是否关闭
 * 2014-07-17 trace_log $ctx 是对象时报FatalError bug fix
 * 2014-07-15 去掉老的mnlogger埋点日志 上下文传appname  RPC_SS、HTTP_SERVICE_SS增加参数
 * 2014-07-13 支持 mnlogger app_name配置以及是否开启mnlogger配置
 * 2014-07-08 支持owl_context上下文,支持日志追踪、异常记录日志,JMTextWorker、ThriftWorker加入RpcBusinessException
 * 2014-06-06 Thrift支持Thrift、文本Text双协议
 * 2014-05-12 $killWorkerTimeLong 改回到原来的4秒
 * 
 * PHPServer
 * 只支持linux PHP需要安装posix pcntl模块
 * 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * PHPServer::init();
 * PHPServer::run();
 * <code>
 * </pre>
 * @author liangl
 *
 */
class PHPServer
{
    // 支持的协议
    const PROTOCOL_TCP = 'tcp';
    const PROTOCOL_UDP = 'udp';
    
    // 服务的各种状态
    const STATUS_STARTING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_SHUTDOWN = 4;
    const STATUS_RESTARTING_WORKERS = 8;
    
    // 配置相关
    const SERVER_MAX_WORKER_COUNT = 1000;
    // 某个进程内存达到这个值时安全退出该进程 单位K
    const MAX_MEM_LIMIT = 83886;
    // 单个进程打开文件数限制
    const MIN_SOFT_OPEN_FILES = 10000;
    const MIN_HARD_OPEN_FILES = 10000;
    // worker从客户端接收数据超时默认时间 毫秒
    const WORKER_DEFAULT_RECV_TIMEOUT = 1000;
    // worker业务逻辑处理默认超时时间 毫秒
    const WORKER_DEFAULT_PROCESS_TIMEOUT = 30000;
    // worker发送数据到客户端默认超时时间 毫秒
    const WORKER_DEFAULT_SEND_TIMEOUT = 1000;
    // 心跳包ping没回复多少次则停止对应进程
    const PONG_MISS_LIMIT = 12;
    
    // 所有子进程pid
    protected static $workerPids = array();

    // server的状态
    protected static $serverStatus = 1;
    
    // server监听端口的Socket数组，用来fork worker使用
    protected static $listenedSockets = array();
    
    // 要监控的文件数组
    protected static $filesToInotify = array();
    
    // worker与master间通信通道
    protected static $channels = array();
    
    // 要重启的worker pid array('pid'=>'time_stamp', 'pid2'=>'time_stamp')
    protected static $workerToRestart = array();
    
    // 发送ping及接收pong的情况[pid=>x, pid=>x,..] ping的时候x+1 ，收到pong的时候x-1， x一直大于1则说明对应进程卡住了
    protected static $pingInfo = array();
    
    // 使用事件轮询库的名称
    protected static $eventLoopName = 'Select';
    // 事件轮询库实例
    protected static $event = null;
    
    // 发送停止命令多久后worker没退出则发送kill信号
    protected static $killWorkerTimeLong = 4;
    // 多久发送一次文件检测命令
    protected static $commonWaitTimeLong = 5;
    // 多久检查一次worker状态，目前只检查内存,进程是否卡死
    protected static $checkStatusTimeLong = 60;
    // 多久检查一次文件更新
    protected static $checkFilesTimeLong = 1;
    
    // 运行worker进程所用的用户名
    protected static $workerUserName = '';
    // worker_name最大长度
    protected static $maxWorkerNameLength = 1;
    // 是否已经探测到终端关闭
    protected static $terminalHasClosed = false;
    
    
    // server统计信息
    protected static $serverStatusInfo = array(
        'start_time' => 0,
        'err_info'=>array(),
    );
    
    /**
     * 构造函数
     * @param string $config_path
     * @return void
     */
    public static function init()
    {
        // 获取配置文件
        $config_path = PHPServerConfig::instance()->filename;
        
        // 设置进程名称，如果支持的话
        self::setProcessTitle('PHPServer:master with-config:' . $config_path);
    }

    /**
     * 启动server，以指定的用户名运行worker
     * @return void
     */
    public static function run($worker_user_name = '')
    {
        self::notice("Server is starting ...", true);
        
        // 指定运行worker进程的用户名
        self::$workerUserName = trim($worker_user_name);
        
        // 标记server状态为启动中...
        self::$serverStatus = self::STATUS_STARTING;
        
        // 检查Server环境
        self::checkEnv();
        
        // 使之成为daemon进程
        self::daemonize();
        
        // master进程使用Select轮询
        self::$event = new Select();

        // 安装相关信号
        self::installSignal();
        
        // 创建监听进程
        self::createSocketsAndListen();
        
        // 创建workers，woker阻塞在这个方法上
        self::createWorkers();
        
        // 创建文件监控的fd，如果支持的话
        self::initInotify();
        
        // 初始化任务
        self::initTask();
        
        self::notice("Server start success ...", true);
        
        // 标记sever状态为运行中...
        self::$serverStatus = self::STATUS_RUNNING;
        
        // 非开发环境关闭标准输出
        self::resetStdFd();
        
        // 监控worker进程状态，worker执行master的命令的结果，监控文件更改
        self::loop();
        
        // 标记sever状态为关闭中...
        self::$serverStatus = self::STATUS_SHUTDOWN;

        return self::stop();
    }
    
    /**
     * Server进程 主体循环
     * @return void
     */
    protected static function loop()
    {
        // 事件轮询
        self::$event->loop();
    }
    
    /**
     * 停止server
     * @return void
     */
    public static function stop()
    {
        // 向所有telnet客户端发送提示
        Telnet::sendToAllClient("Server Is Shuting Down. \n");
        
        // 标记server开始关闭
        self::$serverStatus = self::STATUS_SHUTDOWN;
        
        // 停止所有worker
        self::stopAllWorker(true);
        
        // 如果没有子进程则直接退出
        $all_worker_pid = self::getPidWorkerNameMap();
        if(empty($all_worker_pid))
        {
            exit(0);
        }
        
        // killWorkerTimeLong 秒后如果还没停止则强制杀死所有进程
        Task::add(PHPServer::$killWorkerTimeLong, array('PHPServer', 'stopAllWorker'), array(true), false);
        
        // 停止所有worker
        self::stopAllWorker(true);
        
        return ; 
    }
    
    /**
     * 使之脱离终端，变为守护进程
     * @return void 
     */
    protected static function daemonize()
    {
        // 设置umask
        umask(0);
        // fork一次
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            // 出错退出
            exit("Daemonize fail ,can not fork");
        }
        elseif($pid > 0)
        {
            // 父进程，退出
            exit(0);
        }
        // 子进程使之成为session leader
        if(-1 == posix_setsid())
        {
            // 出错退出
            exit("Daemonize fail ,setsid fail");
        }
        
        // 再fork一次
        $pid2 = pcntl_fork();
        if(-1 == $pid2)
        {
            // 出错退出
            exit("Daemonize fail ,can not fork");
        }
        elseif(0 !== $pid2)
        {
            // 结束第一子进程，用来禁止进程重新打开控制终端
            exit(0);
        }
        
        // 保存master进程pid到当前目录，用于实现停止、重启
        file_put_contents(PID_FILE, posix_getpid());
        chmod(PID_FILE, 0644);
        
        // 记录server启动时间
        self::$serverStatusInfo['start_time'] = time();
    }
    
    /**
     * 创建监听套接字
     * @return void
     */
    protected static function createSocketsAndListen()
    {
    	// backlog
    	$backlog = (int)PHPServerConfig::get('backlog');
    	if($backlog <= 0)
    	{
    		$backlog = 10240;
    	}
    	$opts = array(
    			'socket' => array(
    					'backlog' => $backlog,
    			),
    	);
    	// 上下文
    	$context = stream_context_create($opts);
    	
        // 循环读取配置创建socket
        foreach (PHPServerConfig::get('workers') as $worker_name=>$config)
        {
            if(!isset($config['protocol']) || !isset($config['port']))
            {
                continue;
            }
            $flags = $config['protocol'] == 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $ip = isset($config['ip']) ? $config['ip'] : "0.0.0.0";
            $error_no = 0;
            $error_msg = '';
            // 创建监听socket
            self::$listenedSockets[$worker_name] = stream_socket_server("{$config['protocol']}://{$ip}:{$config['port']}", $error_no, $error_msg, $flags, $context);
            if(!self::$listenedSockets[$worker_name])
            {
                ServerLog::add("can not create socket {$config['protocol']}://{$ip}:{$config['port']} info:{$error_no} {$error_msg}\tServer start fail");
                exit("\n\033[31;40mcan not create socket {$config['protocol']}://{$ip}:{$config['port']} info:{$error_no} {$error_msg}\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
            }
        }
        
        // 创建telnet监听socket
        Telnet::bindAndListen('0.0.0.0', 10101, self::$event);
    }
    
    /**
     * 创建Workers
     * @return void
     */
    protected static function createWorkers()
    {
        // 循环读取配置创建一定量的worker进程
        foreach (PHPServerConfig::get('workers') as $worker_name=>$config)
        {
            // 初始化
            if(empty(self::$workerPids[$worker_name]))
            {
                self::$workerPids[$worker_name] = array();
            }
            
            while(count(self::$workerPids[$worker_name]) < $config['child_count'])
            {
                $pid = self::forkOneWorker($worker_name);
                // 子进程退出
                if($pid == 0)
                {
                    exit("child exist err");
                }
            }
        }
    }
    
    /**
     * 复制一个worker进程
     * @param string $worker_name worker的名称
     * @return 父进程:>0得到新worker的pid ;<0 出错; 子进程:始终为0
     */
    protected static function forkOneWorker($worker_name)
    {
        // 建立进程间通信通道，目前是用unix域套接字
        if(!($channel = self::createChannel()))
        {
            self::notice("Create channel fail\n");
        }
        // 触发alarm信号处理
        pcntl_signal_dispatch();
        
        // 创建子进程
        $pid = pcntl_fork();
        // 父进程
        if($pid > 0)
        {
            // 初始化master的一些东东
            fclose($channel[1]);
            self::$workerPids[$worker_name][$pid] = $pid;
            self::$channels[$pid] = $channel[0];
            unset($channel);
            self::$event->add(self::$channels[$pid], BaseEvent::EV_READ, array('PHPServer', 'dealCmdResult'), $pid, 0, 0);
            return $pid;
        }
        // 子进程
        elseif($pid === 0)
        {
            // 屏蔽alarm信号
            self::ignoreSignalAlarm();
            
            // 子进程关闭telnet监听
            Telnet::close();
            
            // 子进程关闭不用的监听socket
            foreach(self::$listenedSockets as $tmp_worker_name => $tmp_socket)
            {
                if($tmp_worker_name != $worker_name)
                {
                    fclose($tmp_socket);
                }
            }
            
            // 关闭用不到的管道
            fclose($channel[0]);
            foreach(self::$channels as $ch)
            {
                self::$event->delAll((int)$ch);
                fclose($ch);
            }
            self::$channels = array();
            
            // 尝试以指定用户运行worker
            self::setWorkerUser();
            
            // 删除任务
            Task::delAll();
            
            // 开发环境打开标准输出，用于调试
            if(PHPServerConfig::get('ENV') == 'dev')
            {
                self::recoverStdFd();
            }
            else 
            {
                self::resetStdFd();
            }
            
            // 获得socket的信息
            if(isset(self::$listenedSockets[$worker_name]))
            {
                $sock_name = stream_socket_get_name(self::$listenedSockets[$worker_name], false);
                
                // 更改进程名，如果支持的话
                $mata_data = stream_get_meta_data(self::$listenedSockets[$worker_name]);
                $protocol = substr($mata_data['stream_type'], 0, 3);
                self::setProcessTitle("PHPServer:worker $worker_name ".self::$eventLoopName." {$protocol}://$sock_name");
            }
            else
            {
                self::setProcessTitle("PHPServer:worker $worker_name ");
            }
            
            // 检查语法错误
            if(0 != self::checkSyntaxError($worker_name))
            {
                self::notice("$worker_name has Fatal Err\n");
                sleep(5);
                exit(120);
            }
            
            // 获取超时时间
            $recv_timeout = PHPServerConfig::get('workers.' . $worker_name . '.recv_timeout');
            if($recv_timeout === null || (int)$recv_timeout < 0)
            {
                $recv_timeout = self::WORKER_DEFAULT_RECV_TIMEOUT;
            }
            $process_timeout = PHPServerConfig::get('workers.' . $worker_name . '.process_timeout');
            $process_timeout = (int)$process_timeout > 0 ? (int)$process_timeout : self::WORKER_DEFAULT_PROCESS_TIMEOUT;
            $send_timeout = PHPServerConfig::get('workers.' . $worker_name . '.send_timeout');
            $send_timeout = (int)$send_timeout > 0 ? (int)$send_timeout : self::WORKER_DEFAULT_SEND_TIMEOUT;
            // 是否开启长连接
            $persistent_connection = (bool)PHPServerConfig::get('workers.' . $worker_name . '.persistent_connection');
            $max_requests = (int)PHPServerConfig::get('workers.' . $worker_name . '.max_requests');
            
            // 类名
            $class_name = PHPServerConfig::get('workers.' . $worker_name . '.worker_class');
            $class_name = $class_name ? $class_name : $worker_name;
            
            // 创建worker实例
            $worker = new $class_name(isset(self::$listenedSockets[$worker_name]) ? self::$listenedSockets[$worker_name] : null, $recv_timeout, $process_timeout, $send_timeout, $persistent_connection, $max_requests);
            // 设置服务名
            $worker->setServiceName($worker_name);
            // 设置通讯通道，worker读写channel[1]
            $worker->setChannel($channel[1]);
            // 设置worker事件轮询库的名称
            $worker->setEventLoopName(self::$eventLoopName);
            // 使worker开始服务
            $worker->serve();
            return 0;
        }
        // 出错
        else 
        {
            self::notice("create worker fail worker_name:$worker_name detail:pcntl_fork fail");
            return $pid;
        }
    }
    
    /**
     * 创建master与worker之间的通信通道
     * @return array
     */
    protected static function createChannel()
    {
        // 建立进程间通信通道，目前是用unix域套接字
        $channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if(false === $channel)
        {
            return false;
        }
        stream_set_blocking($channel[0], 0);
        stream_set_blocking($channel[1], 0);
        return $channel;
    }
    
    /**
     * 设置运行用户
     * @return void
     */
    protected static function setWorkerUser()
    {
        if(!empty(self::$workerUserName))
        {
            $user_info = posix_getpwnam(self::$workerUserName);
            // 尝试设置gid uid
            if(!posix_setgid($user_info['gid']) || !posix_setuid($user_info['uid']))
            {
                $notice = 'Notice : Can not run woker as '.self::$workerUserName." , You shuld be root\n";
                self::notice($notice);
            }
        }
    }
    
    /**
     * 处理命令结果
     * @param resource $channel
     * @param int $length
     * @param string $buffer
     * @param int $pid
     */
    public static function dealCmdResult($channel, $length, $buffer, $pid)
    {
        // 链接断开了，应该是对应的进程退出了
        if($length == 0)
        {
            return self::monitorWorkers();
        }
        // 处理命令
        $ret = Cmd::getCmdResult($channel, $length, $buffer, $pid);
        if($ret)
        {
            self::dealCmd($ret['cmd'], $ret['result'], $ret['pid']);
        }
    }
    
    /**
     * 初始化inotify
     * @return void
     */
    protected static function initInotify()
    {
        Inotify::init();
        if(!Inotify::isSuport())
        {
            return null;
        } 
        self::$event->add(Inotify::getFd(), BaseEvent::EV_NOINOTIFY, array('PHPServer', 'dealFileModify'));
        self::sendCmdToAll(Cmd::CMD_REPORT_INCLUDE_FILE);
    }
    
    /**
     * 文件更改监控
     * @param resource $inotify_fd
     * @param int $flag
     * @return boolean
     */
    public static function dealFileModify($inotify_fd, $flag)
    {
        // 获取更新的文件
        $files_to_reload = Inotify::getModifiedFiles();
        
        // 获得文件与worker的映射关系
        $file_worker_name_map = self::getFilesWorkerNameMap();
        
        // 遍历要重新载入的文件
        $need_reload = false;
        foreach ($files_to_reload as $file)
        {
            if(!isset($file_worker_name_map[$file]))
            {
                self::notice('$file_worker_name_map[$file] not exist file:'.$file);
                continue;
            }
            // 遍历文件
            foreach($file_worker_name_map[$file] as $worker_name)
            {
                if(!isset(self::$workerPids[$worker_name]))
                {
                    self::notice("\self::$workerPids[$worker_name] empty");
                    return false;
                }
                self::notice(" file $file updated");
                // 获得每个worker的pid，放入重启队列workerToRestart
                self::addToRestartWorkers(self::$workerPids[$worker_name]);
                $need_reload = true;
            }
        }
        
        // 需要重启
        if($need_reload)
        {
            // 标记server在进程重启状态
            self::restartWorkers();
            Telnet::sendToAllClient("File Updated And Restart Workers ");
        }
    }
    
    /**
     * 放入重启队列中
     * @param array $restart_pids
     * @return void
     */
    public static function addToRestartWorkers($restart_pids)
    {
        if(!is_array($restart_pids))
        {
            return false;
        }
        
        // 将pid放入重启队列
        foreach($restart_pids as $pid)
        {
            if(!isset(self::$workerToRestart[$pid]))
            {
                // 重启时间=0
                self::$workerToRestart[$pid] = 0;
            }
        }
    }
    
    /**
     * 重启workers
     * @return void
     */
    public static function restartWorkers()
    {
        // 标记server状态
        if(self::$serverStatus != self::STATUS_RESTARTING_WORKERS)
        {
            self::$serverStatus = self::STATUS_RESTARTING_WORKERS;
        }
        
        // 没有要重启的进程了
        if(empty(self::$workerToRestart))
        {
            self::$serverStatus = self::STATUS_RUNNING;
            self::notice("\nWorker Restart Success");
            return true;
        }
        
        // 遍历要重启的进程 标记它们重启时间
        foreach(self::$workerToRestart as $pid => $stop_time)
        {
            if($stop_time == 0)
            {
                self::$workerToRestart[$pid] = time();
                self::sendCmdToWorker(Cmd::CMD_RESTART, $pid);
                Task::add(PHPServer::$killWorkerTimeLong, array('PHPServer', 'forceKillWorker'), array($pid), false);
                break;
            }
        }
    }
    
    /**
     * 监控worker进程状态，退出重启
     * @param resource $channel
     * @param int $flag
     * @param int $pid 退出的进程id
     */
    public static function monitorWorkers($wait_pid = -1)
    {
        // 由于SIGCHLD信号可能重叠导致信号丢失，所以这里要循环获取所有退出的进程id
        while(($pid = pcntl_waitpid($wait_pid, $status, WUNTRACED | WNOHANG)) != 0)
        {
            // 如果是重启的进程，则继续重启进程
            if(isset(self::$workerToRestart[$pid]) && self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                Telnet::sendToAllClient(".");
                unset(self::$workerToRestart[$pid]);
                self::restartWorkers();
            }
            
            // 出错
            if($pid == -1)
            {
                // 没有子进程了,可能是出现Fatal Err 了
                if(pcntl_get_last_error() == 10)
                {
                    self::notice('Server has no workers now');
                }
                return -1;
            }
            
            // 查找子进程对应的woker_name
            $pid_workname_map = self::getPidWorkerNameMap();
            $worker_name = isset($pid_workname_map[$pid]) ? $pid_workname_map[$pid] : '';
            // 没找到worker_name说明出错了 哪里来的野孩子？
            if(empty($worker_name))
            {
                self::notice("child exist but not found worker_name pid:$pid");
                break;
            }
            
            // 进程退出状态不是0，说明有问题了
            if($status !== 0 && self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                self::notice("worker exit status $status pid:$pid worker:$worker_name");
            }
            // 记录进程退出状态
            self::$serverStatusInfo['err_info'][$worker_name][$status] = isset(self::$serverStatusInfo['err_info'][$worker_name][$status]) ? self::$serverStatusInfo['err_info'][$worker_name][$status] + 1 : 1;
            
            // 清理这个进程的数据
            self::clearWorker($worker_name, $pid);
        
            // 如果服务是不是关闭中
            if(self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                // 重新创建worker
                self::createWorkers();
                // 开发环境需要上报监控文件给FileMonitor
                if($worker_name == 'FileMonitor')
                {
                    Reporter::reportIncludedFiles(self::$filesToInotify);
                }
            }
            // 判断是否都重启完毕
            else
            {
                $all_worker_pid = self::getPidWorkerNameMap();
                if(empty($all_worker_pid))
                {
                    // 发送提示
                    self::notice("Server stoped");
                    // 关闭管理员socket
                    Telnet::close();
                    // 删除pid文件
                    @unlink(PID_FILE);
                    exit(0);
                }
            }//end if
        }//end while
    }
    
    /**
     * worker进程退出时，master进程的一些清理工作
     * @param string $worker_name
     * @param int $pid
     * @return void
     */
    protected static function clearWorker($worker_name, $pid)
    {
        // 删除事件监听
        self::$event->delAll(self::$channels[$pid]);
        // 释放一些不用了的数据
        unset(self::$channels[$pid], self::$workerToRestart[$pid], self::$workerPids[$worker_name][$pid], self::$pingInfo[$pid]);
        // 清除进程间通信缓冲区
        Cmd::clearPid($pid);
    }
    
    /**
     * 安装相关信号控制器
     * @return void
     */
    protected static function installSignal()
    {
        // 设置终止信号处理函数
        self::$event->add(SIGINT, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGINT);
        // 设置SIGUSR1信号处理函数,测试用
        self::$event->add(SIGUSR1, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGUSR1);
        // 设置SIGUSR2信号处理函数,平滑重启Server
        self::$event->add(SIGUSR2, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGUSR2);
        // 设置子进程退出信号处理函数
        self::$event->add(SIGCHLD, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGCHLD);
        
        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGHUP,  SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
    }
    
    /**
     * 设置server信号处理函数
     * @param null $null
     * @param int $signal
     * @return void
     */
    public static function signalHandler($null, $flag, $signal)
    {
        switch($signal)
        {
            // 停止server信号
            case SIGINT:
                self::notice("Server is shutting down");
                self::stop();
                break;
            // 展示server服务状态
            case SIGUSR1:
                break;
            // worker退出信号
            case SIGCHLD:
                // 不要在这里fork，fork出来的子进程无法收到信号
                // self::monitorWorkers();
                break;
            // 平滑重启server信号
            case SIGUSR2:
                self::notice("Server reloading");
                self::addToRestartWorkers(array_keys(self::getPidWorkerNameMap()));
                self::restartWorkers();
                break;
        }
    }
    
    /**
     * 初始化一些定时任务
     * @return void
     */
    protected static function initTask()
    {
        // 任务初始化
        Task::init();
        
        // 测试环境定时获取worker包含的文件
        if(PHPServerConfig::get('ENV') == 'dev')
        {
            // 定时获取worker包含的文件
            Task::add(self::$commonWaitTimeLong, array('PHPServer', 'sendCmdToAll'), array(Cmd::CMD_REPORT_INCLUDE_FILE));
            // 定时检测终端是否关闭
            Task::add(self::$commonWaitTimeLong, array('PHPServer', 'checkTty'));
        }
        else
        {
            // 定时发送alarm命令
            Task::add(self::$commonWaitTimeLong, array('PHPServer', 'sendCmdToAll'), array(Cmd::CMD_PING));
        }
        
        // 如果不支持inotify则上报文件给FileMonitor进程来监控文件更新
        Task::add(self::$checkFilesTimeLong, function (){
            Reporter::reportIncludedFiles(PHPServer::getFilesToInotify());
        });
        
        // 检查worker内存占用情况
        Task::add(self::$checkStatusTimeLong, array('PHPServer', 'checkWorkersMemory'));
        
        // 检查心跳情况
        Task::add(self::$checkStatusTimeLong, array('PHPServer', 'checkPingInfo'));
        
        // 开发环境定时清理master输出
        if(PHPServerConfig::get('ENV') == 'dev')
        {
            Task::add(self::$commonWaitTimeLong, function(){
                 @ob_clean();
            });
        }
    }
    
    /**
     * 获取服务状态
     * @return array
     */
    public static function getServerStatusInfo()
    {
        return self::$serverStatusInfo;
    }
    
    /**
     * 获取需要监控的文件
     * @return array:
     */
    public static function getFilesToInotify()
    {
        return self::$filesToInotify;
    }
    
    /**
     * 返回进程pid信息
     * @return array
     */
    public static function getWorkerPids()
    {
        return self::$workerPids;
    }
    
    /**
     * 获取workername长度最大值
     * @return number
     */
    public static function getMaxWorkerNameLength()
    {
        return self::$maxWorkerNameLength;
    }
    
    /**
     * 停止所有worker
     * @param bool $force 是否强制退出
     * @return void
     */
    public static function stopAllWorker($force = false)
    {
        // 获得所有pid
        $all_worker_pid = self::getPidWorkerNameMap();
        
        // 强行杀死？
        if($force)
        {
            // 杀死所有子进程
            foreach(self::getPidWorkerNameMap() as $pid=>$worker_name)
            {
                // 发送kill信号
                self::forceKillWorker($pid);
                if(self::$serverStatus != self::STATUS_SHUTDOWN)
                {
                    self::notice("Kill workers($worker_name) force!");
                }
            }
        }
        else
        {
            // 向所有worker发送停止服务命令
            self::sendCmdToAll(Cmd::CMD_STOP_SERVE);
        }
    }
    
    /**
     * 强制杀死进程
     * @param int $pid
     */
    public static function forceKillWorker($pid)
    {
        if(posix_kill($pid, 0))
        {
            if(self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                self::notice("Kill workers $pid force!");
            }
            posix_kill($pid, SIGKILL);
        }
    }
    
    /**
     * 向所有worker发送命令
     * @param char $cmd
     * @return void
     */
    public static function sendCmdToAll($cmd)
    {
        $result = array();
        foreach(self::$channels as $pid => $channel)
        {
            self::sendCmdToWorker($cmd, $pid);
        }
    }
    
    /**
     * 向特定的worker发送命令
     * @param char $cmd
     * @param int $pid
     * @return boolean|string|mixed
     */
    protected static function sendCmdToWorker($cmd, $pid)
    {
        // 如果是ping心跳包，则计数
        if($cmd == Cmd::CMD_PING)
        {
            if(!isset(self::$pingInfo[$pid]))
            {
                self::$pingInfo[$pid] = 0;
            }
            self::$pingInfo[$pid]++;
        }
        // 写入命令
        if(!@fwrite(self::$channels[$pid], Cmd::encodeForMaster($cmd), 1))
        {
            self::notice("send cmd:$cmd to pid:$pid fail");
            self::monitorWorkers();
        }
    }
    
    /**
     * 处理命令
     * @param char $cmd
     * @param mix $result
     * @param int $pid
     */
    protected static function dealCmd($cmd, $result, $pid)
    {
        // 获得所有pid到worker_name映射关系
        $all_pid_and_worker = self::getPidWorkerNameMap();
        $worker_name = isset($all_pid_and_worker[$pid]) ? $all_pid_and_worker[$pid] : '';
        
        switch ($cmd)
        {
            // 监控worker的使用的文件
            case Cmd::CMD_REPORT_INCLUDE_FILE:
                if(is_array($result))
                {
                    if(empty($worker_name))
                    {
                        self::notice("CMD_REPORT_INCLUDE_FILE pid:$pid has no worker_name");
                        return false;
                    }
                    // 获取已经监控的文件
                    $all_inotify_files = self::getFilesWorkerNameMap();
                    // 获取master进程使用的文件
                    $master_included_files = array_flip(get_included_files());
                    // 遍历worker上报的包含文件
                    foreach($result as $file)
                    {
                        // 过滤master进程包含的文件，没有监控的文件加入监控
                        if(!isset($all_inotify_files[$file]) && !isset($master_included_files[$file]))
                        {
                            self::$filesToInotify[$worker_name][$file] = $file;
                            if(Inotify::isSuport())
                            {
                                Inotify::addFile($file);
                            }
                        }
                    }
                    return true;
                }
                break;
            // 停止服务
            case Cmd::CMD_STOP_SERVE:
                // self::$event->delAll(self::$channels[$pid]);
                break;
            // 测试命令
            case Cmd::CMD_TEST:
                break;
            // 重启命令
            case Cmd::CMD_RESTART:
                // self::$event->delAll(self::$channels[$pid]);
                break;
            // telnet报告worker状态
            case Cmd::CMD_REPORT_STATUS_FOR_MASTER:
                $workers = PHPServerConfig::get('workers');
                $port = isset($workers[$worker_name]['port']) ? $workers[$worker_name]['port'] : 'none';
                $proto = isset($workers[$worker_name]['protocol']) ? $workers[$worker_name]['protocol'] : 'none';
                $str = "$pid\t".str_pad(round($result['memory']/(1024*1024),2)."M", 9)." $proto    ". str_pad($port, 5) ." ". $result['start_time'] ." ".str_pad($worker_name, self::$maxWorkerNameLength)." ";
                if($result)
                {
                    $str = $str . str_pad($result['total_request'], 14)." ".str_pad($result['recv_timeout'], 12)." ".str_pad($result['proc_timeout'],12)." ".str_pad($result['packet_err'],10)." ".str_pad($result['thunder_herd'],12)." ".str_pad($result['client_close'], 12)." ".str_pad($result['send_fail'],9)." ".str_pad($result['throw_exception'],15)." ".($result['total_request'] == 0 ? 100 : (round(($result['total_request']-($result['proc_timeout']+$result['packet_err']+$result['send_fail']))/$result['total_request'], 6)*100))."%";
                }
                else 
                {
                    $str .= var_export($result, true);
                }
                Telnet::sendToClient($str."\n");
                break;
            // 心跳包回复
            case Cmd::CMD_PONG:
                self::$pingInfo[$pid] = 0;
                break;
            // 未知命令
            case Cmd::CMD_UNKNOW:
                break;
        }
    }
    
    
    /**
     * 获取pid 到 worker_name 的映射
     * @return array('pid1'=>'worker_name1','pid2'=>'worker_name2', ...)
     */
    public static function getPidWorkerNameMap()
    {
        $all_pid = array();
        foreach(self::$workerPids as $worker_name=>$pid_array)
        {
            foreach($pid_array as $pid)
            {
                $all_pid[$pid] = $worker_name;
            }
        }
        return $all_pid;
    }
    
    /**
     * 设置进程名称，需要proctitle支持 或者php>=5.5
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        // 更改进程名
        if(extension_loaded('proctitle') && function_exists('setproctitle'))
        {
            @setproctitle($title);
        }
        // >=php 5.5
        elseif (version_compare(phpversion(), "5.5", "ge") && function_exists('cli_set_process_title'))
        {
            @cli_set_process_title($title);
        }
    }
    
    /**
     * 获得所有的监控中的文件
     * @return array
     */
    protected static function getFilesWorkerNameMap()
    {
        $all_files = array();
        foreach(self::$filesToInotify as $worker_name=>$file_array)
        {
            $all_files[$worker_name] = array();
            foreach($file_array as $file)
            {
                $all_files[$file][$worker_name] = $worker_name;
            }
        }
        return $all_files;
    }
    
    /**
     * 检查必要环境
     * @return void
     */
    protected static function checkEnv()
    {
        // 已经有进程pid可能server已经启动
        if(@file_get_contents(PID_FILE))
        {
            ServerLog::add("server already started");
            exit("server already started\n");
        }
        
        // 检查指定的worker用户是否合法
        self::checkWorkerUserName();
        
        // 检查扩展支持情况
        self::checkExtension();
        
        // 检查函数禁用情况
        self::checkDisableFunction();
        
        // 检查log目录是否可读
        if(!ServerLog::init())
        {
            $pad_length = 26;
            ServerLog::add(SERVER_BASE."logs/ Need to have read and write permissions\tServer start fail");
            exit("------------------------LOG------------------------\n".str_pad('/logs', $pad_length) . "\033[31;40m [NOT READABLE/WRITEABLE] \033[0m\n\n\033[31;40mDirectory ".SERVER_BASE."logs/ Need to have read and write permissions\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
        }
        
        // 检查配置和语法错误等
        self::checkWorkersConfig();
        
        // 检查文件限制
        self::checkLimit();
    }
    
    /**
     * 检查启动worker进程的的用户是否合法
     * @return void
     */
    protected static function checkWorkerUserName()
    {
        foreach(array(self::$workerUserName, PHPServerConfig::get('worker_user')) as $worker_user)
        {
            if($worker_user)
            {
                $user_info = posix_getpwnam($worker_user);
                if(empty($user_info))
                {
                    ServerLog::add("Can not run worker processes as user $worker_user , User $worker_user not exists\tServer start fail");
                    exit("\033[31;40mCan not run worker processes as user $worker_user , User $worker_user not exists\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
                }
                if(self::$workerUserName != $worker_user)
                {
                    self::$workerUserName = $worker_user;
                }
                break;
            }
        }
    }
    
    /**
     * 检查扩展支持情况
     * @return void
     */
    public static function checkExtension()
    {
        // 扩展名=>是否是必须
        $need_map = array(
                'posix'     => true,
                'pcntl'     => true,
                'libevent'  => false,
                'ev'        => false,
                'uv'        => false,
                'proctitle' => false,
                'inotify'   => false,
        );
        
        // 检查每个扩展支持情况
        echo "----------------------EXTENSION--------------------\n";
        $pad_length = 26;
        foreach($need_map as $ext_name=>$must_required)
        {
            $suport = extension_loaded($ext_name);
            if($must_required && !$suport)
            {
                ServerLog::add($ext_name. " [NOT SUPORT BUT REQUIRED] \tYou have to compile CLI version of PHP with --enable-{$ext_name} \tServer start fail");
                exit($ext_name. " \033[31;40m [NOT SUPORT BUT REQUIRED] \033[0m\n\n\033[31;40mYou have to compile CLI version of PHP with --enable-{$ext_name} \033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
            }
        
            // 自动检测支持的事件轮询库
            if(self::$eventLoopName == 'Select' && $suport)
            {
                if('libevent' == $ext_name)
                {
                	// 禁用libevent，发现会有低概率接收超时问题 日期2014-12-23
                    //self::$eventLoopName = 'Libevent';
                }
                else if('ev' == $ext_name)
                {
                    //self::$eventLoopName = 'Libev';
                }
                else if('uv' == $ext_name)
                {
                    //self::$eventLoopName = 'Libuv';
                }
            }
            // 支持扩展
            if($suport)
            {
                echo str_pad($ext_name, $pad_length), "\033[32;40m [OK] \033[0m\n";
            }
            // 不支持
            else
            {
                // ev uv inotify不是必须
                if('ev' == $ext_name || 'uv' == $ext_name || 'inotify' == $ext_name || 'proctitle' == $ext_name)
                {
                    continue;
                }
                echo str_pad($ext_name, $pad_length), "\033[33;40m [NOT SUPORT] \033[0m\n";
            }
        }
    }
    
    /**
     * 检查禁用的函数
     * @return void
     */
    public static function checkDisableFunction()
    {
        // 可能禁用的函数
        $check_func_map = array(
                'stream_socket_server',
                'stream_socket_client',
        );
        if($disable_func_string = ini_get("disable_functions"))
        {
            $disable_func_map = array_flip(explode(',', $disable_func_string));
        }
        // 遍历查看是否有禁用的函数
        foreach($check_func_map as $func)
        {
            if(isset($disable_func_map[$func]))
            {
                ServerLog::add("Function $func may be disabled\tPlease check disable_functions in php.ini \t Server start fail");
                exit("\n\033[31;40mFunction $func may be disabled\nPlease check disable_functions in php.ini\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
            }
        }
    }
    
    /**
     * 检查worker配置、worker语法错误等
     * @return void
     */
    public static function checkWorkersConfig()
    {
        $pad_length = 26;
        $total_worker_count = 0;
        // 检查worker 是否有语法错误
        echo "----------------------WORKERS--------------------\n";
        foreach (PHPServerConfig::get('workers') as $worker_name=>$config)
        {
            // 端口、协议、进程数等信息
            if(empty($config['child_count']))
            {
                ServerLog::add(str_pad($worker_name, $pad_length)." [child_count not set]\tServer start fail");
                exit(str_pad($worker_name, $pad_length)."\033[31;40m [child_count not set]\033[0m\n\n\033[31;40mServer start fail\033[0m\n");
            }
            
            $total_worker_count += $config['child_count'];
            
            // 计算最长的worker_name
            if(self::$maxWorkerNameLength < strlen($worker_name))
            {
                self::$maxWorkerNameLength = strlen($worker_name);
            }
        
            // 语法检查
            if(0 != self::checkSyntaxError($worker_name))
            {
                unset(PHPServerConfig::instance()->config['workers'][$worker_name]);
                self::notice("$worker_name has Fatal Err");
                echo str_pad($worker_name, $pad_length),"\033[31;40m [Fatal Err] \033[0m\n";
                break;
            }
            echo str_pad($worker_name, $pad_length),"\033[32;40m [OK] \033[0m\n";
        }
        
        if($total_worker_count > self::SERVER_MAX_WORKER_COUNT)
        {
            ServerLog::add("Number of worker processes can not be more than " . self::SERVER_MAX_WORKER_COUNT . ".\tPlease check child_count in " . SERVER_BASE . "config/main.php\tServer start fail");
            exit("\n\033[31;40mNumber of worker processes can not be more than " . self::SERVER_MAX_WORKER_COUNT . ".\nPlease check child_count in " . SERVER_BASE . "config/main.php\033[0m\n\n\033[31;40mServer start fail\033[0m\n");
        }
        
        echo "-------------------------------------------------\n";
    }
    
    /**
     * 检查worker文件是否有语法错误
     * @param string $worker_name
     * @return int 0：无语法错误 其它:可能有语法错误
     */
    protected static function checkSyntaxError($worker_name)
    {
        $pid = pcntl_fork();
        // 父进程
        if($pid > 0)
        {
            // 退出状态不为0说明可能有语法错误
            $pid = pcntl_wait($status);
            return $status;
        }
        // 子进程
        elseif($pid == 0)
        {
            // 载入对应worker
            $class_name = PHPServerConfig::get('workers.' . $worker_name . '.worker_class');
            $class_name = $class_name ? $class_name : $worker_name;
            include_once SERVER_BASE . 'workers/'.$class_name.'.php';
            
            if(!class_exists($class_name)) 
            {
                throw new Exception("Class $class_name not exists");
            }
            exit(0);
        }
    }
    
    /**
     * 检查打开文件限制
     * @return void
     */
    public static function checkLimit()
    {
        if(PHPServerConfig::get('ENV') != 'dev' && $limit_info = posix_getrlimit())
        {
            if('unlimited' != $limit_info['soft openfiles'] && $limit_info['soft openfiles'] < self::MIN_SOFT_OPEN_FILES)
            {
                echo "Notice : Soft open files now is {$limit_info['soft openfiles']},  We recommend greater than " . self::MIN_SOFT_OPEN_FILES . "\n";
            }
            if('unlimited' != $limit_info['hard filesize'] && $limit_info['hard filesize'] < self::MIN_SOFT_OPEN_FILES)
            {
                echo "Notice : Hard open files now is {$limit_info['hard filesize']},  We recommend greater than " . self::MIN_HARD_OPEN_FILES . "\n";
            }
        }
    }
    
    /**
     * 检查所有进程内存占用情况
     * @return void
     */
    public static function checkWorkersMemory()
    {
        clearstatcache(true);
        foreach(self::getPidWorkerNameMap() as $pid=>$worker_name)
        {
            self::checkWorkerMemoryByPid($pid);
        }
        if(!empty(self::$workerToRestart))
        {
            self::restartWorkers();
        }
    }
    
    /**
     * 检查是否有长时间没响应ping心跳包的进程
     */
    public static function checkPingInfo()
    {
        $pid_to_restart = array();
        $pid_workname_map = self::getPidWorkerNameMap();
        foreach(self::$pingInfo as $pid=>$not_recv_pong_count)
        {
            if(!isset($pid_workname_map[$pid]))
            {
                self::notice("checkPingInfo and self::\$pid_workname_map[$pid] not exsits");
                unset(self::$pingInfo[$pid]);
                continue;
            }
            if($not_recv_pong_count >= self::PONG_MISS_LIMIT)
            {
                if(false !== PHPServerConfig::get('workers.'.$pid_workname_map[$pid].'.heart_detection'))
                {
                    $pid_to_restart[$pid] = $pid;
                }
            }
        }
        if($pid_to_restart)
        {
            self::notice('PID['.implode(',', $pid_to_restart).'] not send PONG ' . self::PONG_MISS_LIMIT . ' times so RESTART them');
            self::addToRestartWorkers($pid_to_restart);
            self::restartWorkers();
        }
    }
    
    /**
    * 根据进程id收集进程内存占用情况
    * @param int $pid
    * @return bool
    */
    protected static function checkWorkerMemoryByPid($pid)
    {
        // 读取系统对该进程统计的信息
        $status_file = "/proc/$pid/status";
        if(is_file($status_file))
        {
            // 获取信息
            $status = file_get_contents($status_file);
            if(empty($status))
            {
                return false;
            }
            // 目前只需要进程的内存占用占用信息
            $match = array();
            if(preg_match('/VmRSS:\s+(\d+)\s+([a-zA-Z]+)/', $status, $match))
            {
                $memory_usage = $match[1];
                if($memory_usage >= self::MAX_MEM_LIMIT)
                {
                    self::addToRestartWorkers(array($pid));
                }
                return true;
            }
            else
            {
                return false;
            }
        }
        return false;
    } 
    
    
    /**
     * 关闭标准输入输出
     * @return void
     */
    protected static function resetStdFd()
    {
        // 开发环境不关闭标准输出，用于调试
        if(PHPServerConfig::get('ENV') == 'dev')
        {
            ob_start();
            return;
        }
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        $STDOUT = fopen('/dev/null',"rw+");
        $STDERR = fopen('/dev/null',"rw+");
    }
    
    /**
     * 屏蔽alarm信号
     * @return void
     */
    protected static function ignoreSignalAlarm()
    {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_signal_dispatch();
    }
    
    /**
     * 恢复标准输出(开发环境用)
     * @return void
     */
    protected static function recoverStdFd()
    {
        if(PHPServerConfig::get('ENV') == 'dev')
        {
            @ob_end_clean();
        }
        if(!posix_ttyname(STDOUT))
        {
            global $STDOUT, $STDERR;
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen('/dev/null',"rw+");
            $STDERR = fopen('/dev/null',"rw+");
            return;
        }
    }
    
    public static function checkTty()
    {
        if(self::$terminalHasClosed)
        {
            return;
        }
        if(!posix_ttyname(STDOUT))
        {
            self::notice("The terminal is closed ,Server reloading");
            self::addToRestartWorkers(array_keys(self::getPidWorkerNameMap()));
            self::restartWorkers();
            self::$terminalHasClosed = true;
        }
    }
    
    /**
     * notice,记录到日志，同时打印到telnet客户端（如果有telnet链接的话）
     * @param string $msg
     * @param bool $display
     */
    protected static function notice($msg, $display = false)
    {
        ServerLog::add("Server notice:".$msg);
        Telnet::sendToAllClient($msg."\n");
        if($display)
        {
            if(self::$serverStatus == self::STATUS_STARTING && posix_ttyname(STDOUT))
            {
                echo($msg."\n");
            }
        }
    }
    
    /**
     * 打印Server属性
     * @param string $var
     * @return string
     */
    public static function debugVar($var)
    {
        $var = strval($var);
        if(isset(self::$$var))
        {
            return var_export(self::$$var, true);
        }
        return 'null';
    }
}

/**
 * Autoloader
 */
$LoadableModules = array('core', 'core/events', 'plugins', 'workers', 'protocols');

spl_autoload_register(function($name) {
    global $LoadableModules;

    foreach ($LoadableModules as $module) {
        $filename = SERVER_BASE . $module . '/' . $name . '.php';
        if (file_exists($filename))
            require_once $filename;
    }
});

