<?php

/**
 * 
 * AlarmService
 * @author liangl
 * 
 */

class AlarmService extends PHPServerWorker
{
	// [type:1, phone:phone, ip:xx]
	const TYPE_MAIN_PROCESS_EXIT = 1;
	
	// [type:2, phone:phone, ip:ip, worker_name:'worker_name', count:'count']
	const TYPE_WORKER_BLOCKED = 2;
	
	// [type:3, phone:phone, ip:ip, worker_name:worker_name, count:count]
	const TYPE_WORKER_FATAL_RROR = 3;
	
	// [type:4 phone:phone, ip:ip, worker_name:'worker_name', status:status, count:count]
	const TYPE_WORKER_EXIT = 4;
	
	// [type:5, phone:phone, ip:ip, worker_name:worker_name, percentage:percentage]
	const TYPE_FRAME_SUCCES_RATE = 5;
	
	// [type:6, phone:phone, ip:ip, interface:interface, total_count:total_count, fail_count:fail_count]
	const TYPE_WORKER_SUCCESS_RATE = 6;
	
	// [type:7 phone:phone, ip:ip, target_ip:target:ip]
	const TYPE_CLIENT_CONNECTION_FAIL = 7;
	
	/**
	 * [phone1=>type1=>['ip'=>array('ip1','ip2'), 'last_send_time'=>xxxxx],type2=>[...],..
	 *  phone2=>..
	 * ]
	 * @var array
	 */
	protected $alarmInfo = array();
	
	/**
	 * [type:1, ip:'xx', phone:phone, .. ]
	 * @see PHPServerWorker::dealInput()
	 */
    public function dealInput($recv_str)
    {
    	$data = json_decode($recv_str, true);
    	if(empty($data))
    	{
    		$this->notice("json_decode($recv_str) fail");
    		return 0;
    	}
    	
    	$this->notice($recv_str);
    	
    	$time_now = time();
    	
    	$type = $data['type'];
    	$phone = $data['phone'];
    	$ip = $data['ip'];
    	if(empty($this->alarmInfo[$phone]))
    	{
    		$this->alarmInfo[$phone] = array();
    		//[type:1, phone:phone, ip:xx]
    		$this->alarmInfo[$phone][1] = array('last_send_time'=>0, 'ip'=>array());
    		//[type:2, phone:phone, ip:ip, worker_name:'worker_name', count:'count']
    		$this->alarmInfo[$phone][2] = array('last_send_time'=>0, 'ip'=>array(), 'worker_name'=>array(), 'count'=>0);
    		//[type:3, phone:phone, ip:ip, worker_name:worker_name, count:count]
    		$this->alarmInfo[$phone][3] = array('last_send_time'=>0, 'ip'=>array(), 'worker_name'=>array(), 'count'=>0);
    		//[type:4 phone:phone, ip:ip, worker_name:'worker_name', status:status, count:count]
    		$this->alarmInfo[$phone][4] = array('last_send_time'=>0, 'ip'=>array(), 'worker_name'=>array(), 'status'=>array(), 'count'=>0);
    		//[type:5, phone:phone, ip:ip, worker_name:worker_name, percentage:percentage]
    		$this->alarmInfo[$phone][5] = array('last_send_time'=>0, 'ip'=>array(), 'worker_name'=>array(), 'percentage'=>array());
    		// [type:6, phone:phone, ip:ip, interface:interface, total_count:total_count, fail_count:fail_count]
    		$this->alarmInfo[$phone][6] = array('last_send_time'=>0, 'ip'=>array(), 'interface'=>array(), 'total_count'=>0, 'fail_count'=>0);
    		// [type:7 phone:phone, ip:ip, target_ip:target:ip]
    		$this->alarmInfo[$phone][7] = array('last_send_time'=>0, 'ip'=>array(), 'target_ip'=>array());
    	}
    	
    	$this->alarmInfo[$phone][$type]['ip'][$ip]=$ip;
    	switch ($type)
    	{
    		case self::TYPE_MAIN_PROCESS_EXIT:
    			break;
    		case self::TYPE_WORKER_BLOCKED:
    			$this->alarmInfo[$phone][$type]['worker_name'][$data['worker_name']]=$data['worker_name'];
    			$this->alarmInfo[$phone][$type]['count'] += $data['count'];
    			break;
    		case self::TYPE_WORKER_FATAL_RROR:
    			$this->alarmInfo[$phone][$type]['worker_name'][$data['worker_name']]=$data['worker_name'];
    			$this->alarmInfo[$phone][$type]['count'] += $data['count'];
    			break;
    		case self::TYPE_WORKER_EXIT:
    			$this->alarmInfo[$phone][$type]['worker_name'][$data['worker_name']]=$data['worker_name'];
    			$this->alarmInfo[$phone][$type]['count'] += $data['count'];
    			$this->alarmInfo[$phone][$type]['status'][$data['status']]=$data['status'];
    			break;
    		case self::TYPE_FRAME_SUCCES_RATE:
    			$this->alarmInfo[$phone][$type]['worker_name'][$data['worker_name']]=$data['worker_name'];
    			$this->alarmInfo[$phone][$type]['percentage'][]=$data['percentage'];
    			break;
    		case self::TYPE_WORKER_SUCCESS_RATE:
    			$this->alarmInfo[$phone][$type]['interface'][$data['interface']]=$data['interface'];
    			$this->alarmInfo[$phone][$type]['total_count'] += $data['total_count'];
    			$this->alarmInfo[$phone][$type]['fail_count'] += $data['fail_count'];
    			break;
    		case self::TYPE_CLIENT_CONNECTION_FAIL:
    			$this->alarmInfo[$phone][$type]['target_ip'][$data['target_ip']]=$data['target_ip'];
    			break;
    		default :
    			$this->notice("UNKNOW TYPE $recv_str");
    	}
        return 0;
    }

