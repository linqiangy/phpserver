<?php 

/**
 * 
 * 定时telnet master进程 监控服务状态 并触发告警
 * 定时清理log文件
 * 
 * @author liangl
 *
 */
class Monitor extends PHPServerWorker
{
    
    // ==告警类型==
    // 大量worker进程退出
    const WARNING_TOO_MANY_WORKERS_EXIT = 1;
    // 框架低成功率
    const WARNING_FRAMEWORK_lOW_SUCCESS_RATE = 2;
    // 业务低成功率
    const WARNING_STATISTIC_lOW_SUCCESS_RATE = 4;
    // 主进程死掉
    const WARNING_MASTER_DEAD = 8;
    
    // 成功率最低值(框架网络层面)
    const MIN_FRAMEWORK_lOW_SUCCESS_RATE = 98;
    // 成功率最低值(业务层面)
    const MIN_BUSINESS_lOW_SUCCESS_RATE = 98;
    // 进程最大（退出状态码为0）退出数
    const MAX_WORKER_NORMAL_EXIT_COUNT = 1000;
    // 进程最大意外（退出状态码非0）退出数
    const MAX_WORKER_UNEXPECT_EXIT_COUNT = 20;
    
    // ==时长相关 单位:秒==
    // 多长时间检查一次状态
    const CHECK_STATUS_TIME_LONG = 60;
    // 多长时间检查一次业务成功率
    const CHECK_BUSINESS_TIME_LONG = 300;
    // 接收telnet数据超时时间 ms
    const TELNET_RECV_TIMEOUT = 2000;
    // 告警发送时间间隔
    const WARING_SEND_TIME_LONG = 600;
    
    // ==清理日志文件相关==
    // 一天有多少秒
    const SECONDS_ONE_DAY = 86400;
    // 多长时间清理一次磁盘日志文件
    const CLEAR_LOGS_TIME_LONG = 86400;
    // 清理多少天前的日志文件
    const CLEAR_BEFORE_DAYS = 14;
    
    // 上次发送告警的时间
    protected static $lastWarningTimeMap = array(
            self::WARNING_TOO_MANY_WORKERS_EXIT => 0,
            self::WARNING_FRAMEWORK_lOW_SUCCESS_RATE => 0,
            self::WARNING_STATISTIC_lOW_SUCCESS_RATE => 0,
    );
    
    // telnet到master进程的socket
    protected $telnetSocketToMaster = null;
    
    // telnet结果的缓冲
    protected $telnetBuffer = '';
    
    /**
     * 上次master进程状态 [worker_name=>[exit_status=>exit_count, exit_status=>exit_count], worker_name=>[..], ..]
     * @var array
     */
    protected $lastMasterStatus = array();
    
    /**
     * 本次master进程状态 [worker_name=>[exit_status=>exit_count, exit_status=>exit_count], worker_name=>[..], ..]
     * @var array
     */
    protected $currentMasterStatus = array();
    
    /**
     * 上次worker状态 [worker_name=>[pid=>[worker_name,total_request,recv_timeout,proc_timeout...], pid=>[worker_name,..], ...],'worker_name'=>[ ..],..]
     * @var array
     */
    protected $lastWorkerStatus = array();
    
    /**
     * 本次worker状态 [worker_name=>[pid=>[worker_name,total_request,recv_timeout,proc_timeout...], pid=>[worker_name,..], ...],'worker_name'=>[ ..],..]
     * @var array
     */
    protected $currentWorkerStatus = array();
    
    /**
     * 进程状态字段 [pid, worker_name, ...]
     * @var array
     */
    protected $workerStatusFields = array();
    
