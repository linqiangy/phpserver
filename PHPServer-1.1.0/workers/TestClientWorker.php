<?php 

/**
 * 
 * web页面rpc测试客户端
 * http://ip:30303 例如:http://192.168.20.23:30303
 * 
 * @author liangl
 *
 */

class TestClientWorker extends PHPServerWorker
{
    
    /**
     * 判断包是否都到达
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return HTTP::input($recv_str);
    }
    
    /**
     * 处理业务逻辑 查询log 查询统计信息
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $time_start = microtime(true);
        HTTP::decode($recv_str);
        $rsp_data = '';
        if(!empty($_POST))
        {
            // 传的是json文本
            if(!empty($_POST['req_data']))
            {
                $req_text = trim($_POST['req_data']);
                
                // 文本前有非json字符
                if($req_text[0] != "{" && $req_text[0] != "[")
                {
                    for($i=0,$j=strlen($req_text);$i<$j;$i++)
                    {
                        if($req_text[$i] == "{" || $req_text[$i] == "[")
                        {
                            $req_text = substr($req_text, $i);
                            break;
                        }
                    }
                }
                $len = strlen($req_text);
                if($req_text[$len-1] != "}" && $req_text[$len-1] != "]")
                {
                    for($i=$len-1;$i>0;$i--)
                    {
                        if($req_text[$i] == "]" || $req_text[$i] == "}")
                        {
                            $req_text = substr($req_text, 0, $i+1);
                            break;
                        }
                    }
                }
                
                $req_data = json_decode($req_text,true);
                if(isset($req_data[0]))
                {
                    $req_data = $req_data[0];
                }
                if(isset($req_data['data']))
                {
                    $req_data = json_decode($req_data['data'],true);
                }
                if(is_array($req_data))
                {
                    if(isset($req_data['class']))
                    {
                        $_POST['class'] = str_replace('RpcClient_', '', $req_data['class']);
                    }
                    if(isset($req_data['method']))
                    {
                        $_POST['func'] = $req_data['method'];
                    }
                    if(isset($req_data['params']))
                    {
                        $_POST['value'] = $req_data['params'];
                        if(is_array($_POST['value']))
                        {
                            foreach ($_POST['value'] as $key=>$value)
                            {
                                if(!is_scalar($value))
                                {
                                    $_POST['value'][$key] = var_export($value, true);
                                }
                                else 
                                {
                                    if($value === true)
                                    {
                                        $_POST['value'][$key] = 'true';
                                    }
                                    elseif($value === false)
                                    {
                                        $_POST['value'][$key] = 'false';
                                    }
                                    elseif($value === null)
                                    {
                                        $_POST['value'][$key] = 'null';
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            $class = isset($_POST['class']) ? $_POST['class'] : '';
            $func = isset($_POST['func']) ? $_POST['func'] : '';
            $param = isset($_POST['value']) ? $_POST['value'] : '';
            $post_address = isset($_POST['address']) ? $_POST['address'] : '127.0.0.1:2201';
            list($address_ip, $address_port) = explode(':', $post_address);
            
            if(get_magic_quotes_gpc() && !empty($_POST['value']) && is_array($_POST['value']))
            {
                foreach($_POST['value'] as $index=>$value)
                {
                    $_POST['value'][$index] = stripslashes(trim($value));
                }
            }
            if($param)
            {
                foreach($param as $index=>$value)
                {
                    if(stripos($value, 'array') === 0 || stripos($value, 'true') === 0 || stripos($value, 'false') === 0 || stripos($value, 'null') === 0 || stripos($value, 'object') === 0)
                    {
                        eval('$param['.$index.']='.$value.';');
                    }
                }
            }
            global $reqText, $rspText;
            
            JMTextRpcClient::on('send', function ($data) {
                global $reqText;
                $reqText = $data;
            });
            JMTextRpcClient::on('recv', function ($data) {
                global $rspText;
                $rspText = $data;
            });
            
            try{
                if(!class_exists('RpcClient_'.$class))
                {
                    eval('class RpcClient_'.$class.' extends JMTextRpcClient {}');
                }
                $remote_class = 'RpcClient_'.$class;
                $config = array(
                        'rpc_secret_key' => '769af463a39f077a0340a189e9c1ec28',
                        'User' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                                #'compressor' => 'GZ',
                        ),
                        'Item' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'Order' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'Cart' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'GlobalPayment' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'Product' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'DealMan',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'GlobalPayment' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'Cart' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'Optool',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        'Dealman' => array(
                                'host' => $address_ip,
                                'port' => $address_port,
                                'user' => 'DealMan',
                                'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        ),
                        
                );
                $test = $remote_class::instance($config);
                //call_user_func_array(array($test, 'asend_'.$func), $param);
                //$rsp_data = call_user_func_array(array($test, 'arecv_'.$func), $param);
                $rsp_data = call_user_func_array(array($test, $func), $param);
            }catch(Exception $e){
                $rsp_data = $e.'';
            }
            
            if(isset($_POST['req_data']))
            {
                $reqText = $_POST['req_data'];
            }
            
            return $this->display($reqText, $rspText, $rsp_data, '',microtime(true)-$time_start);
        }
        
        $this->display('','','','',microtime(true)-$time_start);
    }
    
    
    protected function display($req_text = '', $rsp_text = '', $rsp_data = '', $msg = '', $cost='')
    {
        $value_data = '';
        $class = isset($_POST['class']) ? $_POST['class'] : '';
        $func = isset($_POST['func']) ? $_POST['func'] : '';
        $rsp_data = !is_scalar($rsp_data) ? var_export($rsp_data, true) : $rsp_data;
        $cost = $cost ? round($cost, 5) : '';
        
        // 默认给个测试参数
        if(empty($_POST))
        {
            $class = "Cart_User";
            $func = "getUserByUid";
            $_POST['value'][] = 5100;
        }
        
        $post_address = '127.0.0.1:2201';
        if(isset($_POST['address']))
        {
            $post_address = $_POST['address'];
        }
        
        $address_data = '<tr><td>地址:</td><td>
        <select name="address">';
        $address_array = $this->getAddress();
        foreach($address_array as $address=>$address_and_name)
        {
            $selected = $address == $post_address ? 'selected="selected"' : '';
            $address_data .= '<option value ="'.$address.'" '.$selected.'>'.$address_and_name.'</option>';
        }
        
        $address_data .= '
        </select>
        </td></tr>';
        
        if(isset($_POST['value']))
        {
            foreach($_POST['value'] as $value)
            {
                $value_data .= '<tr><td>参数</td><td><input type="text" name="value[]" style="width:480px;" value=\''.htmlspecialchars($value, ENT_QUOTES).'\' /> <a href="javascript:void(0)" onclick="delParam(this)">删除本行</a></td></tr>';
            }
        }
        else
        {
            $value_data = '<tr><td>参数</td><td><input type="text" name="value[]" style="width:480px;" value="" /> <a href="javascript:void(0)" onclick="delParam(this)">删除本行</a></td></tr>';
        }
        
        $display_data = <<<HHH
<html>
    <head>
        <meta charset=utf-8>
        <title>Rpc test tool</title>
    </head>
    <body>
        <b style="color:red">$msg</b>
        </br>
        <b>数组使用array(..)格式,bool直接使用true/false,null直接写null</b>
        </br>
        <form action="" method="post">
            <table>
                $address_data
                <tr>
                    <td>类</td>
                    <td><input type="text" name="class" style="width:480px;" value="$class" /></td>
                </tr>
                <tr>
                    <td>方法</td>
                    <td><input type="text" name="func" style="width:480px;" value="$func" /></td>
                </tr>
                <tbody id="parames">
                   $value_data
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><a href="javascript:void(0)" onclick="addParam()">添加参数</a></td>
                    </tr>
                      <tr>
                        <td colspan="2" align="center">
                        <input style="padding:5px 20px;" type="submit" value="submit" />
                        <br>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </form>
        <b>Return Data: </b><pre>$rsp_data</pre><br>
        <b>Request Text:</b><br>
        <form action="" method="post">
        <textarea style="width:98%;height:120px" name="req_data">$req_text</textarea><br>
        <input style="padding:5px 20px;" type="submit" value="submit" />
        </form>
        <br><br>
	<table>
<tr>
<td>

</td>
<td width=24px>
</td>
<td>

</td>
</tr>

</table>
       <b>Return Text:</b><br>
        <textarea style="width:98%;height:300px">$rsp_text</textarea><br>
        <br>
        <b>耗时:</b>{$cost}秒
        <script type="text/javascript" src="http://libs.baidu.com/jquery/1.9.0/jquery.js"></script>
        <script type="text/javascript">

            function addParam() {
                $('#parames').append('<tr><td>参数</td><td><input type="text" name="value[]" style="width:480px;" value="" /> <a href="javascript:void(0)" onclick="delParam(this)">删除本行</a></td></tr>');
            }
            
            function delParam(obj) {
                $(obj).parent('td').parent('tr').remove();
            }
        </script>
    </body>
</html>    
HHH;
        
        $this->sendToClient(HTTP::encode($display_data));
    }
    
    
    protected function getAddress()
    {
        $address_array = array();
        foreach(PHPServerConfig::get('workers') as $service_name=>$config)
        {
            if((!empty($config['worker_class']) && ($config['worker_class'] == 'JumeiWorker' || $config['worker_class'] == 'JmTextWorker'))
                            || $service_name == 'JumeiWorker'
                            || $service_name == 'JmTextWorker')
            {
                $ip = '127.0.0.1';
                $address_array["$ip:{$config['port']}"] = "$ip:{$config['port']} {$service_name}";
            }
        }
        return empty($address_array) ? $address_array = array('127.0.0.1:2201'=>'127.0.0.1:2201 JumeiWorker') : $address_array;
    }
    
    
}

/**
 * 新 RPC 文本协议客户端实现
*
* @author Xiangheng Li <xianghengl@jumei.com>
*
* @usage:
*  1, 复制或软链接 JMTextRpcClient.php 到具体的项目目录中
*  2, 添加 RpcServer 相关配置, 参考: examples/config/debug.php
*  3, 在 Controller 中添加 RPC 使用代码, 参考下面的例子
*
* @example
*
*      $userInfo = RpcClient_User_Info::instance();
*
*      # case 1
*      $result = $userInfo->getInfoByUid(100);
*      if (!JMTextRpcClient::hasErrors($result)) {
*          ...
*      }
*
*      # case 2
*      $userInfo->getInfoByUid(100, function ($result, $errors) {
*          if (!$errors) {
*              ...
*          }
*      });
*
*      # 其中 RpcClient_ 是接口调用约定
*      # RpcClient_User_Info::getInfoByUid 映射到
*      # WebService 中的 \User\Service\Info 类和 getInfoByUid 方法
*
* 用户认证算法
*
*      # 客户端
*      $packet = array(
*          'data' => json_encode(
*              array(
*                  'version' => '2.0',
*                  'user' => $this->rpcUser,
*                  'password' => md5($this->rpcUser . ':' . $this->rpcSecret),
*                  'timestamp' => microtime(true)); # 时间戳用于生成不同的签名, 以区分每一个独立请求
*                  'class' => $this->rpcClass,
*                  'method' => $method,
*                  'params' => $arguments,
*              )
*          ),
*      );
*      $packet['signature'] = $this->encrypt($packet['data'], $secret);
*
*      # 服务器端
*      # $this->encrypt($rawJsonData, $secret) === $packet['signature']
*
* 获取网络数据
*
*      JMTextRpcClient::on('send', function ($data) { });
*      JMTextRpcClient::on('recv', function ($data) { });
*/

/**
 * 客户端协议实现.
*/
class JMTextRpcClient
{

