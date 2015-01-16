<?php
/**
 * 标准 RpcWorker.
 *
 * @author Xiangheng Li <xianghengl@jumei.com>
 */

/**
 * RpcWorker 抽象类实现.
 */
abstract class RpcWorker extends PHPServerWorker implements IWorker
{

    /**
     * 压缩方法.
     */
    private $rpcCompressor;

    /**
     * 验证数据是否接收完整.
     *
     * @param string $recv_str 接收到的数据流.
     *
     * @return integer|boolean
     */
    public function dealInput($recv_str)
    {
        return Text::input($recv_str);
    }

    /**
     * 处理数据流.
     *
     * @param string $recv_str 接收到的数据流.
     *
     * @throws Exception 抛出开发时错误.
     *
     * @return void
     */
    public function dealProcess($recv_str)
    {
        try {
            if (($data = Text::decode($recv_str)) === false) {
                throw new Exception('RpcWorker: You want to check the RPC protocol.');
            }

            if ($data['command'] === 'TEST' && $data['data'] === 'PING') {
                $this->send('PONG');
                return;
            }

            $this->rpcCompressor = null;
            if (strpos($data['command'], 'RPC:') === 0) {
                $this->rpcCompressor = substr($data['command'], strpos($data['command'], ':') + 1);
            } elseif ($data['command'] !== 'RPC') {
                throw new Exception('RpcWorker: Oops! I am going to do nothing but RPC.');
            }
            
            $data = $data['data'];

            if ($this->rpcCompressor === 'GZ') {
                $data = @gzuncompress($data);
            }
            $packet = json_decode($data, true);
            
            global $context, $owl_context;
            $context = array();
            if(isset($packet['CONTEXT']))
            {
                $context = $packet['CONTEXT'];
            }
            $owl_context = null;
            if(isset($context['owl_context']))
            {
                $owl_context = json_decode($context['owl_context'], true);
            }

            if ($this->encrypt($packet['data'], PHPServerConfig::get('rpc_secret_key')) !== $packet['signature']) {
                throw new Exception('RpcWorker: You want to check the RPC secret key, or the packet has broken.');
            }

            $data = json_decode($packet['data'], true);
            if (empty($data['version']) || $data['version'] !== '2.0') {
                throw new Exception('RpcWorker: Hmm! We are now expect version 2.0.');
            }

            $prefix = 'RpcClient_';
            if (strpos($data['class'], $prefix) !== 0) {
                throw new Exception(sprintf('RpcWorker: Mmm! RPC class name should be prefix with %s.', $prefix));
            }
            $data['class'] = substr($data['class'], strlen($prefix));
            
            // 检查权限
            $_SERVER['REMOTE_ADDR'] = $this->getRemoteIp();
            if($_SERVER['REMOTE_ADDR'] !== '127.0.0.1')
            {
	            if(!PHPServerWorker::hasAuth($this->serviceName, $data['class'], $data['method'], $_SERVER['REMOTE_ADDR']))
	            {
	            	throw new \Exception("{$_SERVER['REMOTE_ADDR']} has no permissions to access {$this->serviceName} {$data['class']}->{$data['method']}. Permission denied.");
	            }
            }

            $this->process($data);
        } catch (Exception $ex) {
            $this->send(
                array(
                    'exception' => array(
                        'class' => get_class($ex),
                        'message' => $ex->getMessage(),
                        'code' => $ex->getCode(),
                        'file' => $ex->getFile(),
                        'line' => $ex->getLine(),
                        'traceAsString' => $ex->getTraceAsString(),
                    )
                )
            );
        }
    }

    /**
     * 业务处理方法.
     *
     * @param mixed $data RPC 请求数据.
     *
     * @return void
     */
    abstract protected function process($data);

    /**
     * 发送数据回客户端.
     *
     * @param mixed $data 业务数据.
     *
     * @return void
     */
    protected function send($data)
    {
        $data = json_encode($data);
        if ($this->rpcCompressor === 'GZ') {
            $data = @gzcompress($data);
        }
        $this->sendToClient(Text::encode($data));
    }

    /**
     * 数据签名.
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
}
