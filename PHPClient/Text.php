<?php
namespace PHPClient;

use \Exception;

class Text extends JMTextRpcClient{
    protected static $instances = array();
    protected $rpcClass;
    protected $configName;
    /**
     * @param string|array $config 服务的配置名称或配置内容
     *
     * @return  static
     */
    public static function inst($config)
    {
        if(is_array($config))
        {
            $configName = md5(serialize($config));
            $allConfig = parent::config();
            if(!isset($allConfig[$configName]))
            {
                parent::config(array_merge($allConfig, $config));
            }
        }
        else
        {
            $configName = $config;
        }
        if(!isset(static::$instances[$configName]))
        {
            static::$instances[$configName] = new static($configName);
        }
        return static::$instances[$configName];
    }

    protected function __construct($configName)
    {
        $config = parent::config();
        if(empty($config) && class_exists('\Config\PHPClient'))
        {
            $config = (array) new \Config\PHPClient;
            parent::config($config);
        }

        if (empty($config)) {
            throw new Exception('JMTextRpcClient: Missing configurations');
        }

        if (empty($config[$configName]))
        {
            throw new Exception(sprintf('JMTextRpcClient: Missing configuration for `%s`', $configName));
        } else {
            $this->configName = $this->appName = $configName;
            $this->init($config[$configName]);
        }
    }

    /**
     * @param string $name Service classname to use.
     * @return $this
     */
    public function setClass($name)
    {
        $config = parent::config();
        if(isset($config[$this->configName]['ver']) && version_compare($config[$this->configName]['ver'], '2.0', '<'))
        {
            $className = 'RpcClient_'.$this->configName.'_'.$name;
        }
        else
        {
            $className = 'RpcClient_'.$name;
        }
        $this->rpcClass = $className;
        return $this;
    }
}