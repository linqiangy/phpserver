<?php
/**
 * RpcClient ping脚本，
 * 用于探测故障节点是否已经复活
 * 
 * *我只想说这个方案很挫* *
 * 
 * @author liangl<liangl3@jumei.com>
 * 
 */

/**
 * 共享内存key
 * @var int
 */
$shm_key = 0x90905741;

/**
 * 共享内存fd
 * @var resource
 */
$shm_fd = shm_attach($shm_key);

/**
 * 信号量fd
 * @var resource
 */
$sem_fd = sem_get($shm_key);

/**
 * 保存所有故障节点的VAR
 * @var int
 */
define('RPC_BAD_ADDRESS_KEY', 1);

/**
 * 保存配置的md5的VAR,用于判断文件配置是否已经更新
 * @var int
 */
define('RPC_CONFIG_MD5', 2);

/**
 * 是否是debug模式
 * @var bool
 */
$debug = isset($argv[1]) && $argv[1] = 'debug';

/**
 * 非debug模式关闭标准输出
 */
if(!$debug) resetStdFd();

/**
 * 循环探测故障节点
 */
while (1) {
    // 获取故障节点
    $bad_address_list = get_bad_list();
    // 打印故障节点列表
    echo "bad address list : " . json_encode($bad_address_list) . "\n";
    // 循环探测
    foreach($bad_address_list as $address)
    {
        // 创建客户端链接
        $client = @stream_socket_client("tcp://$address", $errno, $errmsg, 1);
        // 设置超时时间
        @stream_set_timeout($client, 1);
        // 发送心跳数据
        @fwrite($client, pack("H*",'0000005a80010001000000192424245f315f504324242424242424245f5f315f265f385f23000000000b0b000000010000000b69734865617274426561740000000474727565800100010000000c23242548656172746265617400000000'));
        // 没有返回，认为节点还是存在故障
        if(!@fread($client, 10240))
        {
            continue;
        }
        // 有返回，认为节点复活，从故障节点列表中删除
        delete_from_bad_list($address);
    }
    // 5秒探测一次
    sleep(5);
}

/**
 * 获取故障节点列表
 * @return array
 */
function get_bad_list()
{
    global $shm_fd;
    $bad_address_list = @shm_get_var($shm_fd, RPC_BAD_ADDRESS_KEY);
    return $bad_address_list ? $bad_address_list : array();
}

/**
 * 保存故障节点列表
 * @param array $address_list
 * @return bool
 */
function save_bad_list($address_list)
{
    global $shm_fd, $sem_fd;
    $sem_fd && sem_acquire($sem_fd);
    $ret = shm_put_var($shm_fd, RPC_BAD_ADDRESS_KEY, $address_list);
    $sem_fd && sem_release($sem_fd);
    return $ret;
}

/**
 * 从故障节点列表中删除
 * @param array $address
 * @return bool
 */
function delete_from_bad_list($address)
{
    $bad_address_list = get_bad_list();
    $bad_address_list_flip = is_array($bad_address_list) ? array_flip($bad_address_list) : array();
    
    if(!isset($bad_address_list_flip[$address]))
    {
        return true;
    }
    unset($bad_address_list_flip[$address]);
    return save_bad_list(array_keys($bad_address_list_flip));
}

/**
 * 重定向标准输出
 * @return void\
 */
function resetStdFd()
{
    global $STDOUT, $STDERR;
    @fclose(STDOUT);
    @fclose(STDERR);
    $STDOUT = fopen('/dev/null',"rw+");
    $STDERR = fopen('/dev/null',"rw+");
}

