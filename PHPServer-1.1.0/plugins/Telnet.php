<?php
/**
 * 
 * telnet到master进程查看服务状态等
 * 
 * @author liangl
 *
 */
class Telnet 
{
    // 管理员控制server的socket
    protected static $bindSocket = null;
    // 管理员控制server的链接
    protected static $connections = array();
    // 当前处理的管理员的链接id
    protected static $currentFd = 0;
    // 管理员认证信息
    protected static $adminAuth = array();
    // 时间轮询库实例
    protected static $event = null;
    // 最长的workerName
    protected static $maxWorkerNameLength = 30;
    
    static function bindAndListen($ip, $port, $event)
    {
        self::$event = $event;
        
        self::$maxWorkerNameLength = PHPServer::getMaxWorkerNameLength();
        
        // telnet控制server的socket
        $error_no = 0;
        $error_msg = '';
        self::$bindSocket = stream_socket_server("tcp://$ip:$port", $error_no, $error_msg);
        if(!self::$bindSocket)
        {
            return false;
        }
        
        // telnet添加accept事件
        $event->add(self::$bindSocket,  BaseEvent::EV_ACCEPT, array('Telnet', 'acceptConnection'));
        return true;
    }
    
    
    /**
     * telnet远程控制接受一个链接
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public static function acceptConnection($socket, $null_one = null, $null_two = null)
    {
        // 获得一个连接
        $new_connection = @stream_socket_accept($socket, 0);
        // 出错
        if(false === $new_connection)
        {
            return false;
        }
        // 连接的fd序号
        $fd = (int) $new_connection;
        self::$currentFd = $fd;
        self::$connections[$fd] = $new_connection;
        // 非阻塞
        stream_set_blocking(self::$connections[$fd], 0);
        self::$event->add(self::$connections[$fd], BaseEvent::EV_READ , array('Telnet', 'dealCmd'), $fd, 0, 0);
        $ip = self::getRemoteIp();
        if($ip != '127.0.0.1')
        {
            ServerLog::add("ip:$ip Telnet Server");
            self::sendToClient("password:\n", $new_connection);
        }
        else
        {
            self::sendToClient("Hello Admin \n", $new_connection);
            self::$adminAuth[$fd] = time();
        }
    }
    
    /**
     * 处理收到的管理员命令
     * @param event_buffer $event_buffer
     * @param int $fd
     * @return boolean
     */
    public static function dealCmd($connection, $length, $buffer, $fd = null)
    {
        // 管理员断开
        if($length == 0)
        {
            self::closeClient($fd);
            return;
        }
        self::$currentFd = $fd;
        $buffer = trim($buffer);
        
        $ip = self::getRemoteIp();
        if($ip != '127.0.0.1' && $buffer == 'status')
        {
            ServerLog::add("IP:$ip $buffer");
        }
    
        // 判断是否认证过
        self::$adminAuth[$fd] = !isset(self::$adminAuth[$fd]) ? 0 : self::$adminAuth[$fd];
        if(self::$adminAuth[$fd] < 3)
        {
            if($buffer != 'P@ssword')
            {
                if(++self::$adminAuth[$fd] >= 3)
                {
                    self::sendToClient("Password Incorrect \n");
                    self::closeClient($fd);
                }
                self::sendToClient("Please Try Again\n");
                return;
            }
            else
            {
                self::$adminAuth[$fd] = time();
                self::sendToClient("Hello Admin \n");
                return;
            }
        }
    
        $pid_worker_name_map = PHPServer::getPidWorkerNameMap();
        // 单独停止某个worker进程
        if(preg_match("/kill (\d+)/", $buffer, $match))
        {
            $pid = $match[1];
            if(isset($pid_worker_name_map[$pid]))
            {
                self::sendToClient("Kill Pid $pid ");
                PHPServer::addToRestartWorkers(array($pid));
                PHPServer::restartWorkers();
            }
            else
            {
                self::sendToClient("Pid Not Exsits\n");
            }
            return;
        }
        
        // 打印某个变量 print_r $current
        if(preg_match("/debug (.*)/", $buffer, $match))
        {
            $var = trim($match[1]);
            $var = str_replace('$', '', $var);
            if(!$var)
            {
                self::sendToClient("Uesage debug var_name\n");
                return;
            }
            self::sendToClient(PHPServer::debugVar($var)."\n");
            return;
        }
        
    
        switch($buffer)
        {
            // 展示统计信息
            case 'status':
                $status = PHPServer::getServerStatusInfo();
                $worker_pids = PHPServer::getWorkerPids();
                $loadavg = sys_getloadavg();
                self::sendToClient("---------------------------------------GLOBAL STATUS--------------------------------------------\n");
                self::sendToClient('start time:'. date('Y-m-d H:i:s', $status['start_time']).'   run ' . floor((time()-$status['start_time'])/(24*60*60)). ' days ' . floor(((time()-$status['start_time'])%(24*60*60))/(60*60)) . " hours   \n");
                self::sendToClient('load average: ' . implode(", ", $loadavg) . "\n");
                self::sendToClient(count(self::$connections) . ' users          ' . count($worker_pids) . ' workers       ' . count($pid_worker_name_map)." processes\n");
                self::sendToClient(str_pad('worker_name', self::$maxWorkerNameLength) . " exit_status     exit_count\n");
                foreach($worker_pids as $worker_name=>$pid_array)
                {
                    if(isset($status['err_info'][$worker_name]))
                    {
                        foreach($status['err_info'][$worker_name] as  $exit_status=>$exit_count)
                        {
                            self::sendToClient(str_pad($worker_name, self::$maxWorkerNameLength) . " " . str_pad($exit_status, 16). " $exit_count\n");
                        }
                    }
                    else
                    {
                        self::sendToClient(str_pad($worker_name, self::$maxWorkerNameLength) . " " . str_pad(0, 16). " 0\n");
                    }
                }
    
                PHPServer::sendCmdToAll(Cmd::CMD_REPORT_STATUS_FOR_MASTER);
                self::sendToClient("---------------------------------------PROCESS STATUS-------------------------------------------\n");
                self::sendToClient("pid\tmemory    proto  port  timestamp  ".str_pad('worker_name', self::$maxWorkerNameLength)." ".str_pad('total_request', 13)." ".str_pad('recv_timeout', 12)." ".str_pad('proc_timeout',12)." ".str_pad('packet_err', 10)." ".str_pad('thunder_herd', 12)." ".str_pad('client_close', 12)." ".str_pad('send_fail', 9)." ".str_pad('throw_exception', 15)." suc/total\n");
                break;
                // 停止server
            case 'stop':
                PHPServer::stop();
                break;
                // 平滑重启server
            case 'reload':
                PHPServer::addToRestartWorkers(array_keys($pid_worker_name_map));
                PHPServer::restartWorkers();
                self::sendToClient("Restart Workers ");
                break;
                // admin管理员退出
            case 'quit':
                self::sendToClient("Admin Quit\n");
                self::$event->delAll(self::$connections[self::$currentFd]);
                fclose(self::$connections[self::$currentFd]);
                unset(self::$connections[self::$currentFd],self::$adminAuth[self::$currentFd]);
                break;
            case 'debug':
                self::sendToClient("Uesage debug var_name\n");
                break;
            case '':
                break;
            default:
                self::sendToClient("Unkonw CMD \nAvailable CMD:\n status     show server status\n stop       stop server\n reload     graceful restart server\n quit       quit and close connection\n kill pid   kill the worker process of the pid\n");
        }
    }
    
