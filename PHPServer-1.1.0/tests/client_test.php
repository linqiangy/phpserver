<?php

/**
 * 简单的一个压力测试脚本,使用方法
 * php client_test.php address content num1 num2
 * num1：启用进程数 num2:每个进程请求多少次 udp:使用udp
 */

if(!isset($argv[1]))
{
    exit("php client_test.php address content num1 num2\n");
}
$address = $argv[1];
$content = isset($argv[2]) ? $argv[2] : 'hello';
//启用多少个进程
$proc_cnt = isset($argv[3]) ? intval($argv[3]) : 10;
//每个进程发送多少请求
$req_cnt = isset($argv[4]) ? intval($argv[4]) : 10000;


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
        while($i-- > 0)
        //while(1)
	    {
    		$socket = stream_socket_client($address);

	        stream_socket_sendto($socket, $content);

    		$response = stream_socket_recvfrom($socket, 102400);
                fclose($socket);
     		if(empty($response))
    		{
       			var_export($response);
        		$fail_cnt++;
        		continue;
    		}
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
