<?php
namespace Thrift;
define('THRIFT_CLIENT_DIR', realpath(__dir__) . '/');

require_once THRIFT_CLIENT_DIR . 'Lib/KLogger/KLogger.php';

if(!defined('IN_THRIFT_WORKER'))
{
    require_once THRIFT_CLIENT_DIR . 'Lib/Thrift/ClassLoader/ThriftClassLoader.php';
    require_once THRIFT_CLIENT_DIR . 'Lib/Thrift/Context.php';
    require_once THRIFT_CLIENT_DIR . 'Lib/Thrift/ContextSerialize.php';
    require_once THRIFT_CLIENT_DIR . 'Lib/Thrift/ThriftInstance.php';
    
    // 加载Thrift相关类
    $loader = new \Thrift\ClassLoader\ThriftClassLoader();
    $loader->registerNamespace('Thrift', THRIFT_CLIENT_DIR. 'Lib');
    $loader->register(true);
}
else
{
    require_once THRIFT_CLIENT_DIR . 'Lib/Thrift/ThriftInstance.php';
}

require_once __DIR__.'/../MNLogger/Base.php';
require_once __DIR__.'/../MNLogger/TraceLogger.php';
require_once __DIR__.'/../MNLogger/MNLogger.php';
require_once __DIR__.'/../PHPClient/JMTextRpcClient.php';

/**
 * 保存所有故障节点的VAR
 * @var int
 */
define('RPC_BAD_ADDRESS_KEY', 1);

/**
 * 保存配置的md5的VAR,用于判断文件配置是否已经更新
 * @var int
 */
define('RPC_CONFIG_MD5_KEY', 2);

/**
 * 保存上次告警时间的VAR
 * @var int
 */
define('RPC_LAST_ALARM_TIME_KEY', 3);


/**
 * 
 * 版本1.1.5
 * 发布时间 2015-01-13
 * 2015-01-13 短信告警治理
 * 2014-12-01 所有操作共享内存的地方加锁
 * 2014-08-24 thrift客户端text协议支持故障ip踢出，支持按照项目配置客户端，修复types.php中的类调用前没加载问题
 * 2014-07-15 去掉老的mnlogger埋点 加入tracelogger
 * 2014-06-06 Thrift客户端支持文本协议 
 * 
 * 通用客户端,支持故障ip自动踢出
 * 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * \Thrift\Client::config(array(  
 *                         'IRecommend' => array(
 *                             'nodes' => array(
 *                                   '10.0.20.10:9090',
 *                                   '10.0.20.11:9090',
 *                               ),
 *                           ),
 *                           'HelloWorldService' => array(
 *                               'nodes' => array(
 *                                   '127.0.0.1:9090'
 *                               ),
 *                           ),
 *                     )
 * );
 * 
 * // 同步调用
 * $recommend_client = \Thrift\Client::instance('IRecommend');
 * $ret = $recommend_client->recommendForUser(2000000437, "bj", 0, 3));
 * 
 * ***************注意：当使用text协议时无法使用客户端异步*****************
 * // ===以下是异步调用=== 
 * // 异步调用之发送请求给服务器。提示：在方法前面加上asend_前缀即为异步发送请求
 * $recommend_client->asend_recommendForUser(2000000437, "bj", 0, 3);
 * 
 * .................这里是你的其它业务逻辑...............
 * 
 * // 异步调用之获取服务器返回。提示：在方法前面加上arecv_前缀即为异步接收服务器返回
 * $ret_async = arecv_recommendForUser(2000000437, "bj", 0, 3);
 * 
 * <code>
 * </pre>
 * 
 * @author libingw <libingw@jumei.com>
 * @author liangl <liangl3@jumei.com>
 *
 */
class Client 
{
    /**
     * 存储RPC服务端节点共享内存的key
     * @var int
     */
    const BAD_ASSRESS_LIST_SHM_KEY = 0x90905741;
    
    /**
     * 当出现故障节点时，有多大的几率访问这个故障节点(默认万分之一)
     * @var float
     */
    const DETECTION_PROBABILITY = 0.0001;
    