    /**
     * 异步发送前缀
     * @var string
     */
    const ASYNC_SEND_PREFIX = 'asend_';
    
    /**
     * 异步接收后缀
     * @var string
     */
    const ASYNC_RECV_PREFIX = 'arecv_';
    
    /**
     * 异步客户端实例
     * @var array [method.arguments=>instance, method.arguments=>instance]
     */
    private static $asyncInstances = array();
    
    
    private $connection;
    private $rpcClass;
    private $rpcHost;
    private $rpcPort;
    private $rpcUser;
    private $rpcSecret;
    private $executionTimeStart;

    private static $events = array();

    /**
     * 设置或读取配置信息.
     *
     * @param array $config 配置信息.
     *
     * @return array|void
     */
    public static function config(array $config = array())
    {
        static $_config = array();
        if (empty($config)) {
            return $_config;
        }
        $_config = $config;
    }

    /**
     * 获取RPC对象实例.
     *
     * @param array $config 配置信息, 或配置节点.
     *
     * @return JMTextRpcClient
     */
    public static function instance(array $config = array())
    {
        $className = get_called_class();

        static $instances = array();
        $key = $className . '-';
        if (empty($config)) {
            $key .= 'whatever';
        } else {
            $key .= md5(serialize($config));
        }
        if (empty($instances[$key])) {
            $instances[$key] = new $className($config);
            $instances[$key]->rpcClass = $className;
        }
        return $instances[$key];
    }

