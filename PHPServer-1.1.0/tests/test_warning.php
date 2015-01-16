<?php

/**
 * 测试告警脚本
 */
date_default_timezone_set("Asia/Shanghai");

$port = isset($_argv[1]) ? $argv[1] : 2201;
$protocol = isset($_argv[2]) ? $argv[2] : 'tcp';
while(1)
{
    //只链接，不发送数据
	$socket = stream_socket_client("{$protocol}://0.0.0.0:{$port}");
}
