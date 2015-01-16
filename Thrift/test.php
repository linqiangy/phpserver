<?php
// 设置vendor根目录
define('VENDOR_ROOT', __DIR__ . '/../');
// 类自动加载
require_once VENDOR_ROOT . '/Bootstrap/Autoloader.php';
\BootStrap\Autoloader::instance()->init();

// 这里是老的传配置的方法，新的配置方法参见[PHP Service 客户端使用实例](http://wiki.int.jumei.com/index.php?title=PHP_Service_%E5%AE%A2%E6%88%B7%E7%AB%AF%E4%BD%BF%E7%94%A8%E5%AE%9E%E4%BE%8B)
$config = array(
    'OneProject' => array(                               // 一个项目下可以提供多个服务的调用 
        'nodes' => array(
            '127.0.0.3:9090'                             // 与服务端配置的ip端口相同，可配置多个ip端口
        ),
        'provider' => '/home/demo/client_demo/Provider', // 这里是thrift生成文件所放目录
        'protocol' => 'binary',                          // binary or compact or json
        'timeout'  => 20,                                // 超时时间 单位秒
    ),
    'TwoProject' => array(                                 
        'nodes' => array(
            '127.0.0.2:9090'                             // 与服务端配置的ip端口相同，可配置多个ip端口
        ),
        'protocol' => 'text',                            // 这里text是文本协议（PHP-RPC的服务支持双协议）,文本协议是与Thrift无关的协议，可以不要provider
        'timeout'  => 20,
    ),
);
// 服务端节点配置
\Thrift\Client::config($config);
// 告警手机配置
\Thrift\Client::configAlarmPhone('1333333333,1444444444');

/* \Thrift\Client::extConfig(
 array(
                 'monitor_log_path' => '/home/logs/monitor',  // 统一监控日志目录
                 'trace_log_path' => '/home/logs/monitor',    // 日志追踪目录
                 'exception_log_path' => '/home/logs/monitor',// 异常日志目录
                 'alarm_phone' => '15555555555,13333333333',  // 告警配置，作用同\Thrift\Client::configAlarmPhone('15555555555,13333333333');
 )
); */

// ==以上业务框架入口处配置一次即可==

// ==== thrfit 协议调用 ====  调用OneProject下的 User 服务
$user_client = \Thrift\Client::instance('OneProject.User');
$ret1 = $user_client->GetUserInfo(201);
var_dump($ret1);

// ===== 文本协议调用 ===== 调用TwoProject下的 Order 服务
$order_client = \Thrift\Client::instance('TwoProject.Order');
$ret2 = $order_client->getOrderInfo(201);
var_dump($ret2);