    /**
     * 检查返回结果是否包含错误信息.
     *
     * @param mixed $ctx 调用RPC接口时返回的数据.
     *
     * @return boolean
     */
    public static function hasErrors(&$ctx)
    {
        if (is_array($ctx)) {
            if (isset($ctx['error'])) {
                $ctx = $ctx['error'];
                return true;
            }
            if (isset($ctx['errors'])) {
                $ctx = $ctx['errors'];
                return true;
            }
        }
        return false;
    }

    /**
     * 注册各种事件回调函数.
     *
     * @param string   $eventName     事件名称, 如: read, recv.
     * @param function $eventCallback 回调函数.
     *
     * @return void
     */
    public static function on($eventName, $eventCallback)
    {
        if (empty(self::$events[$eventName])) {
            self::$events[$eventName] = array();
        }
        array_push(self::$events[$eventName], $eventCallback);
    }

    /**
     * 调用事件回调函数.
     *
     * @param $eventName 事件名称.
     *
     * @return void.
     */
    private static function emit($eventName)
    {
        if (!empty(self::$events[$eventName])) {
            $args = array_slice(func_get_args(), 1);
            foreach (self::$events[$eventName] as $callback) {
                @call_user_func_array($callback, $args);
            }
        }
    }

    /**
     * 构造函数.
     *
     * @param array $config 配置信息, 或配置节点.
     *
     * @throws Exception 抛出开发错误信息.
     */
    private function __construct(array $config = array(), $className = '')
    {
        if (empty($config)) {
            $config = self::config();
        } else {
            self::config($config);
        }

        if (empty($config)) {
            throw new Exception('JMTextRpcClient: Missing configurations');
        }

        if(empty($className))
        {
            $className = get_called_class();
        }
        else
        {
            $this->rpcClass = $className;
        }
        if (preg_match('/^[A-Za-z0-9]+_([A-Za-z0-9]+)_/', $className, $matches)) {
            $module = $matches[1];
            if (empty($config[$module])) {
                $this->init($config['Cart']);
                //throw new Exception(sprintf('JMTextRpcClient: Missing configuration for `%s`', $module));
            } else {
                $this->init($config[$module]);
            }
        } else {
            $this->init($config['Cart']);
        }

        // $this->openConnection();
    }

