# Phpserver

版本：1.1.8
发布日期：2014-12-23
特性更新：
2014-12-23 禁用libevent；支持客户端来源统计；告警服务；支持鉴权；支持HTTP文件上传（HttpFileReceiver）
2014-11-20 backlog默认10240
2014-10-14 FatalError通过异常机制返回给前端
2014-09-23 JMTextWorker、ThriftWorker支持setRequestInfo;支持FatalErr在监控中查看
2014-07-28 启动过程中向终端输出判断终端是否关闭
2014-07-17 trace_log $ctx 是对象时报FatalError bug fix
2014-07-15 去掉老的mnlogger埋点日志 上下文传appname RPC_SS、HTTP_SERVICE_SS增加参数
2014-07-13 支持 mnlogger app_name配置以及是否开启mnlogger配置
2014-07-08 支持owl_context上下文,支持日志追踪、异常记录日志,JMTextWorker、ThriftWorker加入RpcBusinessException
2014-06-06 Thrift支持Thrift、文本Text双协议
服务端开发示例
===================
见[PHP Service 开发实例](http://wiki.int.jumei.com/index.php?title=PHP_Service_%E5%BC%80%E5%8F%91%E5%AE%9E%E4%BE%8B)
1) PHPServer简介v1.1
===========================
__PHPServer 用PHP写的server框架，支持libevent、libev、libuv等事件轮询库，支持多进程、支持Inotify文件监控及自动更新、支持server平滑重启、支持PHP文件语法检查等特性。目前实现了http协议和fastcgi协议，可替代nginx+php-fpm，性能比较强悍。.__
2) 代码库
=================================
####_此处代码库基于mercurial_####
+ 申请项目代码库权限.项目代码管理[https://hg.int.jumei.com](https://hg.int.jumei.com)
+ 代码库详细配置和操作参考[https://echo.int.jumei.com/projects/panda/wiki/代码库](https://echo.int.jumei.com/projects/panda/wiki/代码库)
+ 获取您的service代码库
`hg clone https://hg.int.jumei.com/Commons/PHPServer_Group/PHPServer` PHPServer1.0
`hg clone https://hg.int.jumei.com/Arch_Groups/RPC-PHP/parrot-phpserver` PHPServer2.0
3) 目录结构
=======
PHPServer-+
|--bin-+
| |--serverd //启动文件 命令: serverd {start|stop|restart|reload}
|
|--config-+
| |--main.php //配置文件 配置worker名、端口、进程数等
|
|--core-+ //Server核心文件目录
| |--events-+ //事件轮询库目录
| | |--interfaces.php
| | |--Libev.php
| | |--Libevent.php
| | |--Libuv.php
| | |--Select.php
| |--Server.php //Server类
| |--Worker.php //Worker类
|
|--plugins //server用到的一些插件
|
|--protocol-+ //协议相关目录
| |--FastCGI.php
| |--HTTP.php
| |--JMProtocol.php
|
|--workers-+ //业务的worker类继承 需要继承Worker类 需要业务自己实现
| |--RpcWorker.php
| |--StatisticWorker.php
|
|
|--docs //文档
|
|--test //测试
|
4) 安装、部署、启动
=======
- 将PHPServer目录放到任意位置，一般业务目录与PHPServer目录放在同一个目录即可
- 安装所须的模块或者扩展
- posix 必须 进程控制相关 PHP默认都会安装
- pcntl 必须 进程控制相关 PHP默认是关闭的 需要用 --enable-pcntl 重新编译PHP
- libevent 生产环境必须 事件轮询库 PHP默认不会安装 用命令 pecl install libevent 安装
- proctitle 可选 设置进程名称 PHP默认不会安装但php5.5以上版本原生支持了该方法 用命令pecl install proctitle安装
- inotify 可选 监控文件更新 PHP默认不会安装 注意：mac系统不支持Inotify 用命令pecl install inotify安装
- 配置参考:
config/main.php
<pre>
return array(
'workers' => array(
// 聚美通用 Worker
'JumeiWorker' => array(
'protocol' => 'tcp', // [必填]tcp udp
'port' => 2201, // [必填]监听的端口
'child_count' => 10, // [必填]worker进程数 注意:每个进程大概占用30M内存，总worker数量不要超过800个
'ip' => "0.0.0.0",// [选填]绑定的ip，注意：生产环境要绑定内网ip 不配置默认是0.0.0.0
'recv_timeout' => 1000, // [选填]从客户端接收数据的超时时间 不配置默认1000毫秒
'process_timeout' => 30000, // [选填]业务逻辑处理超时时间 不配置默认30000毫秒
'send_timeout' => 1000, // [选填]发送数据到客户端超时时间 不配置默认1000毫秒
'persistent_connection' => true, // [选填]是否是长连接 不配置默认是短链接（短连接每次请求后服务器主动断开）
'max_requests' => 1000, // [选填]进程接收多少请求后退出 不配置默认是0，不退出
'framework' => array( // [选填]业务框架相关配置
'path' => __dir__ . '/../../Framework',
),
),
//统计接口调用结果
'StatisticWorker' => array(
'protocol' => 'udp', // tcp udp
'port' => 2207,
'child_count' => 1,
),
),
);
</pre>
- 配置说明
- protocol [必填]所用传输层协议，目前支持tcp udp
- port [必填]对外服务的端口
- child_count [必填]创建多少worker进程，可遵循php-fpm进程数配置，总worker数量不要超过800个
- ip [选填]绑定的ip，注意：生产环境要绑定内网ip 不配置默认是0.0.0.0
- recv_timeout [选填]从客户端接收数据的超时时间 不配置默认1000毫秒
- process_timeout [选填]业务逻辑处理超时时间 不配置默认30000毫秒
- persistent_connection [选填]是否是长连接 不配置默认是短链接（短连接:每次请求后服务器主动断开 长连接:每次请求后一般是客户端主动断开）
- send_timeout [选填]发送数据到客户端超时时间 不配置默认1000毫秒
- max_requests [选填]进程接收多少请求后退出 不配置默认是0，不退出
- framework [选填]业务框架相关配置
- 启动停止
- 启动 ./bin/serverd start
- 停止 ./bin/serverd stop
- 重启 ./bin/serverd restart
- 平滑重启 ./bin/serverd reload
- 强制杀死所有进程 ./bin/serverd kill
- 查看服务状态 ./bin/serverd status
- 其他
ulimit -n 65535
5) 开发调试
===========
- 开发rpc服务时可以通过http://ip:30303测试rpc服务的连通性,例如[http://192.168.20.23:30303](http://192.168.20.23:30303)，也可用于rpc服务开发调试页面
- PHPServer框架自身支持文件监控和自动更新（除了PHPServer自身文件），所以更改文件后无需手动重启服务
- 开发过程中可以直接用echo printr var_dump 等语句打印输出，打印结果会现显示在终端（终端要一直保持，不要关闭）
- 特别注意的是：使用以上打印语句，当终端关闭时，打印会引发EIO错误，导致进程退出
6) 对业务开放的接口
=======
- Server框架与业务框架通过workers目录下的worker文件作为入口文件进行交互
- workers目录下的Worker类必须继承自core/Worker.php中的Worker类，并且必须实现以下两个方法
<pre>
/**
*
* 判断当前请求数据是否全部到达
* 由于TCP/UDP分片等，数据可能不会一次全部到达，
* 需要业务根据自己所使用协议判断数据完整性
*
* @param string $data_str 收到的数据包
* @return int/false 0:数据收全了 >0:数据没收全 false:无法解析这个数据包
*
**/
Worker::dealInput($data_str);
/**
*
* 每个请求业务逻辑处理部分
* 当数据全部接收完，即Worker::dealInput()返回0时自动调用
* 业务根据自己使用的协议解析包的内容，做相应逻辑处理
* 例如根据解析的参数调用相应的处理函数
*
* @param string $data_str 收到的数据包
* @return void
*
**/
Worker::dealProcess($data_str);
</pre>
- 一般Worker类做的工作只是协议的解析与调用业务框架的请求分发处理函数，由业务框架请求分发处理函数调用相应的业务逻辑
- 当协议确定后用户的Worker类一般就不会做变动，业务逻辑的更改只在业务框架中做相应的改动
- 开发示例 ：WebServiceWorker(通过http协议提供线上的WebServer服务),用来替代nginx+php-fpm架构的webservice
workers/WebServiceHttp.php
<pre>
&lt;?php
//解析http协议类
include_once SERVER_BASE.'protocol/HTTP.php';
/**
*
* 使用http协议提供webservice服务
*
* @author liangl3
*
** /
class WebServiceHttp extends Worker
{
/**
* 处理http协议，判断数据包是否都已经收到
* @see Worker::dealInput()
** /
public function dealInput($recv_str)
{
return Http::input($recv_str);
}
/**
* webservice逻辑处理
* @see Worker::dealProcess()
** /
public function dealProcess($recv_str)
{
ob_start();
//解析http协议
$data = Http::decode($recv_str);
//更改工作目录
chdir('your_jumei_webservice_dir/');
try{
//载入webservice入口文件rpc.php
include 'your_jumei_webservice_dir/rpc.php';
}
catch (Exception $e)
{
echo $e->getMessage();
}
//改回工作目录
chdir(SERVER_BASE);
//发送数据给前端
$ret = $this->sendToClient(Http::encode(ob_get_clean()));
}
}
</pre>
your_jumei_webservice_dir/rpc.php
<pre>
&lt;?php
require_once '/your_jumei_webservice_dir/RpcJumeiServer.php';
$rpcServer = new RpcJumeiServer();
$rpcServer->run();
</pre>
- 增加配置项
<pre>
'WebServiceHttp' => array(
'protocol' => 'tcp', // tcp udp
'port' => 2207,
'child_count' => 100,
'requests_per_child' => 100000,
'recv_timeout' => 2000, // 接收数据的超时时间 毫秒
),
</pre>
- 开发示例说明
- WebServiceHttp继承core/Worker.php中的Worker类
- dealInput实现应用层协议解析，判断数据是否全部到达
- dealProcess方法中include了rpc.php入口文件，因为每次请求都会包含rpc.php，所以rpc.php代码中不要包含类及函数的定义
- 增加对应配置在./config/main.php中
7) 开发规范
=======
- 对外接口类 Worker
- 用户的Worker需要继承自core/Worker.php中的Worker类
- 业务根据自己使用的协议实现Worker::dealInput()方法
- 业务根据自己的业务逻辑实现Worker::dealProcess()方法
- 用户的Worker类名必须与文件名一致，并且与config/main.php配置下标一致
- 类名采用大驼峰风格，如：OptoolWorker
- 方法名采用小驼峰风格，如：OptoolWorker::getUserByUid()
- 框架中不建议使用全局变量,注意类静态成员膨胀导致的内存泄露
- 代码中不要包含exit、die等退出语句，不要有echo、var_dump等打印语句
8) Worker类提供的其它接口
=======
<pre>
/**
* 该worker进程开始服务的时候会触发一次，可以在这里做一些全局的事情
* 例如从磁盘里面载入某个文件
* @return bool
*/
Worker::onServe();
/**
* 该worker进程停止服务的时候会触发一次，可以在这里做一些全局的事情
* 例如给将某些数据写入磁盘
* @return bool
*/
Worker::onStopServe();
/**
* 每隔一段时间(5s)会触发该函数，用于触发worker某些流程
* 例如每5秒给外部发送一个心跳包
* @return bool
*/
Worker::onAlarm();
</pre>
9)PHPServer 进程模型示意图
=======
Master process
/ | \
/ | \
/ | \
/ | \
/ | \
/ | \
| | |
V V V
worker worker worker
^ ^ ^ ^ ^ ^ ^
| | | | | | |
/ | \ | / | \
/ | \ | / | \
/ | \ | / | \
| | | | | | |
V V V V V V V
client client client client client client
10)PHPServer 支持的特性
=======
- 多进程
- 进程管理及监控
- 支持tcp/udp协议
- 支持Inotify文件更新监控及重新加载
- 支持平滑重启
- 支持配置文件重新加载
- 支持以指定用户运行worker
- 支持Epoll 需要有libevent libev libuv等扩展支持
- 支持以指定用户运行worker进程
- 支持PHP语法检查
- 支持超时设置 网络超时及逻辑处理超时
- 支持服务状态统计
- 支持telnet远程控制及监控
11)PHPServer telnet远程控制
=======
- PHPServer支持telnet远程控制及监控,默认是10101端口
- 使用方法
<pre>
telnet ip 10101
输入密码P@ssword
输入命令status
---------------------------------------GLOBAL STATUS--------------------------------------------
start time:2013-09-16 15:16:05 run 0 days 0 hours
load average: 4.44, 4.71, 4.44
1 users 6 workers 12 processes
worker_name exit_status exit_count
JumeiWorker 0 0
StatisticWorker 0 0
StatisticService 0 0
TaskWorker 0 0
TestClientWorker 0 0
EchoWorker 0 0
---------------------------------------PROCESS STATUS-------------------------------------------
pid memory proto port timestamp worker_name total_request recv_timeout proc_timeout packet_err thunder_herd client_close send_fail throw_exception suc/total
8866 1.5M tcp 2201 1379315765 JumeiWorker 0 0 0 0 0 0 0 0 100%
8871 1.5M tcp 2201 1379315765 JumeiWorker 0 0 0 0 0 0 0 0 100%
8874 1.75M tcp 20202 1379315765 StatisticService 0 0 0 0 0 0 0 0 100%
8867 1.5M tcp 2201 1379315765 JumeiWorker 0 0 0 0 0 0 0 0 100%
8882 1.5M tcp 20304 1379315765 EchoWorker 0 0 0 0 0 0 0 0 100%
8883 1.5M tcp 20304 1379315765 EchoWorker 0 0 0 0 0 0 0 0 100%
8884 1.5M tcp 20304 1379315765 EchoWorker 0 0 0 0 0 0 0 0 100%
8873 1.75M udp 2207 1379315765 StatisticWorker 4212486 0 0 0 0 0 0 0 100%
8876 1.75M udp 10203 1379315765 TaskWorker 28 0 0 0 0 0 0 0 100%
8881 1.5M tcp 20304 1379315765 EchoWorker 0 0 0 0 0 0 0 0 100%
8869 1.5M tcp 2201 1379315765 JumeiWorker 0 0 0 0 0 0 0 0 100%
8877 1.5M tcp 30303 1379315765 TestClientWorker 0 0 0 0 0 0 0 0 100%
</pre>
- status命令说明
- GLOBAL STATUS
- start time server启动的时间
- load average 服务器负载1分钟、5分钟、15分钟负载情况
- users telnet链接到这台服务器的管理员数
- workers 启动了多少组worker
- processes 启动了多少worker进程
- worker_name worker组的名称
- exit_status 进程退出状态 0是正常退出 非0则是异常如出现Fatal Err
- exit_count 进程退出次数，如果进程退出次数过大，则说明代码有异常如出现大量Fatal Err，业务逻辑调用exit die语句
- PROCESS STATUS
- pid 进程id
- memory 当前进程占用内存
- worker_name 当前进程所属worker组
- proto 使用的传输层协议
- port 端口
- timestamp 进程创建的时间戳
- total_request 该进程接收了多少请求
- recv_timeout 该进程有多少请求接收超时
- proc_timeout 该进程有多少请求逻辑处理时超时
- packet_err 该进程有多少请求解包发生错误
- thunder_herd 该进程有多少次惊群效应
- client_close 客户端主动关闭链接的次数
- send_fail 该进程有多少请求Server向Client发送数据失败
- throw_exception 该进程收到了多少业务层抛出的但是业务层未捕获的异常
- suc/total 该进程成功率 （不是业务成功率，只是框架在收发包层面的成功率）
- kill 命令说明
- 用法 kill [pid]， pid为status展示的pid
- 作用停止某个pid对应的worker进程的服务，让pid对应进程处理完手头上的工作后安全退出
- stop 命令说明
- 用法 stop
- 停止Server，注意停止后telnet会断开，需要运维手动重启，所以最好不要用这个命令。
- reload 命令说明
- 用法 reload
- 平滑重启并且重新加载服务器配置
- debug 命令说明
- 用法 debug var
- var 是Server的类成员，例如 debug $filesToInotify，打印出Server类的$filesToInotify成员变量（被server监控的所有文件）
- quit 命令说明
- 断开telnet链接
12)PHPServer 框架自身的监控及告警
=======
- 可以通过telnet命令登录观察当前PHPServer工作情况，并且可以做相应控制
- 当worker进程频繁退出时，会有短信告警
- 当某个worker进程请求处理成功率低于99.99%（具体数值待定）时触发短信告警
13)业务接口调用量、延时、波动及成功率监控
=======
- PHPServer内置了一个统计业务调用信息的接口，用来统计调用量、成功率等信息
- 统计代码埋在Worker入口处，对业务透明
- 进程间使用udp协议，对于业务无影响，不影响外网带宽
- 统计每5分钟会做一次汇总
- 可以通过PHPServer监控页面查看单台机器的业务接口调用统计 地址为http://PHPServer_ip:20202 例如:[http://192.168.20.23:20202](http://192.168.20.23:20202)
- PHPServer监控页面以图形和表格方式展示每个时间段的所有类的所有接口的调用量、平均处理耗时、成功调用量、成功平均耗时、失败调用量、失败平均耗时、成功率信息 例如
<pre>
时间 调用总数 平均耗时 成功调用总数 成功平均耗时 失败调用总数 失败平均耗时 成功率
2013-09-04 00:00:00 17884 2.342 8860 2.323 2 2.36 99.941%
2013-09-04 00:05:00 17848 2.352 8954 2.343 0 2.361 100%
2013-09-04 00:10:00 17889 2.333 8866 2.348 0 2.317 100%
2013-09-04 00:15:00 17816 2.344 8939 2.34 0 2.348 100%
2013-09-04 00:20:00 17718 2.325 8932 2.318 1 2.332 99.999%
2013-09-04 00:25:00 17590 2.348 8924 2.354 0 2.342 100%
2013-09-04 00:30:00 17974 2.349 8931 2.352 0 2.346 100%
</pre>
- 如果某个时间段内如果有接口调用失败，可以点击统计页面中失败数字进入log查询页面查询失败log及错误码
- 当某个接口在某个时间段成功率小于98%（具体数值待定）时出发短信告警
- 业务也可以自己按照固定格式上报想要的统计信息，Server框架会做相应的统计。上报数据使用udp协议，端口为2207，上报格式如下，没有返回包
<pre>
struct statistic_data
{
int code, //返回码
unsigned int time, //时间
float cost_time, //消耗时间 单位秒 例如1.xxx
unsigned int source_ip, //来源ip
unsigned int target_ip, //目标ip
unsigned char success, //是否成功
unsigned char module_name_length, //模块名字长度
unsigned char interface_name_length,//接口名字长度
unsigned short msg_length, //日志信息长度
unsigned char[module_name_length] module, //模块名字
unsigned char[interface_name_length] interface, //接口名字
char[msg_length] msg //日志内容
}
</pre>
14)压测数据
============
- 测试环境：
<pre>
系统：ubuntu 12.04 LTS 64位
内存：8G
cpu：Intel® Core™ i3-3220 CPU @ 3.30GHz × 4
</pre>
- 测试脚本 ./test/benchmark
- Server开启4个worker进程（worker进程只是将收到的包写回客户端）
- 短链接（每次请求完成后关闭链接）:
- 条件： 压测脚本开500个线程，每个线程链接PHPSever10W次，每次链接发送1个请求
- 结果： 吞吐量：3W/S ， cpu：60% ， 内存占用：4*7M = 28M
- 长链接（每次请求后不关闭链接）:
- 条件： 压测脚本开1000个线程，每个线程链接PHPSever1次，每个链接发送10W请求
- 结果： 吞吐量：9.7W/S ， cpu：68% ， 内存占用：4*7M = 28M
- 压测脚本和PHPServer在一个机器上运行，理论上压测结果会更好一些
15)使用Thrift（以ubuntu系统为例）
=============
###创建demo相关目录
`sudo mkdir /home/demo && sudo chmod 777 /home/demo && mkdir demo/service_demo && mkdir demo/client_demo && cd /home/demo`
###下载及配置及启动parrot-phpserver
`hg clone https://hg.int.jumei.com/WS_Group/Vendor/Vendor`
`cp Vendor/PHPServer/config/main.example.php Vendor/PHPServer/config/main.php`
`sudo ./Vendor/PHPServer/bin/serverd start`
###建立IDL文件
打开<http://thrift.int.jumei.com/>，点击在线创建
创建 User.thrift 文件如下
namespace php Provider.User
service User
{
map<string, string> getUserInfo(1:int uid);
}
######注意IDL文件命名空间统一格式为 `namespace php Provider.服务名`
###下载生成的PHP文件并解压
`unzip HelloWorlds.thrift.php.zip -d /home/demo/service_demo/`
`unzip HelloWorlds.thrift.php.zip -d /home/demo/client_demo/`
###创建 User 类
`mkdir /home/demo/service_demo/Handler`
创建文件`vi /home/demo/service_demo/Handler/User.php`如下
<?php
namespace Handler;
class User implements \Provider\User\UserIf
{
public function getUserInfo($uid)
{
return array('uid'=>$uid, 'name'=>'tom', 'sex'=>'1');
}
}
######注意Handler文件命名空间格式为 `namespace Handler`
####配置服务端####
`vi /home/demo/Vendor/PHPServer/config/main.php`
设置 ThriftWorker 项的 provider、handler目录位置
'ThriftWorker' => array(
'protocol' => 'tcp', // 固定tcp
'port' => 9090, // 每组服务一个端口
'child_count' => 5, // 启动多少个进程提供服务
'persistent_connection' => true, // thrift默认使用长链接
'provider' => '/home/demo/service_demo/Provider', // 这里是thrift生成文件所放目录,可以是绝对路径
'handler' => '/home/demo/service_demo/Handler', // 这里是对thrift生成的Provider里的接口的实现
'bootstrap' => '../../bootstrap.php', // 进程启动时会载入这个文件，里面可以做一些autoload等初始化工作
'max_requests' => 1000, // 进程接收多少请求后退出
),
####使用phpserver的thrift调试客户端
* 浏览器中输入地址 http://phpserver_ip:30304 , phpserver_ip是phpserver运行的ip地址，例如 http://127.0.0.1:30304
* 可以看到一个web页面，有类、方法、参数三栏
* 鼠标点击类输入框，可以看到所有该ip所提供的服务
* 同理点击方法输入框，可以看到对应类的所有方法、参数及参数名
* 输入相应参数，点击提交就可以看到服务端返回的结果
###配置客户端
` cp /home/demo/Vendor/Thrift/test.php /home/demo/client_demo/`
在`vi /home/demo/client_demo/parrot-client/test.php`文件
设置Vendor根目录
define('VENDOR_ROOT', '/home/demo/Vendor');
配置项增加
'User' => array( // 注意：键名统一为服务名
'nodes' => array(
'127.0.0.1:9090' // 与服务端配置的ip端口相同，可配置多个ip端口
),
'provider' => '/home/demo/client_demo/Provider', // 这里是thrift生成文件所放目录
),
####命令行运行客户端
`php test.php`
会看到如下显示,证明成功
<pre>
array(3) {
["uid"]=>
string(3) "201"
["name"]=>
string(3) "tom"
["sex"]=>
string(1) "1"
}
array(3) {
["uid"]=>
string(3) "202"
["name"]=>
string(3) "tom"
["sex"]=>
string(1) "1"
}
array(3) {
["uid"]=>
string(3) "203"
["name"]=>
string(3) "tom"
["sex"]=>
string(1) "1"
}
</pre>
###服务端配置说明
####一个Thrift服务端配置例子：
<pre><code>
'YourServiceName' => array( // 注意：键名固定为服务名
'protocol' => 'tcp', // 固定tcp
'port' => 9091, // 每组服务一个端口
'child_count' => 5, // 启动多少个进程提供服务,线上环境应该启动200个左右
'recv_timeout' => 10000, // [选填]从客户端接收数据的超时时间 不配置默认1000毫秒
'process_timeout' => 30000, // [选填]业务逻辑处理超时时间 不配置默认30000毫秒
'send_timeout' => 1000, // [选填]发送数据到客户端超时时间 不配置默认1000毫秒
'persistent_connection' => false, // [选填]是否是长连接 不配置默认是短链接（短连接每次请求后服务器主动断开）
'max_requests' => 1000, // [选填]进程接收多少请求后退出 不配置默认是0，不退出
'provider' => __DIR__ . '/../../../Provider', // 这里是thrift生成文件所放目录,可以是绝对路径
'handler' => __DIR__ . '/../../../Handler', // 这里是对thrift生成的Provider里的接口的实现,一般是Handler目录
'bootstrap' => __DIR__ . '/../../../init.php', // 进程启动时会载入这个文件，里面可以做一些autoload等初始化工作
),
</code></pre>
####关于bootstrap配置(此项是可选配置)
Thrift服务有个bootstrap配置，这个配置是一个php文件，如果文件存在，则当php进程启动的时候会载入这个文件。
这个文件一般作用如下：
* 业务可以在这里使用spl_autoload_register注册类自动加载函数
* 可以做一些全局的初始化工作
* 实现on_phpserver_request_start on_phpserver_request_finish函数，函数作用在下面有说明
####关于on_phpserver_request_start/finish函数
* on_phpserver_request_start
如果业务实现此函数，则每次请求处理前会触发此函数。在这里可以实现请求初始化等一些工作。
* on_phpserver_request_finish
如果业务实现此函数，则每次请求成功处理后会触发此函数。在这里可以实现请求清理一些工作，例如关闭数据库链接。
注意：公司规范要求业务使用mysql数据库时需要用短链接，也就是要求在请求完毕之后需要关闭数据库链接，此时可以在on_phpserver_request_finish函数里面完成这项工作。
例如在：bootstrap指定的文件中实现该函数类似如下
<pre><code>
// phpserver 请求完毕钩子函数，目前只支持thrift，用来关闭数据库链接等操作
function on_phpserver_request_finish()
{
if(class_exists('\\Db\\Connection', false) && is_callable(array(\Db\Connection::instance(), 'closeAll')))
{
\Db\Connection::instance()->closeAll();
}
}
</code></pre>
###目录结构
<pre>
demo
├── client_demo
│   ├── parrot-client
│   │   ├── Client.php
│   │   ├── Lib
│   │   ├── Ping.php
│   │   ├── README.md
│   │   └── test.php
│   └── Provider
│   └── HelloWorlds
│   ├── HelloWorlds.php
│   └── Types.php
├── server_demo
│   ├── Handler
│   │   └── HelloWorldsHandler.php
│   ├── parrot-phpserver
│   │   ├── bin
│   │   ├── config
│   │   │   ├── main.example.php
│   │   │   └── main.php
│   │   ├── core
│   │   ├── docs
│   │   ├── plugins
│   │   ├── protocols
│   │   ├── README.md
│   │   ├── tests
│   │   ├── thirdparty
│   │   └── workers
│   └── Provider
│   └── HelloWorlds
│   ├── HelloWorlds.php
│   └── Types.php
└── thrifts
└── HelloWorlds.thrift
</pre>
###安装Thrfit(建议使用thrift.int.jumeicd.com，不用安装Thrift)
`sudo apt-get install libboost-dev automake libtool flex bison pkg-config g++ make libssl-dev`
`mkdir /home/demo/thrift_source_code && cd /home/demo/thrift_source_code`
`wget http://www.eu.apache.org/dist/thrift/0.9.1/thrift-0.9.1.tar.gz`
`tar -zxvf thrift-0.9.1.tar.gz`
`cd thrift-0.9.1`
`sudo ./configure && sudo make && sudo make install`
####Thrift 数据类型
1.基本类型：
bool（boolean）: 布尔类型（对应PHP的bool）
byte（byte）: 8位带符号整数（PHP原生没有8位带符号整数）
i16（short）: 16位带符号整数（PHP原生没有16位带符号整数）
i32（int）: 32位带符号整数（对应PHP的int）
i64（long）: 64位带符号整数(对应PHP的int/float)
double（double）: 64位浮点数(对应PHP的float)
string（String）: 采用UTF-8编码的字符串(对应PHP的string)
2.特殊类型：
binary：未经过编码的字节流(可以看作PHP的string)
3.Structs：
struct定义了一个很普通的OOP对象，但是没有继承特性。对应PHP的对象
struct UserProfile {
1: i32 uid, // php int
2: string name, // php string
3: string blurb // php string
}
如果变量有默认值，可以直接写在定义文件里:
struct UserProfile {
1: i32 uid = 1,
2: string name = "User1", // 默认值
3: string blurb
}
4.容器，除了上面提到的基本数据类型，Thrift还支持以下容器类型：
list：（对应PHP的数组，可以看作键名是从0开始的连续整数的数组）
set：（PHP原生没有set，可以看作是特殊的PHP数组，数组内没有重复的值，键名也是从0开始的连续整数）
map：（可以看作是PHP的数组，但是强制类型的，针对某个数组键值要么为数字（可以不连续），要么为字符串，键值也只有一种类型，数字、字符串、对象、数组等）
用法如下：
struct Node {
1: i32 id, // php int
2: string name, // php string
3: list<i32> subNodeList, //键值强制是从0开始的连续整数，键值强制为整数（可重复）的数组
4: map<i32,string> subNodeMap,//键名强制为整数（可以乱序），键值强制为字符串的数组
5: set<i32> subNodeSet //键值强制是从0开始的连续整数，键值强制为不重复整数的数组
}
包含定义的其他Object:
struct SubNode {
1: i32 uid,
2: string name,
3: i32 pid
}
struct Node {
1: i32 uid,
2: string name,
3: list<subNode> subNodes
}
5.Services服务，也就是对外展现的接口：（对应PHP中的类）
service UserStorage {
void store(1: UserProfile user),
UserProfile retrieve(1: i32 uid)
}
####Thrift使用注意事项
* IDL 使用struct时，如果要增加字段一般增加在struct末尾，这样可以避免客户端未更新IDL编译文件导致的解析异常。例如：下面1 2 3 4 是原有的字段，它们顺序及对应关系不要改变，5：list<Phone> phones是新增加的一个字段，序号递增为5加在struct末尾。
struct Person {
1: i32 id,
2: string firstName,
3: string lastName,
4: string email,
5: list<Phone> phones
}
* IDL 使用struct时，如果删除一个字段，建议先保证更新了所有客户端IDL编译文件再更新服务端IDL编译文件，避免客户端解析异常
* IDL struct定义可以使用required、optional标识，例如
struct Tweet {
1: required i32 userId;
2: required string userName;
3: optional string text;
}
struct定义中的每个域可使用required或者optional关键字进行标识。如果required标识的域没有赋值，Thrift将给予提示；如果optional标识的域没有赋值，该域将不会被序列化传输；如果某个optional标识域有缺省值而用户没有重新赋值，则该域的值一直为缺省值
####Thrift参考资料
* 官网 http://thrift.apache.org/
* http://www.ibm.com/developerworks/cn/java/j-lo-apachethrift/index.html 
