<?php 

/**
 * 
 * phpserver集群业务统计数据提供者
 * @author liangl
 *
 */

class StatisticProvider extends PHPServerWorker
{
    /**
     * 上报ip命令
     * @var int
     */
    const CMD_REPORT_IP = 11;
    
    /**
     * 上报ip结果命令
     * @var int
     */
    const CMD_REPORT_IP_RESULT = 12;
    
    /**
     * 统计相关命令
     * @var int
     */
    const CMD_PROVIDER = 107;
    
    /**
     * 获得统计子命令
     * @var int
     */
    const SUB_CMD_GET_ST = 201;

    /**
     * 获得模块子命令
     * @var int
     */
    const SUB_CMD_GET_MODULES = 202;
    
    /**
     * 获得日志子命令
     * @var int
     */
    const SUB_CMD_GET_LOGS = 203;
    
    /**
     * 获得统计及模块子命令
     * @var unknown_type
     */
    const SUB_CMD_GET_ST_AND_MODULES = 204;
    
    /**
     * 获得各个ip的接口访问量
     * @var unknown_type
     */
    const SUB_CMD_GET_CLIENT_SUMMARY = 205;
    
    /**
     * 获得单个ip接口访问曲线数据
     * @var unknown_type
     */
    const SUB_CMD_GET_CLIENT_DETAIL = 206;
    
    /**
     * 获得客户端原始统计数据
     * @var unknown_type
     */
    const SUB_CMD_GET_CLIENT_RAW = 207;
    
    /**
     * 监听广播ip的udp socket
     * @var resource
     */
    protected $udpBroadSocket = null;
    
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
        $this->event = new $this->eventLoopName();
    
        // 添加accept事件
        $this->event->add($this->mainSocket,  BaseEvent::EV_ACCEPT, array($this, 'accept'));
    
        // 添加管道可读事件
        $this->event->add($this->channel,  BaseEvent::EV_READ, array($this, 'dealCmd'), null, 0, 0);
    
        // 监听一个udp端口，用来广播ip
        $error_no = 0;
        $error_msg = '';
        // 创建监听socket
        $this->udpBroadSocket = @stream_socket_server('udp://0.0.0.0:'.PHPServerConfig::get('workers.'.$this->serviceName.'.port'), $error_no, $error_msg, STREAM_SERVER_BIND);
    
        if($this->udpBroadSocket)
        {
            $this->event->add($this->udpBroadSocket,  BaseEvent::EV_ACCEPT, array($this, 'recvBroadcastUdp'));
        }
    
        // 主体循环
        $ret = $this->event->loop();
        $this->notice("evet->loop returned " . var_export($ret, true));
    