    /**
     * 析构函数.
     */
    public function __destruct()
    {
        // $this->closeConnection();
    }

    /**
     * 读取初始化配置信息.
     *
     * @param array $config 配置.
     *
     * @return void
     */
    public function init(array $config)
    {
        $this->rpcHost = $config['host'];
        $this->rpcPort = $config['port'];
        $this->rpcUser = $config['user'];
        $this->rpcSecret = $config['secret'];
        $this->rpcCompressor = isset($config['compressor']) ? strtoupper($config['compressor']) : null;
    }

    /**
     * 创建网络链接.
     *
     * @throws Exception 抛出链接错误信息.
     *
     * @return void
     */
    private function openConnection()
    {
        $client = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$client)
            $this->raiseSocketException();

        //socket_set_option($client, SOL_SOCKET, TCP_NODELAY, 0);
        //socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 60, 'usec' => 0));
        //socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 60, 'usec' => 0));

        if (!@socket_connect($client, $this->rpcHost, $this->rpcPort))
            $this->raiseSocketException($client);

        $this->connection = $client;
    }

    /**
     * 关闭网络链接.
     *
     * @return void
     */
    private function closeConnection()
    {
        @socket_close($this->connection);
    }

    /**
     * 抛出 Socket 异常信息.
     *
     * @param resource $client            Socket 句柄.
     * @param boolean  $withExecutionTime 是否记录执行时间.
     *
     * @throw Exception
     *
     * @return void
     */
    private function raiseSocketException($client = null, $withExecutionTime = false)
    {
        if ($client === null) $client = $this->connection;

        $errstr = socket_strerror(socket_last_error($client));
        @socket_close($client);

        throw new Exception($withExecutionTime
                        ? sprintf('JMTextRpcClient: %s:%s, %s(%.3fs)', $this->rpcHost, $this->rpcPort, $errstr, $this->executionTime())
                        : sprintf('JMTextRpcClient: %s:%s, %s', $this->rpcHost, $this->rpcPort, $errstr));
    }