    /**
     * 客户端实例
     * @var array
     */
    private static $instance = array();
    
    /**
     * 配置
     * @var array
     */
    private static $config = null;
    
    /**
     * 故障节点共享内存fd
     * @var resource
     */
    private static $badAddressShmFd = null;
    
    /**
     * 故障的节点列表
     * @var array
     */
    private static $badAddressList = null;
    
    /**
     * 信号量
     * @var resource
     */
    private static $semFd = null;
    
    /**
     * 上次告警时间戳
     * @var int
     */
    private static $lastAlarmTime = 0;
    
    /**
     * 
     */
    private static $alarmTimeKey = '';
    
    /**
     * 告警时间间隔 单位:秒
     * @var int
     */
    private static $alarmInterval = 300;
    
    /**
     * 短信告警（服务连不上）相关参数
     * @var int
     */
    private static $smsAlarmPrarm = array(
        // 接收告警的手机号，逗号(,)分割
        'phone'  => '',
        // 短信告警接口url
        'url'    => 'http://sms.int.jumei.com/send',
        // 接口参数
        'params' => array(
            'channel' => 'monternet',
            'key' => 'notice_rt902pnkl10udnq',
            'task' => 'int_notice', 
        ),
    );
    
    /**
     * monitorLogPath
     * @var string
     */
    public static $monitorLogPath  = '/home/logs/monitor';
    
    /**
     * traceLogPath
     * @var string
     */
    public static $traceLogPath  = '/home/logs/monitor';
    
    /**
     * exceptionLogPath
     * @var string
     */
    public static $exceptionLogPath = '/home/logs/monitor';
    
    /**
     * 排它锁文件handle
     * @var resource
     */
    private static $lockFileHandle = null;
    
    /**
     * klogger
     * @var klogger
     */
    public static $logger = null;
    
    /**
     * 设置/获取 配置
     *  array(  
     *      'IRecommend' => array(
     *          'nodes' => array(
     *              '10.0.20.10:9090',
     *              '10.0.20.11:9090',
     *              '10.0.20.12:9090',
     *          ),
     *          'provider'      => 'yourdir/Provider',
     *      ),
     *      'HelloWorldService' => array(
     *          'nodes' => array(
     *              '127.0.0.1:9090'
     *          ),
     *          'provider'      => 'yourdir/Provider',
     *      ),
     *  )
     * @param array $config
     * @return array
     */
    public static function config(array $config=array())
    {
        if(!self::$logger)
        {
            // klogger
            if(is_writeable('/home/www/logs/'))
            {
                $klogdir = '/home/www/logs/';
            }
            elseif(is_writeable('/home/jm/logs/'))
            {
                $klogdir = '/home/jm/logs/';
            }
            else
            {
                $klogdir = sys_get_temp_dir();
                if($klogdir == '')
                {
                    $klogdir = '/tmp/';
                }
            }
            self::$logger = @\ThriftClient\KLogger\KLogger::instance($klogdir.'/php-rpc-client', \ThriftClient\KLogger\KLogger::INFO);
        }
        
        if(!empty($config))
        {
            // 初始化配置
            self::$config = $config;
            // 检查现在配置md5与共享内存中md5是否匹配，用来判断配置是否有更新
            self::checkConfigMd5();
            // 从共享内存中获得故障节点列表
            self::getBadAddressList();
        }
        
        // 如果配置为空，则尝试自动加载
        if(empty(self::$config) && class_exists("\\Config\\Thrift"))
        {
            self::$config = (array) new \Config\Thrift;
            // 检查现在配置md5与共享内存中md5是否匹配，用来判断配置是否有更新
            self::checkConfigMd5();
            // 从共享内存中获得故障节点列表
            self::getBadAddressList();
        }
        
        return self::$config;
    }
    