    /**
     * 该worker进程开始服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onServe()
    {
        if(!is_dir(SERVER_BASE . 'logs/statistic'))
        {
            @mkdir(SERVER_BASE . 'logs/statistic', 0777);
        }
        Task::add(self::CHECK_STATUS_TIME_LONG, array($this, 'dealStatus'));
        Task::add(self::CHECK_BUSINESS_TIME_LONG, array($this, 'checkBusiness'));
        Task::add(self::CLEAR_LOGS_TIME_LONG, array($this, 'clearLogs'), array(SERVER_BASE . 'logs'));
    }
    
    /**
     * 检查业务成功率
     */
    public function checkBusiness()
    {
        clearstatcache();
        $st_dir = SERVER_BASE . 'logs/statistic/st/';
        $today = date('Y-m-d');
        // 只看15分钟以内的统计
        $time_line = time() - 60*9;
        $module_interface_map = $this->getModuleInterface();
        foreach($module_interface_map as $module=>$interfaces)
        {
            if(empty($interfaces) || $module == 'PHPServer')
            {
                continue;
            }
            foreach($interfaces as $interface)
            {
                $st_file = SERVER_BASE . "logs/statistic/st/$module/$interface|$today";
                if(!is_file($st_file))
                {
                    continue;
                }
                $lines = file($st_file, FILE_IGNORE_NEW_LINES | FILE_IGNORE_NEW_LINES);
                if(!empty($lines))
                {
                    $lines = array_reverse($lines);
                    $last_time_line = 0;
                    $total_suc_count = $total_fail_count = 0;
                    foreach($lines as $line)
                    {
                        $tmp = explode("\t", $line);
                        if(count($tmp) < 6)
                        {
                            continue;
                        }
                        list($ip, $time, $suc_count, $suc_cost, $fail_count, $fail_cost) = $tmp;
                        if($time < $time_line)
                        {
                            continue;
                        }
                        $last_time_line = $last_time_line ? $last_time_line : $time;
                        if($time != $last_time_line)
                        {
                            break;
                        }
                        $total_suc_count += $suc_count;
                        $total_fail_count += $fail_count;
                    }
                }
                $total_count = $total_suc_count + $total_fail_count;
                if(!$total_count)
                {
                    continue;
                }
                $success_rate = round($total_suc_count/$total_count, 4)*100;
                $MIN_BUSINESS_lOW_SUCCESS_RATE = PHPServerConfig::get('workers.Monitor.framework.min_success_rate') > 0 ? PHPServerConfig::get('workers.Monitor.framework.min_success_rate') : self::MIN_BUSINESS_lOW_SUCCESS_RATE;
                if($success_rate < $MIN_BUSINESS_lOW_SUCCESS_RATE)
                {
                    $this->onBusinessLowSuccessRate($module, $interface, $total_count, $total_fail_count, $success_rate, $last_time_line);
                }
            }
        }
    }
    
    protected function getModuleInterface()
    {
        clearstatcache();
        $st_dir = SERVER_BASE . 'logs/statistic/st/';
        $modules_name_array = array();
        foreach(glob($st_dir."/*") as $module_file)
        {
            $tmp = explode("/", $module_file);
            $module = end($tmp);
            $modules_name_array[$module] = array();
            $st_dir = SERVER_BASE . 'logs/statistic/st/'.$module.'/';
            $all_interface = array();
            foreach(glob($st_dir."*") as $file)
            {
                if(is_dir($file))
                {
                    continue;
                }
                $tmp = explode("|", basename($file));
                $interface = trim($tmp[0]);
                if(isset($all_interface[$interface]))
                {
                    continue;
                }
                $all_interface[$interface] = $interface;
            }
            $modules_name_array[$module] = $all_interface;
        }
        return $modules_name_array;
    }
    
    /**
     * 处理状态
     */
    public function dealStatus()
    {
        $this->analyseStatus();
        $this->checkMasterStatus();
        $this->checkWorkerStatus();
        $this->logStatus();
        $this->showStatus();
    }
    
