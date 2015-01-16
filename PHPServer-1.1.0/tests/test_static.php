<?php

/**
 * 简单的一个测试接口调用统计的脚本,使用方法
 * php client_static.php num1 num2
 * num1：启用进程数 num2:每个进程请求多少次 
 */

require_once 'StatisticClient.php';

//启用多少个进程
$proc_cnt = isset($argv[1]) ? intval($argv[1]) : 10;
//每个进程发送多少请求
$req_cnt = isset($argv[2]) ? intval($argv[2]) : 10000;

//protocol udp
$protocol = 'udp';
$port = 2207;

$pid_array = array();

$time_start = microtime(true);
$suc_cnt = 0;
$fail_cnt = 0;
$j = $proc_cnt;
while($j-- != 0)
{
    $pid = pcntl_fork();
    if($pid > 0)
    {
        $pid_array[$pid] = $pid;
    }
    else
    {
	    $i = $req_cnt;
        // while($i-- > 0)
        while(1)
	    {
	        // === tick 用于记录接口调用起始时间，精确到毫秒===
	        StatisticClient::tick();
	        
	        usleep(rand(1000, 2000000));
	        
    		$module_map = array(
    		        'User'=>'User',
    		        'Order'=>'Order',
    		        'Card'=>'Card'
    		        );
    	    
    		$interface_map = array(
    		        'getUserByUid'=>'getUserByUid',
    		        'getOrderInfo'=>'getOrderInfo',
    		        'getInfo'=>'getInfo',
    		        'getLists'=>'getLists',
    		        'update'=>'update',
    		        'delete'=>'delete',
    		        );
    		
    		$module = array_rand($module_map);
    		$interface = array_rand($interface_map);
    		
    		$msg_array = array(
    		        '参数错误',
    		        '数据库链接超时',
    		        '数据库无法链接，请稍后重试',
    		        '用户不存在',
    		        '网络繁忙，请稍后再试',
    		);
    		$msg = $msg_array[array_rand($msg_array)];
    		
    		$code = rand(11011, 11100);
    		$suc = rand(1,9999)<9997 ? true : false;
    		
    		// === 上报结果 ===
    		$ret = StatisticClient::report($module, $interface, $code, $msg, $suc);
    		
	    }
        print_result();
	    exit("");
    }
}

while(!empty($pid_array))
{
    $pid = pcntl_wait($status);
    if($pid > 0) {
	    unset($pid_array[$pid]);
    }
}

echo "DONE ........";print_result($req_cnt*$proc_cnt);
die;

function print_result($cnt = null)
{
    global $fail_cnt,$time_start,$proc_cnt,$req_cnt;
    if($cnt == null) $cnt = $req_cnt;
    echo "\n", $cnt/(microtime(true) - $time_start), "req/S fail count $fail_cnt\n";
}