    /**
     * 配置告警手机，多个手机号用逗号（,）分割
     * 例如：$phone_str = '133333333333,13333333333';
     * 告警类型是客户端无法链接服务端，包括频繁超时以及目标不可达
     * @param string $phone_str
     */
    public static function configAlarmPhone($phone_str)
    {
        $phone_str = str_replace('，', ',', $phone_str);
        self::$smsAlarmPrarm['phone'] = $phone_str;
    }
    
    /**
     * 客户端的其它配置
     * $ext_config = array(
     *     'monitor_log_path' => '/home/logs/monitor',  // 统一监控日志目录
     *     'trace_log_path' => '/home/logs/monitor',    // 日志追踪目录
     *     'exception_log_path' => '/home/logs/monitor',// 异常日志目录
     *     'alarm_phone' => '15555555555,13333333333',   // 告警电话
     * );
     * @param array $ext_config
     */
    public static function extConfig(array $ext_config)
    {
        if(isset($ext_config['monitor_log_path']))
        {
            self::$monitorLogPath = $ext_config['monitor_log_path'];
        }
        if(isset($ext_config['trace_log_path']))
        {
            self::$traceLogPath = $ext_config['trace_log_path'];
        }
        if(isset($ext_config['exception_log_path']))
        {
            self::$exceptionLogPath = $ext_config['exception_log_path'];
        }
        if(isset($ext_config['alarm_phone']))
        {
            self::configAlarmPhone($ext_config['alarm_phone']);
        }
    }
    
    
    /**
     * 获取实例
     * @param string $serviceName 服务名称
     * @param bool $newOne 是否强制获取一个新的实例
     * @return object/Exception
     */
    public static function instance($serviceName, $newOne = false)
    {
        if(empty(self::$config))
        {
            self::config();
        }
        if (empty($serviceName))
        {
            $e = new \Exception('ServiceName can not be empty');
            self::$logger->logError($e);
            throw $e;
        }
        
        // 判断$serviceName 是否是 项目.service 格式,例如Cart.User
        $project_and_service = explode('.', $serviceName, 2);
        if(count($project_and_service) > 1)
        {
            $project = $project_and_service[0];
            $service = $project_and_service[1];
        }
        else
        {
            $project = $service = $serviceName;
        }
        
        if(isset(self::$config[$project]['protocol']) && self::$config[$project]['protocol'] == 'text')
        {
            $one_address = self::getOneAddress($project);
            $config = array(
                'rpc_secret_key' => '769af463a39f077a0340a189e9c1ec28',
                $service => array(
                    'uri' => 'tcp://'.$one_address,
                    'user' => 'Thrift',
                    'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                )
            );
            \PHPClient\JMTextRpcClient::config($config);
            return call_user_func(array('RpcClient_'.$service, 'instance'), $config);
        }
        
        if($newOne)
        {
            unset(self::$instance[$serviceName]);
        }
        
        if(!isset(self::$instance[$serviceName]))
        {
            self::$instance[$serviceName] = new ThriftInstance($service, $project);
        }
        
        return self::$instance[$serviceName];
    }
    
    /**
     * 获取一个可用节点
     * @param string $service_name
     * @throws \Exception
     * @return string
     */
    public static function getOneAddress($service_name)
    {
    
        // 配置中没有配置这个服务
        if(!isset(self::$config[$service_name]))
        {
            $e = new \Exception("Service[$service_name] is not exist!");
            self::$logger->logError($e);
            throw $e;
        }
    
        // 总的节点列表
        $address_list = self::$config[$service_name]['nodes'];
        
        // owl trace 
        global $owl_context;
        $owl_context_client = $owl_context;
        if(!empty($owl_context_client))
        {
            $owl_context_client['app_name'] = defined('JM_APP_NAME') ? JM_APP_NAME : 'undefined';
        }
        \Thrift\Context::put('owl_context', json_encode($owl_context_client));
    
        // 选择协议
        if(!empty(self::$config[$service_name]['protocol']))
        {
            \Thrift\Context::put('protocol', self::$config[$service_name]['protocol']);
        }
        // 超时时间
        if(!empty(self::$config[$service_name]['timeout']) && self::$config[$service_name]['timeout'] >= 1)
        {
            \Thrift\Context::put('timeout', self::$config[$service_name]['timeout']);
        }
    
        // 获取故障节点列表 
        $bad_address_list = self::getBadAddressList(PHP_SAPI != 'cli');
        if($bad_address_list)
        {
            // 获得属于本次服务的故障ip列表
            $bad_address_list = array_intersect($bad_address_list, $address_list);
        }
        
        // 从节点列表中去掉故障节点列表
        if($bad_address_list)
        {
            $address_list = array_diff($address_list, $bad_address_list);
            // 有一定几率（1/100000）触发告警
            if(empty($address_list) || rand(1,50000) == 1)
            {
                $local_ip = self::getLocalIp();
                $alarm_data = array(
                	'type' => 7,
                	'ip' => $local_ip,
                	'target_ip' => implode(',', $bad_address_list),
                );
                self::sendSmsAlarm($alarm_data, '告警消息 PHPServer客户端监控 客户端 '.$local_ip.' 链接 ['.implode(',', $bad_address_list).'] 失败 时间：'.date('Y-m-d H:i:s'));
            }
            // 一定的几率访问故障节点，用来探测故障节点是否已经存活
            if(empty($address_list) || rand(1, 1000000)/1000000 <= self::DETECTION_PROBABILITY)
            {
                $one_bad_address = $bad_address_list[array_rand($bad_address_list)];
                self::recoverAddress($one_bad_address);
                return $one_bad_address;
            }
        }
        // 如果没有可用的节点,尝试使用一个故障节点
        if (empty($address_list))
        {
            // 连故障节点都没有？
            if(empty($bad_address_list))
            {
                $e =  new \Exception("No avaliable server node! Service_name:$service_name allAddress:[".implode(',', self::$config[$service_name]['nodes']).'] badAddress:[' . implode(',', $bad_address_list).']');
                self::$logger->logError($e);
                throw $e;
            }
            $address = $bad_address_list[array_rand($bad_address_list)];
            self::recoverAddress($address);
            $e =  new \Exception("No avaliable server node! Try to use a bad address:$address .Service_name:$service_name allAddress:[".implode(',', self::$config[$service_name]['nodes']).'] badAddress:[' . implode(',', $bad_address_list).']');
            self::$logger->logError($e);
            return $address;
        }
    
        // 随机选择一个节点
        return $address_list[array_rand($address_list)];
    }
    
    /**
     * 获取故障节点共享内存的Fd
     * @return resource
     */
    public static function getShmFd()
    {
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        if(!self::$badAddressShmFd)
        {
            self::$badAddressShmFd = shm_attach(self::BAD_ASSRESS_LIST_SHM_KEY);
        }
        return self::$badAddressShmFd;
    }
    
    /**
     * 获得信号量fd
     * @return null/resource
     */
    public static function getSemFd()
    {
        if(!self::$semFd && extension_loaded('sysvsem'))
        {
            self::$semFd = sem_get(self::BAD_ASSRESS_LIST_SHM_KEY);
        }
        return self::$semFd;
    }
    
    /**
     * 检查配置文件的md5值是否正确,
     * 用来判断配置是否有更改
     * 有更改清空badAddressList
     * @return bool
     */
    public static function checkConfigMd5()
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        
        // 获取shm_fd
        if(!self::getShmFd())
        {
            return false;
        }
        
        // 尝试读取md5，可能其它进程已经写入了
        self::getMutex();
        $config_md5 = shm_has_var(self::$badAddressShmFd, RPC_CONFIG_MD5_KEY) ? shm_get_var(self::$badAddressShmFd, RPC_CONFIG_MD5_KEY) : '';
        self::releaseMutex();
        $config_md5_now = md5(serialize(self::$config));
        
        // 有md5值，则判断是否与当前md5值相等
        if($config_md5 === $config_md5_now)
        {
            return true;
        }
        
        self::$badAddressList = array();
        
        // 清空badAddressList
        self::getMutex();
        $ret = shm_put_var(self::$badAddressShmFd, RPC_BAD_ADDRESS_KEY, array());
        self::releaseMutex();
        if($ret)
        {
            self::$logger->logInfo("Config md5 changed $config_md5!=$config_md5_now and clean bad_address_List");
            // 写入md5值
            self::getMutex();
            $ret = shm_put_var(self::$badAddressShmFd, RPC_CONFIG_MD5_KEY, $config_md5_now);
            self::releaseMutex();
            return $ret;
        }
        return false;
    }
    