    /**
     * 请求数据签名.
     *
     * @param string $data   待签名的数据.
     * @param string $secret 私钥.
     *
     * @return string
     */
    private function encrypt($data, $secret)
    {
        return md5($data . '&' . $secret);
    }

    /**
     * 调用 RPC 方法.
     *
     * @param string $method    PRC 方法名称.
     * @param mixed  $arguments 方法参数.
     *
     * @throws Exception 抛出开发用的错误提示信息.
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        // 是异步发送
        if(0 === strpos($method, self::ASYNC_SEND_PREFIX))
        {
            $real_method = substr($method, strlen(self::ASYNC_SEND_PREFIX));
            $instance_key = $method . serialize($arguments);
            if(isset(self::$asyncInstances[$instance_key]))
            {
                throw new Exception(get_class($this) . "->$method(".implode(',', $arguments).") has already called", 500);
            }
            self::$asyncInstances[$instance_key] = new $this->rpcClass(self::config(), $this->rpcClass);
            return self::$asyncInstances[$instance_key]->sendCallData($real_method, $arguments);
        }
        // 是异步接收
        elseif(0 === strpos($method, self::ASYNC_RECV_PREFIX))
        {
            $real_method = substr($method, strlen(self::ASYNC_RECV_PREFIX));
            $instance_key = self::ASYNC_SEND_PREFIX.$real_method . serialize($arguments);
            if(!isset(self::$asyncInstances[$instance_key]))
            {
                throw new Exception(get_class($this) . "->".self::ASYNC_SEND_PREFIX."$real_method(".implode(',', $arguments).") have not previously been called", 500);
            }
            $ctx = self::$asyncInstances[$instance_key]->recvCallData($real_method, $arguments);
            self::$asyncInstances[$instance_key] = null;
            unset(self::$asyncInstances[$instance_key]);
            return $ctx;
        }
        
        // 是同步
        $this->sendCallData($method, $arguments);
        $ctx = $this->recvCallData();

        $success = !(is_array($ctx) && isset($ctx['exception']) && is_array($ctx['exception']));

        if (!$success) {
            throw new Exception('RPC Exception: ' . var_export($ctx['exception'], true));
        }
        
        $fn = null;
        if (!empty($arguments) && is_callable($arguments[count($arguments) - 1])) {
            $fn = array_pop($arguments);
        }

        if ($fn === null)
            return $ctx;

        if ($this->hasErrors($ctx)) {
            $fn(null, $ctx);
        } else {
            $fn($ctx, null);
        }
    }
    
    protected function sendCallData($method, $arguments)
    {
        $sign = '' . $this->rpcSecret;
        
        $time_start = microtime(true);
        
        $data = array(
            'data' => json_encode(
                array(
                    'version' => '2.0',
                    'user' => $this->rpcUser,
                    'password' => md5($this->rpcUser . ':' . $this->rpcSecret),
                    'timestamp' => microtime(true),
                    'class' => $this->rpcClass,
                    'method' => $method,
                    'params' => $arguments,
                )
            ),
        );
        
        $config = self::config();
        $data['signature'] = $this->encrypt($data['data'], $config['rpc_secret_key']);
        $this->executionTimeStart = microtime(true);
        
        // 用 JSON 序列化请求数据
        if (!$data = json_encode($data)) {
            throw new Exception('JMTextRpcClient: Cannot serilize $data with json_encode');
        }
        
        // 压缩数据
        $command = 'RPC';
        if ($this->rpcCompressor === 'GZ') {
            $data = @gzcompress($data);
            $command .= ':GZ';
        } elseif ($this->rpcCompressor) {
            throw new Exception(sprintf('JMTextRpcClient: Unsupported compress method `%s`', $this->rpcCompressor));
        }
        
        $this->openConnection();
        
        return $this->send($command, $data);
    }
    
    protected function recvCallData()
    {
        $ctx = $this->recv();
        
        $this->closeConnection();
        
        // 反序列化 JSON 数据并返回
        if ($ctx !== '') {
            if ($this->rpcCompressor === 'GZ') {
                $ctx = @gzuncompress($ctx);
            }
            $ctx = json_decode($ctx, true);
            return $ctx;
        }
    }

    /**
     * 发起 RPC 调用协议.
     *
     * @param array $data RPC 数据.
     *
     * @throws Exception 抛出开发用的错误提示信息.
     *
     * @return mixed
     */
    private function remoteCall(array $data)
    {
        $ctx = $this->recv();

        $this->closeConnection();

        // 反序列化 JSON 数据并返回
        if ($ctx !== '') {
            if ($this->rpcCompressor === 'GZ') {
                $ctx = @gzuncompress($ctx);
            }
            $ctx = json_decode($ctx, true);
            return $ctx;
        }
    }

