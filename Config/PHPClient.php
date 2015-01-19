<?php
namespace Config;

/**
 * Class PHPClient
 * @package Config
 *
 * e.g:
 *      smsAlarmPrarm
 *      一般只需要配置告警手机号即可
 *
 *      uri
 *      uri支持配置多个服务端ip，并支持权重设置，以实现客户端负载均衡。
 *      格式为 节点ip:端口:权重
 *      权重高的访问几率就越高，比如有7000个请求
 *      'uri'=>array(
 *          '10.0.1.1:9099:1',
 *          '10.0.1.2:9099:2',
 *          '10.0.1.3:9099:3',
 *          '10.0.1.4:9099:0',
 *      ),
 *      则
 *      10.0.1.1:9099:1 会收到约1000个请求；
 *      10.0.1.2:9099:2 会收到约2000个请求；
 *      10.0.1.3:9099:3 会收到约3000个请求；
 *      10.0.1.4:9099:0 不会收到请求
 *
 * 调用
 *    $cart_user_client = RpcClient_Cart_User::instance();
 *    $user_info = $cart_user_client->getUserByUid(5100);
 */
class PHPClient
{
    // 告警短信(链接超时及链接拒绝告警)及参数，一般只需要配置手机号
    public static $smsAlarmPrarm = array(
        // 接收告警的手机号，逗号(,)分割
        'phone'  => '18888888888,1888888888',
        // 短信告警接口url（默认不用动）
        'url'    => 'http://sms.int.seanxyh.com/send',
        // 接口参数（默认不用动）
        'params' => array(
            'channel' => 'monternet',
            'key' => 'notice_rt902pnkl10udnq',
            'task' => 'int_notice',
        ),
    );

    // rpc_secret_key
    public $rpc_secret_key = '769af463a39f077a0340a189e9c1ec28';

    // 用户服务
    public $User = array(
        // 客户端负载均衡，注意只有PHPClient版本大于等于1.1.5才支持客户端负载均衡
        'uri'=>array(
            '10.0.1.1:9099:1', // 格式为 节点ip:端口:权重
            '10.0.1.2:9099:2',
            '10.0.1.3:9099:3',
            '10.0.1.4:9099:0', // 权重为0代表下线
        ),
        'user' => 'Optool',
        'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
    );

    // cart服务
    public $Cart= array(
        'uri' => 'tcp://11.14.120.123:2201', // 1.1.5以上版本也支持老LVS HAPROXY DNS负载均衡的配置方式
        'user' => 'Optool',
        'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
    );
}