    /**
     * 分析状态
     */
    protected function analyseStatus()
    {
        $line_array = explode("\n", $this->telnetBuffer);
        foreach($line_array as $line)
        {
            if(preg_match("/^pid\s+[a-z_]+\s+[a-z_]+\s+[a-z_]+\s+[a-z_]+\s+[a-z_]+\s+/", $line))
            {
                $tmp = preg_replace("/\s+/",' ', $line);
                $this->workerStatusFields = explode(' ', $tmp);
            }
            elseif(preg_match("/^\d+\s[^ ]+M\s+[tcdpu]{3}\s/", $line))
            {
                $tmp = preg_replace("/\s+/",' ', $line);
                $tmp = explode(' ', $tmp);
                $pid = $tmp[0];
                $status = @array_combine($this->workerStatusFields, $tmp);
                if($this->workerStatusFields)
                {
                    $this->currentWorkerStatus[$status['worker_name']][$pid] = @array_combine($this->workerStatusFields, $tmp);
                }
            }
            elseif(preg_match("/^[A-Za-z]+\s+\d+\s+\d/", $line))
            {
                $tmp = preg_replace("/\s+/",' ', $line);
                $tmp = explode(' ', $tmp);
                $worker_name = $tmp[0];
                $exit_status = $tmp[1];
                $exit_count = $tmp[2];
                $this->currentMasterStatus[$worker_name][$exit_status] = $exit_count;
            }
        }
        
    }
    
    /**
     * 检查master进程状态
     */
    protected function checkMasterStatus()
    {
        $MAX_WORKER_NORMAL_EXIT_COUNT = PHPServerConfig::get('workers.Monitor.framework.max_worker_normal_exit_count') > 0 ? PHPServerConfig::get('workers.Monitor.framework.max_worker_normal_exit_count') : self::MAX_WORKER_NORMAL_EXIT_COUNT;
        $MAX_WORKER_UNEXPECT_EXIT_COUNT = PHPServerConfig::get('workers.Monitor.framework.max_worker_unexpect_exit_count') > 0 ? PHPServerConfig::get('workers.Monitor.framework.max_worker_unexpect_exit_count') : self::MAX_WORKER_UNEXPECT_EXIT_COUNT;
        if(!empty($this->lastMasterStatus))
        {
            foreach($this->currentMasterStatus as $worker_name=>$status_array)
            {
                foreach($status_array as $exit_status=>$exit_count)
                {
                    $last_count = isset($this->lastMasterStatus[$worker_name][$exit_status]) ? $this->lastMasterStatus[$worker_name][$exit_status] : 0;
                    $current_exit_count = $exit_count - $last_count;
                    if($exit_status == 0)
                    {
                        if($current_exit_count >= $MAX_WORKER_NORMAL_EXIT_COUNT)
                        {
                            $this->onTooManyWorkersExits($worker_name, $exit_status, $exit_count);
                        }
                    }
                    else
                    {
                        if($current_exit_count >= $MAX_WORKER_UNEXPECT_EXIT_COUNT)
                        {
                            $this->onTooManyWorkersExits($worker_name, $exit_status, $exit_count);
                        }
                    }
                    
                }
            }
        }
        $this->lastMasterStatus = $this->currentMasterStatus;
        $this->currentMasterStatus = array();
    }
    
    /**
     * 检查worker进程状态
     */
    protected function checkWorkerStatus()
    {
        $MIN_FRAMEWORK_lOW_SUCCESS_RATE = PHPServerConfig::get('workers.Monitor.framework.min_success_rate') > 0 ? PHPServerConfig::get('workers.Monitor.framework.min_success_rate') : self::MIN_FRAMEWORK_lOW_SUCCESS_RATE;
        foreach($this->currentWorkerStatus as $worker_name => $status_array)
        {
            $total_request = 0;
            $fail_count = 0;
            foreach($status_array as $pid=>$status)
            {
                if(!isset($this->lastWorkerStatus[$worker_name][$pid]))
                {
                    continue;
                }
                $c = $status;
                $l = $this->lastWorkerStatus[$worker_name][$pid];
                $fail_count += $c['proc_timeout']+$c['packet_err']+$c['send_fail'] - ($l['proc_timeout']+$l['packet_err']+$l['send_fail']);
                $total_request += $c['total_request']-$l['total_request'];
            }
            // 大于100个请求才告警
            $min_total_request = 100;
            if($total_request > $min_total_request)
            {
                $success_rate = round(($total_request-$fail_count)*100/$total_request, 3);
                if($success_rate >= 0 && $success_rate <= $MIN_FRAMEWORK_lOW_SUCCESS_RATE)
                {
                    $this->onFrameworkLowSuccessRate($worker_name, $success_rate);
                }
            }
        }
        
        $this->lastWorkerStatus = $this->currentWorkerStatus;
        $this->currentWorkerStatus = array();
    }
    