    public function dealProcess($recv_str)
    {
    	$this->sendToClient('ok');
    	$this->tryToSend();
    }
    
    protected function tryToSend()
    {
    	$time_now = time();
    	foreach($this->alarmInfo as $phone=>$info)
    	{
    		foreach($info as $type=>$type_info)
    		{
    			if($time_now - $type_info['last_send_time'] >= 5*60 && !empty($type_info['ip']))
    			{
    				switch($type)
    				{
    					case 1:
    						$this->alarmInfo[$phone][1]=array('last_send_time'=>$time_now, 'ip'=>array());
    						break;
    					case 2:
    						$this->alarmInfo[$phone][2] = array('last_send_time'=>$time_now, 'ip'=>array(), 'worker_name'=>array(), 'count'=>0);
    						break;
    					case 3:
    						$this->alarmInfo[$phone][3] = array('last_send_time'=>$time_now, 'ip'=>array(), 'worker_name'=>array(), 'count'=>0);
    						break;
    					case 4:
    						$this->alarmInfo[$phone][4] = array('last_send_time'=>$time_now, 'ip'=>array(), 'worker_name'=>array(), 'status'=>array(), 'count'=>0);
    						break;
    					case 5:
    						$this->alarmInfo[$phone][5] = array('last_send_time'=>$time_now, 'ip'=>array(), 'worker_name'=>array(), 'percentage'=>array());
    						break;
    					case 6:
    						$this->alarmInfo[$phone][6] = array('last_send_time'=>$time_now, 'ip'=>array(), 'interface'=>array(), 'total_count'=>0, 'fail_count'=>0);
    						break;
    					case 7:
    						$this->alarmInfo[$phone][7] = array('last_send_time'=>$time_now, 'ip'=>array(), 'target_ip'=>array());
    						break;
    				}
    				$this->sendMsg($type, $phone, $type_info);
    			}
    		}
    	}
    }
    
    protected function sendMsg($type, $phone, $info)
    {
    	$url ='http://sms.int.jumei.com/send';
    	$param = array(
    			'channel' => 'monternet',
    			'key'     => 'notice_rt902pnkl10udnq',
    			'task'    => 'int_notice',
    	);
    	$content = '';
    	switch ($type)
    	{
    		case self::TYPE_MAIN_PROCESS_EXIT:
    			$content = 'PHPServer框架告警 主进程意外退出 ip:'.implode(',', $info['ip']).' 时间：'.date('Y-m-d H:i:s');
    			break;
    		case self::TYPE_WORKER_BLOCKED:
    			$content = 'PHPServer业务告警 业务进程'. implode(',', $info['worker_name']) ."长时间阻塞，阻塞进程总数:{$info['count']} ip:" . implode(',', $info['ip']) . " 时间：".date('Y-m-d H:i:s');
    			break;
    		case self::TYPE_WORKER_FATAL_RROR:
    			$content = 'PHPServer业务告警 业务进程 '. implode(',', $info['worker_name'])." 5分钟内共发生FatalError {$info['count']}次 ip:".implode(',', $info['ip']). " 时间：".date('Y-m-d H:i:s');
    			break;
    		case self::TYPE_WORKER_EXIT:
    			$content = 'PHPServer框架告警 业务进程'. implode(',', $info['worker_name'])."共退出{$info['count']}次，退出状态码:".implode(',', $info['status'])." ip:".implode(',', $info['ip']). " 时间：".date('Y-m-d H:i:s');
    			break;
    		case self::TYPE_FRAME_SUCCES_RATE:
    			$content = 'PHPServer框架告警 业务'. implode(',', $info['worker_name'])." 成功率：".round(array_sum($info['percentage'])/count($info['percentage']), 2)."% ip:".implode(',', $info['ip']). " 时间：".date('Y-m-d H:i:s');
    			break;
    		case self::TYPE_WORKER_SUCCESS_RATE:
    			$content = 'PHPServer业务告警 调用接口'. implode(',', $info['interface'])." 共{$info['total_count']}次，失败{$info['fail_count']}次，成功率：".round((($info['total_count']-$info['fail_count'])*100)/$info['total_count'], 2)."% 服务端ip:".implode(',', $info['ip']). " 时间：".date('Y-m-d H:i:s');
    			break;
    		case self::TYPE_CLIENT_CONNECTION_FAIL:
    			$content = 'PHPServer客户端告警 客户端 '.implode(',', $info['ip']).' 连接 服务端 '.implode(',', $info['target_ip']).'失败 时间：'.date('Y-m-d H:i:s');
    			break;
    		default :
    			$this->notice("UNKNOW TYPE sendMsg($phone, ".json_encode($info).") fail");
    			return;
    	}
    	$ch = curl_init();
    	curl_setopt($ch, CURLOPT_URL, $url);
    	curl_setopt($ch, CURLOPT_POST, 1);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(array('num'=>$phone,'content'=>$content) , $param)));
    	curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    	$this->notice('send phone:'.$phone.' msg:' . $content. ' send_ret:' .var_export(curl_exec($ch), true));
    }
    
    /**
     * 每隔一定时间触发一次
     * @see PHPServerWorker::onAlarm()
     */
    protected function onAlarm()
    {
    	$this->tryToSend();
    } 
}