    /**
     * 关闭telnet管理员链接
     * @param int $fd
     */
    protected static function closeClient($fd)
    {
        if(isset(self::$connections[$fd]))
        {
            self::$event->delAll(self::$connections[$fd]);
        }
        unset(self::$connections[$fd], self::$adminAuth[$fd]);
    }
    
    /**
     * 发送数据到管理员客户端
     * @return int/false
     */
    public static function sendToClient($str_to_send, $socket = null)
    {
        if(!isset(self::$connections[self::$currentFd]) && null === $socket)
        {
            return false;
        }
        return @stream_socket_sendto(null === $socket ? self::$connections[self::$currentFd] : $socket, $str_to_send);
    }
    
    /**
     * 发送数据到所有管理员客户端
     * @return int/false
     */
    public static function sendToAllClient($str_to_send)
    {
        foreach(self::$connections as $con)
        {
            self::sendToClient($str_to_send, $con);
        }
    }
    
    /**
     * 获取客户端ip
     * @return string
     */
    public static function getRemoteIp()
    {
        $ip = '';
        $sock_name = stream_socket_get_name(self::$connections[self::$currentFd], true);
        if($sock_name)
        {
            $tmp = explode(':', $sock_name);
            $ip = $tmp[0];
        }
        return $ip;
    }
    
    /**
     * 关闭telnet链接
     */
    public static function close()
    {
        foreach(self::$connections as $con)
        {
            fclose($con);
        }
        self::$connections = array();
        self::$event->delAll(self::$bindSocket);
        @fclose(self::$bindSocket);
    }
    
}