        exit(self::EXIT_UNEXPECT_CODE);
    }
    
    /**
     * 接收Udp数据
     * 如果数据超过一个udp包长，需要业务自己解析包体，判断数据是否全部到达
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function recvBroadcastUdp($socket, $null_one = null, $null_two = null)
    {
        $data = stream_socket_recvfrom($socket , self::MAX_UDP_PACKEG_SIZE, 0, $address);
        // 可能是惊群效应
        if(false === $data || empty($address))
        {
            return false;
        }
    
        $this->currentClientAddress = $address;
        $this->dealProcess($data);
    }
    
    /**
     * 判断包是否都到达
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return JMProtocol::checkInput($recv_str);
    }
    
    /**
     * 处理业务逻辑 查询log 查询统计信息
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $pack_data = new JMProtocol($recv_str);
        $cmd = $pack_data->header['cmd'];
        $sub_cmd = $pack_data->header['sub_cmd'];
        switch($cmd)
        {
            case self::CMD_REPORT_IP:
                $body_data = json_decode($pack_data->body, true);
                $pack_data->header['cmd'] = self::CMD_REPORT_IP_RESULT;
                $pack_data->body = '';
                stream_socket_sendto($this->udpBroadSocket, $pack_data->getBuffer(), 0, $this->currentClientAddress);
                return;
            case self::CMD_PROVIDER:
                switch ($sub_cmd)
                {
                    case self::SUB_CMD_GET_ST_AND_MODULES:
                        $module_interface = json_decode($pack_data->body, true);
                        $module = isset($module_interface['module']) ? $module_interface['module'] : '';
                        $interface = isset($module_interface['interface']) ? $module_interface['interface'] : '';
                        $time = isset($module_interface['time']) ? $module_interface['time'] : '';
                        $pack_data->body = json_encode(array(
                            'modules'   => $this->getModules($module),
                            'statistic' => $this->getStatistic($module, $interface, $time),
                        ));
                        $this->sendToClient($pack_data->getBuffer());
                        return;
                    case self::SUB_CMD_GET_LOGS:
                        $log_param = json_decode($pack_data->body, true);
                        $module = isset($log_param['module']) ? $log_param['module'] : '';
                        $interface = isset($log_param['interface']) ? $log_param['interface'] : '';
                        $start_time = isset($log_param['start_time']) ? $log_param['start_time'] : '';
                        $end_time = isset($log_param['end_time']) ? $log_param['end_time'] : '';
                        $code = isset($log_param['code']) ? $log_param['code'] : '';
                        $msg = isset($log_param['msg']) ? $log_param['msg'] : '';
                        $pointer = isset($log_param['pointer']) ? $log_param['pointer'] : '';
                        $count = isset($log_param['count']) ? $log_param['count'] : 10;
                        $pack_data->body = json_encode($this->getStasticLog($module, $interface , $start_time, $end_time, $code, $msg, $pointer, $count));
                        $this->sendToClient($pack_data->getBuffer());
                        return;
                    case self::SUB_CMD_GET_CLIENT_SUMMARY:
                    	$module_interface = json_decode($pack_data->body, true);
                    	$module = isset($module_interface['module']) ? $module_interface['module'] : '';
                    	$interface = isset($module_interface['interface']) ? $module_interface['interface'] : '';
                    	$time = isset($module_interface['time']) ? $module_interface['time'] : '';
                    	$pack_data->body = json_encode(array(
                    			'statistic' => $this->getClientSummary($module, $interface, $time),
                    	));
                    	$this->sendToClient($pack_data->getBuffer());
                    	return;
                    case self::SUB_CMD_GET_CLIENT_DETAIL:
                    	$module_interface = json_decode($pack_data->body, true);
                    	$module = isset($module_interface['module']) ? $module_interface['module'] : '';
                    	$interface = isset($module_interface['interface']) ? $module_interface['interface'] : '';
                    	$time = isset($module_interface['time']) ? $module_interface['time'] : '';
                    	$ip = isset($module_interface['ip']) ? $module_interface['ip'] : '';
                    	$pack_data->body = json_encode(array(
                    			'statistic' => $this->getClientDetail($module, $interface, $ip, $time),
                    	));
                    	$this->sendToClient($pack_data->getBuffer());
                    	return;
                    case self::SUB_CMD_GET_CLIENT_RAW:
                    	$module_interface = json_decode($pack_data->body, true);
                    	$module = isset($module_interface['module']) ? $module_interface['module'] : '';
                    	$interface = isset($module_interface['interface']) ? $module_interface['interface'] : '';
                    	$time = isset($module_interface['time']) ? $module_interface['time'] : '';
                    	$pack_data->body = json_encode(array(
                    			'statistic' => $this->getClientRaw($module, $interface, $time),
                    	));
                    	$this->sendToClient($pack_data->getBuffer());
                    	return;
                }
                return;
        }
    }
    
    /**
     * 获取模块
     */
    public function getModules($current_module = '')
    {
        clearstatcache();
        $st_dir = SERVER_BASE . 'logs/statistic/st/';
        $modules_name_array = array();
        foreach(glob($st_dir."/*") as $module_file)
        {
            $tmp = explode("/", $module_file);
            $module = end($tmp);
            $modules_name_array[$module] = array();
            if($current_module == $module)
            {
                $st_dir = SERVER_BASE . 'logs/statistic/st/'.$current_module.'/';
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
        }
        return $modules_name_array;
    }
    
    
    /**
     * 日志二分查找法
     * @param int $start_point
     * @param int $end_point
     * @param int $time
     * @param fd $fd
     * @return int
     */
    protected function binarySearch($start_point, $end_point, $time, $fd)
    {
        // 区间很小就直接返回
        if($end_point - $start_point < 200000)
        {
            return $start_point;
        }
        
        // 计算中点
        $mid_point = (int)(($end_point+$start_point)/2);
        
        // 定位文件指针在中点
        fseek($fd, $mid_point-1);
        
        // 读第一行
        $line = fgets($fd);
        if(feof($fd) || false === $line)
        {
            return $start_point;
        }
        
        // 第一行可能数据不全，再读一行
        $line = fgets($fd);
        if(feof($fd) || false === $line || trim($line) == '')
        {
            return $start_point;
        }
        
        // 判断是否越界
        $current_point = ftell($fd);
        if($current_point>=$end_point)
        {
            return $start_point;
        }
        
        // 获得时间
        $tmp = explode("\t", $line);
        $tmp_time = strtotime($tmp[0]);
        
        // 判断时间，返回指针位置
        if($tmp_time > $time)
        {
            return $this->binarySearch($start_point, $current_point, $time, $fd);
        } 
        elseif($tmp_time < $time)
        {
            return $this->binarySearch($current_point, $end_point, $time, $fd);
        }
        else
        {
            return $current_point;
        }
    }
    
    /**
     * 获取指定日志
     * 
     */
    protected function getStasticLog($module, $interface , $start_time = '', $end_time = '', $code = '', $msg = '', $pointer='', $count=100)
    {
        clearstatcache();
        // log文件
        $log_file = SERVER_BASE.'logs/statistic/log/'. (empty($start_time) ? date('Y-m-d') : date('Y-m-d', $start_time));
        if(!is_readable($log_file))
        {
            return array('pointer'=>0, 'data'=>'');
        }
        // 读文件
        $h = fopen($log_file, 'r');
        
        // 如果有时间，则进行二分查找，加速查询
        if($start_time && $pointer == '' && ($file_size = filesize($log_file)) > 2048000)
        {
            $pointer = $this->binarySearch(0, $file_size, $start_time-1, $h);
            $pointer = $pointer < 100000 ? 0 : $pointer - 100000;
        }
        
        // 正则表达式
        $pattern = "/^([\d: \-]+)\t";
        
        if($module && $module != 'PHPServer')
        {
            $pattern .= $module."::";
        }
        else
        {
            $pattern .= ".*::";
        }
        
        if($interface && $module != 'PHPServer')
        {
            $pattern .= $interface."\t";
        }
        else
        {
            $pattern .= ".*\t";
        }
        
        if($code !== '')
        {
            $pattern .= "CODE:$code\t";
        }
        else 
        {
            $pattern .= "CODE:\d+\t";
        }
        
        if($msg)
        {
            $pattern .= "MSG:$msg";
        }
       
        $pattern .= '/';
        
        // 指定偏移位置
        if($pointer >= 0)
        {
            fseek($h, (int)$pointer);
        }
        
        // 查找符合条件的数据
        $now_count = 0;
        $log_buffer = '';
        
        while(1)
        {
            if(feof($h))
            {
                break;
            }
            // 读1行
            $line = fgets($h);
            if(preg_match($pattern, $line, $match))
            {
                // 判断时间是否符合要求
                $time = strtotime($match[1]);
                if($start_time)
                {
                    if($time<$start_time)
                    {
                        continue;
                    }
                }
                if($end_time)
                {
                    if($time>$end_time)
                    {
                        break;
                    }
                }                                    
                // 收集符合条件的log
                $log_buffer .= $line;
                if(++$now_count >= $count)
                {
                    break;
                }
            }
        }
        // 记录偏移位置
        $pointer = ftell($h);
        return array('pointer'=>$pointer, 'data'=>$log_buffer);
    }
    
    /**
     * 获得统计数据
     * @param string $module
     * @param string $interface
     * @param int $start_time
     * @return bool/string
     */
    protected function getStatistic($module, $interface, $start_time='')
    {
        if(empty($module) || empty($interface))
        {
            return '';
        }
        // log文件
        $log_file = SERVER_BASE."logs/statistic/st/{$module}/{$interface}|". ($start_time === '' ? date('Y-m-d') : date('Y-m-d', $start_time));
        return @file_get_contents($log_file);
    }
    
    /**
     * 获得统计数据
     * @param string $module
     * @param string $interface
     * @param int $start_time
     * @return bool/string
     */
    protected function getClientSummary($module, $interface, $start_time='')
    {
    	if(empty($module) || empty($interface))
    	{
    		return '';
    	}
    	// log文件
    	$detail_file = SERVER_BASE."logs/statistic/detail/{$module}/{$interface}-detail|". ($start_time === '' ? date('Y-m-d') : date('Y-m-d', $start_time));
    	// [ip:[suc_cnt,fail_cnt], ip:[suc_cnt,fail_cnt], ..]
    	$ip_data = array();
    	$ret =  @file_get_contents($detail_file);
    	if($ret)
    	{
    		$tmp = explode("\n", $ret);
    		foreach($tmp as $line)
    		{
    			if(!trim($line))
    			{
    				continue;
    			}
    			$tmp2 = explode("\t", $line);
    			$time = $tmp2[0];
    			$detail = json_decode($tmp2[1], true);
    			if($detail)
    			{
    				foreach($detail as $ip=>$count_data)
    				{
    					if(!isset($ip_data[$ip]))
    					{
    						$ip_data[$ip] = array(0,0);
    					}
    					$ip_data[$ip][0] += $count_data[0];
    					$ip_data[$ip][1] += $count_data[1];
    				}
    			}
    		}
    	}
    	return $ip_data;
    }
    
    protected function getClientDetail($module, $interface, $ip, $start_time='')
    {
    	if(empty($module) || empty($interface) || empty($ip))
    	{
    		return '';
    	}
    	// log文件
    	$detail_file = SERVER_BASE."logs/statistic/detail/{$module}/{$interface}-detail|". ($start_time === '' ? date('Y-m-d') : date('Y-m-d', $start_time));
    	// [time:[suc_cnt,fail_cnt], time:[suc_cnt,fail_cnt], ..]
    	$ip_data = array();
    	$ret =  @file_get_contents($detail_file);
    	if($ret)
    	{
    		$tmp = explode("\n", $ret);
    		foreach($tmp as $line)
    		{
    			if(!trim($line))
    			{
    				continue;
    			}
    			$tmp2 = explode("\t", $line);
    			$time = $tmp2[0];
    			$detail = json_decode($tmp2[1], true);
    			if(!isset($detail[$ip]))
    		    {
    				continue;
    		    }
    	        foreach($detail as $ip=>$count_data)
    			{
    			    if(!isset($ip_data[$time]))
    				{
    					$ip_data[$time] = array(0,0);
    				}
    				$ip_data[$time][0] += $count_data[0];
    				$ip_data[$time][1] += $count_data[1];
    			}
    		}
    	}
    	return $ip_data;
    }
    
    protected function getClientRaw($module, $interface, $start_time='')
    {
    	if(empty($module) || empty($interface))
    	{
    		return '';
    	}
    	// log文件
    	$detail_file = SERVER_BASE."logs/statistic/detail/{$module}/{$interface}-detail|". ($start_time === '' ? date('Y-m-d') : date('Y-m-d', $start_time));
    	return @file_get_contents($detail_file);
    }
}