    /**
     * 检查状态
     */
    public function showStatus()
    {
        $this->telnetBuffer = "\r\n\r\n====================================".date('Y-m-d H:i:s')."=========================================\n";
        if(!empty($this->telnetSocketToMaster))
        {
            $this->closeTelnet();
        }
        $this->telnetSocketToMaster = stream_socket_client("tcp://127.0.0.1:10101", $no, $msg);
        if(!$this->telnetSocketToMaster)
        {
            return false;
        }
        stream_set_blocking($this->telnetSocketToMaster, 0);
        fwrite($this->telnetSocketToMaster, 'status');
        $this->event->add($this->telnetSocketToMaster, BaseEvent::EV_READ , array($this, 'dealTelnetInput'), $this->telnetSocketToMaster, self::TELNET_RECV_TIMEOUT);
    }
    
    /**
     * 处理master telnet 回包
     * @param resource $connection
     * @param int $length
     * @param string $buffer
     * @param resource $fd
     */
    public function dealTelnetInput($connection, $length, $buffer, $fd = null)
    {
        // 链接变为null，说明已经断开了
        if(!isset($this->telnetSocketToMaster))
        {
            $this->closeTelnet();
            return false;
        }
    
        // 出错了
        if($length == 0)
        {
            // 一般是超时了
            $this->closeTelnet();
            return false;
        }
    
        $this->dealTelnetResult($buffer);
    }
    
    /**
     * 关闭到master进程的telnet
     */
    protected function closeTelnet()
    {
        if($this->telnetSocketToMaster)
        {
            $this->event->delAll($this->telnetSocketToMaster);
        }
        @fclose($this->telnetSocketToMaster);
        unset($this->telnetSocketToMaster);
    }
    
    /**
     * 处理telnet回包
     * @param string $buffer
     */
    protected function dealTelnetResult($buffer)
    {
        $this->telnetBuffer .= $buffer;
    }
    
    /**
     * 每隔一段时间(5s)会触发该函数，用于触发worker某些流程
     * @return bool
     */
    protected function onAlarm()
    {
        Task::tick();
    }
    
    /**
     * 主进程挂掉会触发一次
     * @see PHPServerWorker::onMasterDead()
     */
    protected function onMasterDead()
    {
        // 延迟告警，启动脚本kill掉主进程不告警，该进程也会随之kill掉
        sleep(60);
        
        $ip = $this->getIp();
        
        $alarm_data = array(
        	'type'=>1,
       		'ip'=>$ip,
        );
        
        $this->sendSms($alarm_data, '告警消息 PHPServer框架监控 ip:'.$ip.' 主进程意外退出 时间：'.date('Y-m-d H:i:s'));
    }
    
    /**
     * 保存状态
     */
    protected function logStatus()
    {
        clearstatcache();
        $log_dir = SERVER_BASE . 'logs/'.date('Y-m-d');
        if(!is_dir($log_dir))
        {
            mkdir($log_dir, 0777);
        }
        file_put_contents(SERVER_BASE . 'logs/'.date('Y-m-d').'/master-status', $this->telnetBuffer, FILE_APPEND);
    }
    