    /**
     * 独立的写接口.
     *
     * @param string $command RPC 命令.
     * @param string $data    RPC 数据.
     *
     * @return void
     */
    private function send($command, $data)
    {
        $client = $this->connection;

        $buffer = sprintf("%d\n%s\n%d\n%s\n", strlen($command), $command, strlen($data), $data);
        $buflen = $i = strlen($buffer);
        while ($i > 0) {
            $wrote = @socket_write($client, substr($buffer, $buflen - $i), $i);
            if ($wrote === false)
                $this->raiseSocketException(null, true);
            $i -= $wrote;
        }
        self::emit('send', $data);
    }

    /**
     * 独立的读接口.
     *
     * @return string
     */
    private function recv()
    {
        $client = $this->connection;

        // 读取 RPC 返回数据的长度信息
        $length = @socket_read($client, 10, PHP_NORMAL_READ);
        if ($length === false)
            $this->raiseSocketException(null, true);

        $length = trim($length);
        if (!ctype_digit($length)) {
            throw new Exception(sprintf('JMTextRpcClient: Got wrong protocol codes: %s', bin2hex($length)));
        }

        // 读取 RPC 返回的具体数据
        $ctx = '';
        while ($length > 0) {
            $buffer = socket_read($client, 4096);
            if ($buffer === false)
                $this->raiseSocketException(null, true);

            $ctx .= $buffer;
            $length -= strlen($buffer);
        }
        $ctx = trim($ctx);
        self::emit('recv', $ctx);

        return $ctx;
    }

    /**
     * 计算 RPC 请求时间.
     *
     * @return float
     */
    private function executionTime()
    {
        return microtime(true) - $this->executionTimeStart;
    }

}

spl_autoload_register(
                function ($className) {
    if (strpos($className, 'RpcClient_') !== 0)
        return false;

    eval(sprintf('class %s extends JMTextRpcClient {}', $className));
}
);







    