    /**
     * 获取故障节点列表
     * @return array
     */
    public static function getBadAddressList($use_cache = true)
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            self::$badAddressList = array();
            return false;
        }
        
        // 还没有初始化故障节点
        if(null === self::$badAddressList || !$use_cache)
        {
            // 是否有故障节点
        	self::getMutex();
            $ret = shm_has_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY);
            self::releaseMutex();
            if(!$ret)
            {
                self::$badAddressList = array();
            }
            else 
            {
                // 获取故障节点
            	self::getMutex();
                $bad_address_list = shm_get_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY);
                self::releaseMutex();
                if(false === $bad_address_list || !is_array($bad_address_list))
                {
                    // 出现错误，可能是共享内存写坏了，删除共享内存
                	self::getMutex();
                    $ret = shm_remove(self::getShmFd());
                    self::$badAddressShmFd = shm_attach(self::BAD_ASSRESS_LIST_SHM_KEY);
                    self::releaseMutex();
                    self::$logger->logError("getBadAddressList fail bad_address_list:".var_export($bad_address_list,true) . ' shm_remove ret:'.var_export($ret, true));
                    self::$badAddressList = array();
                    // 这个不要再加锁了
                    self::checkConfigMd5();
                }
                else
                {
                    self::$badAddressList = $bad_address_list;
                }
            }
        }
       
        return self::$badAddressList;
    }
    
    public static function sendSmsAlarm($alarm_data, $content)
    {
        // 另外有进程已经在发告警短信了
        if(!self::getLock())
        {
            return true;
        }
        // 上次告警时间
        $last_alarm_time = self::getLastAlarmTime();
        if(!$last_alarm_time)
        {
            return false;
        }
        $time_now = time();
        // 时间间隔小于5分钟则不告警
        if($time_now - $last_alarm_time < self::$alarmInterval)
        {
            return;
        }
        
        // 短信告警
        if(empty(self::$smsAlarmPrarm['phone']) && class_exists('\Config\Thrift') && isset(\Config\Thrift::$smsAlarmPrarm))
        {
        	self::$smsAlarmPrarm = \Config\Thrift::$smsAlarmPrarm;
        }
        
        // 短信告警
        $url = self::$smsAlarmPrarm['url'];
        $phone_array = self::$smsAlarmPrarm['phone'] ? explode(',', self::$smsAlarmPrarm['phone']) : array();
        $params = self::$smsAlarmPrarm['params'];
        foreach($phone_array as $phone)
        {
        	$alarm_data['phone'] = $phone;
        	if(!self::sendAlarm($alarm_data))
        	{
	            $ch = curl_init();
	            curl_setopt($ch, CURLOPT_URL, $url);
	            curl_setopt($ch, CURLOPT_POST, 1);
	            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(array('num'=>$phone,'content'=>$content) , $params)));
	            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
	            self::$logger->logInfo('send msg ' . $phone . ' ' . $content. ' send_ret:' .var_export(curl_exec($ch), true));
        	}
        }
        self::setLastAlarmTime($time_now);
        return self::releaseLock();
    }
    
    protected static function sendAlarm($data)
    {
    	$alarm_uri = isset(self::$smsAlarmPrarm['alarm_uri']) ? self::$smsAlarmPrarm['alarm_uri'] : '';
    	if(!$alarm_uri)
    	{
    		$alarm_uri = 'tcp://10.1.27.12:2015';
    	}
    	$client = stream_socket_client($alarm_uri, $err_no, $err_msg, 1);
    	if(!$client)
    	{
    		self::$logger->logInfo("sendAlarm fail . $err_msg");
    		return false;
    	}
    	stream_set_timeout($client, 1);
    	$buffer = json_encode($data);
    	$send_len = fwrite($client, $buffer);
    	if($send_len !== strlen($buffer))
    	{
    		self::$logger->logInfo("sendAlarm fail . fwrite return " . var_export($send_len, true));
    		return false;
    	}
    	//fread($client, 8196);
    	self::$logger->logInfo($buffer);
    	return true;
    }
    
    /**
     * 获取上次告警时间
     */
    public static function getLastAlarmTime()
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        // 是否有保存上次告警时间
        self::getMutex();
        $ret = shm_has_var(self::getShmFd(), RPC_LAST_ALARM_TIME_KEY);
        self::releaseMutex();
        if(!$ret)
        {
            $time_now = time();
            self::setLastAlarmTime($time_now);
            return $time_now;
        }
        
        self::getMutex();
        $ret = shm_get_var(self::getShmFd(), RPC_LAST_ALARM_TIME_KEY);
        self::releaseMutex();
        
        return $ret;
    }
    
    /**
     * 设置上次告警时间
     * @param int $timestamp
     */
    public static function setLastAlarmTime($timestamp)
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), RPC_LAST_ALARM_TIME_KEY, $timestamp);
        self::releaseMutex();
        return $ret;
    }
    
    /**
     * 获得本机ip
     */
    public static function getLocalIp()
    {
        if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] != '127.0.0.1')
        {
            $ip = $_SERVER['SERVER_ADDR'];
        } 
        else 
        {
            $ip = gethostbyname(trim(`hostname`));
        }
        return $ip;
    }
    
    /**
     * 保存故障节点
     * @param string $address
     * @bool
     */
    public static function kickAddress($address)
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        $bad_address_list = self::getBadAddressList(false);
        $bad_address_list[] = $address;
        $bad_address_list = array_unique($bad_address_list);
        self::$badAddressList = $bad_address_list;
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY, $bad_address_list);
        self::releaseMutex();
        self::$logger->logInfo("kickAddress($address) now bad_address_list:[".implode(',', $bad_address_list).'] shm write ret:'.var_export($ret, true));
        return $ret;
    }
    
    /**
     * 恢复一个节点
     * @param string $address
     * @bool
     */
    public static function recoverAddress($address)
    {
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        $bad_address_list = self::getBadAddressList(false);
        if(empty($bad_address_list) || !in_array($address, $bad_address_list))
        {
            return true;
        }
        $bad_address_list_flip = array_flip($bad_address_list);
        unset($bad_address_list_flip[$address]);
        $bad_address_list = array_keys($bad_address_list_flip);
        self::$badAddressList = $bad_address_list;
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY, $bad_address_list);
        self::releaseMutex();
        self::$logger->logInfo("recoverAddress $address now bad_address_list:[".implode(',', $bad_address_list).'] shm write ret:'.var_export($ret, true));
        return $ret;
    }
    
    /**
     * 获取写锁(睡眠锁)
     * @return true
     */
    public static function getMutex()
    {
        self::getSemFd() && sem_acquire(self::getSemFd());
        return true;
    }
    
    /**
     * 释放写锁（睡眠锁）
     * @return true
     */
    public static function releaseMutex()
    {
        self::getSemFd() && sem_release(self::getSemFd());
        return true;
    }
    
    /**
     * 获取排它锁
     */
    public static function getLock()
    {
        self::$lockFileHandle = fopen("/tmp/RPC_CLIENT_SEND_MSM_ALARM.lock", "w");
        return self::$lockFileHandle && flock(self::$lockFileHandle, LOCK_EX | LOCK_NB);
    }
    
    /**
     * 释放排它锁
     */
    public static function releaseLock()
    {
        return self::$lockFileHandle && flock(self::$lockFileHandle, LOCK_UN);
    }
}