    /**
     * 确定包是否完整
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return 0;
    }
    
    /**
     * 处理业务
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $this->sendToClient($recv_str);
    }
    
    /**
     * 当有大量进程频繁退出时触发
     * @param string $worker_name
     * @param int $status
     * @param int $exit_count
     * @return void
     */
    public function onTooManyWorkersExits($worker_name, $status, $exit_count)
    {
        // 不要频繁告警，5分钟告警一次
        $time_now = time();
        if($time_now - self::$lastWarningTimeMap[self::WARNING_TOO_MANY_WORKERS_EXIT] < self::WARING_SEND_TIME_LONG)
        {
            return;
        }
        
        $ip = $this->getIp();
        
        if(65280 == $status || 30720 == $status)
        {
        	$alarm_data = array(
        		'type'=>3,
        		'ip'=>$ip,
        		'worker_name'=>$worker_name,
        		'count'=>$exit_count,
        	);
            $this->sendSms($alarm_data, '告警消息 PHPServer框架监控 '.$ip.' '.$worker_name.' '.((self::CHECK_BUSINESS_TIME_LONG/60)).'分钟内出现 FatalError '.$exit_count.'次 时间:'.date('Y-m-d H:i:s'));
        }
        elseif(9 == $status)
        {
        	$alarm_data = array(
        		'type'=>2,
        		'ip'=>$ip,
        		'worker_name'=>$worker_name,
        		'count'=>$exit_count,
        	);
        	$this->sendSms($alarm_data, '告警消息 PHPServer框架监控 '.$ip.' '.$worker_name.' 业务进程阻塞，阻塞进程数：'.$exit_count.' 时间:'.date('Y-m-d H:i:s'));
        }
        else
        {
        	$alarm_data = array(
        		'type'=>4,
        		'ip'=>$ip,
        		'worker_name'=>$worker_name,
        		'status'=>$status,
        		'count'=>$exit_count,
        	);
            $this->sendSms($alarm_data, '告警消息 PHPServer框架监控 '.$ip.' '.$worker_name.' 进程频繁退出 退出次数'.$exit_count.' 退出状态码：'.$status .' 时间:'.date('Y-m-d H:i:s'));
        }
        
        // 记录这次告警时间
        self::$lastWarningTimeMap[self::WARNING_TOO_MANY_WORKERS_EXIT] = $time_now;
    }
    
    /**
     * 当worker进程成功率低于一定值时触发
     * @param string $worker_name
     * @param float $success_rate
     * @retrun void
     */
    public function onFrameworkLowSuccessRate($worker_name, $success_rate)
    {
        // 不要频繁告警，5分钟告警一次
        $time_now = time();
        if($time_now - self::$lastWarningTimeMap[self::WARNING_FRAMEWORK_lOW_SUCCESS_RATE] < self::WARING_SEND_TIME_LONG)
        {
            return;
        }
        
        $ip = $this->getIp();
        
        $alarm_data = array(
        	'type'=>5,
        	'ip'=>$ip,
        	'worker_name'=>$worker_name,
        	'percentage'=>$success_rate,
        );
        
        $this->sendSms($alarm_data, '告警消息 PHPServer框架监控 '.$ip.' '.$worker_name.' 成功率:'.$success_rate.'% 时间：'.date('Y-m-d H:i:s'));
        
        // 记录这次告警时间
        self::$lastWarningTimeMap[self::WARNING_FRAMEWORK_lOW_SUCCESS_RATE] = $time_now;
    }
    
    /**
     * 业务成功率低于一定值时触发
     * @retrun void
     */
    public function onBusinessLowSuccessRate($module, $interface, $total_count, $fail_count, $success_rate, $time)
    {
        // 量不大时不触发告警 
        if($fail_count < 5 || $total_count < 100)
        {
            return;
        }
        // 已经告警过，则不再告警
        if(self::$lastWarningTimeMap[self::WARNING_STATISTIC_lOW_SUCCESS_RATE] == $time)
        {
            return;
        }
    
        $ip = $this->getIp();
        
        $alarm_data = array(
    		'type'=>6,
    		'ip'=>$ip,
    		'interface'=>"{$module}::{$interface}",
    		'total_count'=>$total_count,
    		'fail_count'=>$fail_count,
    	);
        $this->sendSms($alarm_data, "告警消息 PHPServer业务监控 请求{$module}::{$interface} {$total_count}次，失败{$fail_count}次，成功率{$success_rate}% ip:{$ip} 时间:" . date('Y-m-d H:i:s', $time));
    
        // 记录这次告警时间
        self::$lastWarningTimeMap[self::WARNING_STATISTIC_lOW_SUCCESS_RATE] = $time;
    }
    
