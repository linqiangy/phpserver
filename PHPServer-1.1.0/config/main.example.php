<?php

return array(

    'workers' => array(
 
        // 聚美通用 Worker
        'JumeiWorker' => array(
            'protocol'              => 'tcp',    // [必填]tcp udp
            'port'                  => 2201,     // [必填]监听的端口
            'child_count'           => 5,       // [必填]worker进程数 注意:每个进程大概占用30M内存，总worker数量不要超过800个
            'ip'                    => "0.0.0.0",// [选填]绑定的ip，注意：生产环境要绑定内网ip 不配置默认是0.0.0.0
            'recv_timeout'          => 10000,    // [选填]从客户端接收数据的超时时间          不配置默认1000毫秒
            'process_timeout'       => 30000,    // [选填]业务逻辑处理超时时间               不配置默认30000毫秒
            'send_timeout'          => 1000,     // [选填]发送数据到客户端超时时间            不配置默认1000毫秒
            'persistent_connection' => false,    // [选填]是否是长连接                      不配置默认是短链接（短连接每次请求后服务器主动断开）
            'max_requests'          => 1000,     // [选填]进程接收多少请求后退出              不配置默认是0，不退出
            'framework'             => array(    // [选填]业务框架相关配置
                'path'         => __dir__ . '/../../Services/Framework',
                'app_name'     => 'cart_service',
            ),
        ),
        
        // PromoCard
        'PromoCard' => array(
            'protocol'              => 'tcp',    // [必填]tcp udp
            'port'                  => 2202,     // [必填]监听的端口
            'child_count'           => 5,       // [必填]worker进程数 注意:每个进程大概占用30M内存，总worker数量不要超过800个
            'ip'                    => "0.0.0.0",// [选填]绑定的ip，注意：生产环境要绑定内网ip 不配置默认是0.0.0.0
            'recv_timeout'          => 10000,    // [选填]从客户端接收数据的超时时间          不配置默认1000毫秒
            'process_timeout'       => 30000,    // [选填]业务逻辑处理超时时间               不配置默认30000毫秒
            'send_timeout'          => 1000,     // [选填]发送数据到客户端超时时间            不配置默认1000毫秒
            'persistent_connection' => false,    // [选填]是否是长连接                      不配置默认是短链接（短连接每次请求后服务器主动断开）
            'max_requests'          => 1000,     // [选填]进程接收多少请求后退出              不配置默认是0，不退出
            'worker_class'          => 'JmTextWorker',// worker使用的类
            'bootstrap'             => '../../bootstrap.php', // 进程初始化时调用一次，可以在这里做些全局的事情，例如设置autoload
        ),

        // RedEnvelop
        'RedEnvelop' => array(
            'protocol'              => 'tcp',    // [必填]tcp udp
            'port'                  => 2203,     // [必填]监听的端口
            'child_count'           => 5,       // [必填]worker进程数 注意:每个进程大概占用30M内存，总worker数量不要超过800个
            'ip'                    => "0.0.0.0",// [选填]绑定的ip，注意：生产环境要绑定内网ip 不配置默认是0.0.0.0
            'recv_timeout'          => 10000,    // [选填]从客户端接收数据的超时时间          不配置默认1000毫秒
            'process_timeout'       => 30000,    // [选填]业务逻辑处理超时时间               不配置默认30000毫秒
            'send_timeout'          => 1000,     // [选填]发送数据到客户端超时时间            不配置默认1000毫秒
            'persistent_connection' => false,    // [选填]是否是长连接                      不配置默认是短链接（短连接每次请求后服务器主动断开）
            'max_requests'          => 1000,     // [选填]进程接收多少请求后退出              不配置默认是0，不退出
            'worker_class'          => 'JmTextWorker',// worker使用的类
            'bootstrap'             => '../../bootstrap.php',// 进程初始化时调用一次，可以在这里做些全局的事情，例如设置autoload
        ),
                    
        // Services_Product 联系人 姚剑 首长
        'ProductLib' => array(                                          // 注意：键名固定为服务名
            'protocol'              => 'tcp',                           // 固定tcp
            'port'                  => 9091,                            // 每组服务一个端口
            'child_count'           => 5,                              // 启动多少个进程提供服务
            'recv_timeout'          => 10000,                           // [选填]从客户端接收数据的超时时间          不配置默认1000毫秒
            'process_timeout'       => 30000,                           // [选填]业务逻辑处理超时时间               不配置默认30000毫秒
            'send_timeout'          => 1000,                            // [选填]发送数据到客户端超时时间            不配置默认1000毫秒
            'persistent_connection' => false,                           // [选填]是否是长连接                      不配置默认是短链接（短连接每次请求后服务器主动断开）
            'max_requests'          => 1000,                            // [选填]进程接收多少请求后退出              不配置默认是0，不退出
            'worker_class'          => 'ThriftWorker',
            'provider'              => __DIR__ . '/../../../Provider',  // 这里是thrift生成文件所放目录,可以是绝对路径
            'handler'               => __DIR__ . '/../../../Handler',   // 这里是对thrift生成的Provider里的接口的实现
            'bootstrap'             => __DIR__ . '/../../../init.php',  // 进程启动时会载入这个文件，里面可以做一些autoload等初始化工作
        ),

        // 统计接口调用结果 只开一个进程 已经配置好，不用设置
        'StatisticWorker' => array(
            'protocol'              => 'udp',
            'port'                  => 20205,
            'child_count'           => 1,
        ),
        
        // 查询接口调用结果 只开一个进程 已经配置好，不用再配置
        'StatisticGlobal' => array(
            'protocol'              => 'tcp',
            'port'                  => 20203,
            'child_count'           => 1,
        ),

        // 查询接口调用结果 只开一个进程 已经配置好，不用再配置
        'StatisticProvider' => array(
            'protocol'              => 'tcp',
            'port'                  => 20204,
            'child_count'           => 1,
        ),
            
        // 监控server框架的worker 只开一个进程 framework里面需要配置成线上参数
        'Monitor' => array(
            'protocol'              => 'tcp',
            'port'                  => 20305,
            'child_count'           => 1,
            'framework'             => array(
                 'phone'   => '15551251335',      // 告警电话
                 'url'     => 'http://xxx.xxx',   // 发送短信调用的url 上线时使用下面线上的配置
                 //'url'     => 'http://sms.int.jumei.com/send',  // 发送短信调用的url
                 'param'   => array(                            // 发送短信用到的参数
                     'channel' => 'monternet',                    
                     'key'     => 'notice_rt902pnkl10udnq',                
                     'task'    => 'int_notice',      
                 ),
                 'min_success_rate' => 98,                    // 框架层面成功率小于这个值时触发告警
                 'max_worker_normal_exit_count' => 1000,      // worker进程退出（退出码为0）次数大于这个值时触发告警
                 'max_worker_unexpect_exit_count' => 10,      // worker进程异常退出（退出码不为0）次数大于这个值时触发告警
             )
        ), 
        
        // [开发环境用，生产环境可以去掉该项]耗时任务处理，发送告警短信 邮件，监控master进程是否退出,开发环境监控文件更改等
        'FileMonitor' => array(
            'protocol'              => 'udp',
            'port'                  => 10203,
            'child_count'           => 1,
        ),
            
        // [开发环境用，生产环境可以去掉该项]rpc web测试工具
        'TestClientWorker' => array(
            'protocol'              => 'tcp',
            'port'                  => 30303,
            'child_count'           => 1,
        ),
        
        // [开发环境用，生产环境可以去掉该项]thrift rpc web测试工具
        'TestThriftClientWorker' => array(
            'protocol'              => 'tcp',
            'port'                  => 30304,
            'child_count'           => 1,
        ),
                    
        // 一个定时任务worker
        /* 'TimerWorker' => array(
            'worker_class'         => 'TaskWorker',
            'heart_detection'      => false,                           // 脚本不要加心跳检测
            'child_count'          => 1,                               // 启动多少子进程
            'bootstrap'            => __DIR__.'/../../bootstrap.php',  // 业务入口，任务引导脚本 需要实现 on_start on_stop on_time 函数
            'time_interval_ms'     => 1000,                            // 单位毫秒:多少毫秒运行一次on_time函数
        ), */
        
        // Thrift Worker
        'ThriftWorker' => array(
            'protocol'              => 'tcp',                               // 固定tcp
            'port'                  => 9090,                                // 每组服务一个端口
            'child_count'           => 5,                                   // 启动多少个进程提供服务
            'persistent_connection' => true,                                // thrift默认使用长链接
            'provider'              => '/home/demo/service_demo/Provider',  // 这里是thrift生成文件所放目录,可以是绝对路径
            'handler'               => '/home/demo/service_demo/Handler',   // 这里是对thrift生成的Provider里的接口的实现
            'bootstrap'             => '/home/demo/service_demo/bootstrap.php', // 进程启动时会载入这个文件，里面可以做一些autoload等初始化工作
            'max_requests'          => 1000,                                // 进程接收多少请求后退出
            'worker_class'          => 'ThriftWorker',                      // 说明是Thrfit服务
        ),
    ),
    
    'ENV'          => 'dev', // dev or production
    'worker_user'  => '', //运行worker的用户,正式环境应该用低权限用户运行worker进程

    // 数据签名用私匙
    'rpc_secret_key'    => '769af463a39f077a0340a189e9c1ec28',
    
    // 日志追踪 trace_log 日志目录
    'trace_log_path'    => '/home/logs/monitor',
    // 异常监控 exception_log 日志目录
    'exception_log_path'=> '/home/logs/monitor',
    // 是否开启日志追踪监控
    'trace_log_on'      => true,
    // 是否开启异常监控
    'exception_log_on'  => true,
    // 日志追踪采样，10代表 采样率1/10, 100代表采样率1/100
    'trace_log_sample'  => 10,
);