    /**
     * 发送短信
     * @param int $phone_num
     * @param string $content
     * @return void
     */
    protected function sendSms($data, $content)
    {
        // 短信告警
        $url = PHPServerConfig::get('workers.Monitor.framework.url');
        $phone_array = explode(',', PHPServerConfig::get('workers.Monitor.framework.phone'));
        $param = PHPServerConfig::get('workers.Monitor.framework.param');
        foreach($phone_array as $phone)
        {
        	$data['phone'] = $phone;
        	if(!$this->sendAlarm($data))
        	{
	            $ch = curl_init();
	            curl_setopt($ch, CURLOPT_URL, $url);
	            curl_setopt($ch, CURLOPT_POST, 1);
	            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(array('num'=>$phone,'content'=>$content) , $param)));
	            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	            $this->notice('send phone:'.$phone.' msg:' . $content. ' send_ret:' .var_export(curl_exec($ch), true));
        	}
        }
    }
    
    protected function sendAlarm($data)
    {
    	$alarm_uri = PHPServerConfig::get('workers.Monitor.framework.alarm_uri');
    	if(!$alarm_uri)
    	{
    		$alarm_uri = 'tcp://10.1.27.12:2015';
    		return false;
    	}
    	$client = stream_socket_client($alarm_uri, $err_no, $err_msg, 1);
    	if(!$client)
    	{
    		$this->notice("sendAlarm fail . $err_msg");
    		return false;
    	}
    	stream_set_timeout($client, 1);
    	$buffer = json_encode($data);
    	$send_len = fwrite($client, $buffer);
    	if($send_len !== strlen($buffer))
    	{
    		$this->notice("sendAlarm fail . fwrite return " . var_export($send_len, true));
    		return false;
    	}
    	//fread($client, 8196);
    	$this->notice($buffer);
    	return true;
    }
    
    /**
     * 获取本地ip，优先获取JumeiWorker配置的ip
     * @param string $worker_name
     * @return string
     */
    public function getIp($worker_name = 'JumeiWorker')
    {
        $ip = $this->getLocalIp();
        
        if(empty($ip) || $ip == '0.0.0.0' || $ip = '127.0.0.1')
        {
        	$ip = gethostbyname(trim(`hostname`));
        }
        
        if(empty($ip) || $ip == '0.0.0.0' || $ip = '127.0.0.1')
        {
            if($worker_name)
            {
                $ip = PHPServerConfig::get('workers.' . $worker_name . '.ip');
            }
            if(empty($ip) || $ip == '0.0.0.0' || $ip = '127.0.0.1')
            {
                $ret_string = shell_exec('ifconfig');
                if(preg_match("/:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/", $ret_string, $match))
                {
                    $ip = $match[1];
                }
            }
        }
        return $ip;
    }
    
    /**
     * 发送邮件
     * @param string $email
     * @param string $content
     */
    protected function sendEmail($email, $content)
    {
        
    }
    
    /**
     * 清理日志目录
     * @param string $dir
     */
    public function clearLogs($dir)
    {
        clearstatcache();
        $time_now = time();
        foreach(glob($dir."/20*-*-*") as $file)
        {
            if(!is_dir($file)) continue;
            $base_name = basename($file);
            $log_time = strtotime($base_name);
            if($log_time === false) continue;
            if(($time_now - $log_time)/self::SECONDS_ONE_DAY >= self::CLEAR_BEFORE_DAYS)
            {
                $this->recursiveDelete($file);
            }
            
        }
    }
    
    /**
     * 递归删除文件
     * @param string $path
     */
    private function recursiveDelete($path)
    {
        return is_file($path) ? unlink($path) : array_map(array($this, 'recursiveDelete'),glob($path.'/*')) == rmdir($path);
    }
    
} 
