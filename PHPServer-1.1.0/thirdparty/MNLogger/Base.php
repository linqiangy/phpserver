<?php
namespace thirdparty\MNLogger;

class Base{
    const OFF = false;
    protected $_logFilePath = null;
    protected $_fileHandle = null;
    protected $_hostname = null;
    protected $_ip = null;
    protected $_app = null;
    protected $_on = false;
    protected static $defaultConfig = array(
        'on' => true,
        'app' => 'DefaultSetting',
        'logdir' => '/home/logs/monitor/'

    );

    /**
     * Initialize parameters/configs of logger. Then Logger::instance($configname) can retrieve an instance by config name.
     *
     * @param array $config
     */
    public static function setUp(array $config)
    {
        static::$configs = $config;
    }

    /**
     * @param string|array $config  config name or content.
     * @return static
     * @throws \Exception
     */
    public static function instance($config)
    {
        if(is_string($config))
        {
            if(empty(static::$configs))
            {
                // Load config by common rules {@link http://wiki.int.jumei.com/index.php?title=PHP%E9%A1%B9%E7%9B%AE/%E7%B1%BB%E5%BA%93%E5%BC%80%E5%8F%91%E4%B8%8E%E9%9B%86%E6%88%90%E8%A7%84%E8%8C%83#.E9.85.8D.E7.BD.AE.E8.87.AA.E5.8A.A8.E5.8A.A0.E8.BD.BD.E6.94.AF.E6.8C.81}
                if(class_exists('\Config\MNLogger'))
                {
                    static::$configs = (array) new \Config\MNLogger;
                }
                else
                {
                    static::$configs[$config] = static::$defaultConfig;
                }
            }
            if(!isset(static::$configs[$config]))
            {
                throw new Exception("$config not exists, is it configured with ".__CLASS__.'::setUp() ?');
            }
            else
            {
                $config = static::$configs[$config];
            }
        }
        if(!$config['app'] || !$config['logdir']) {
            throw new Exception("Please check the config params.\n");
        }
        $config_key = $config['app']. '_'. $config['logdir'];
        if (isset(static::$instance[$config_key])) {
            return static::$instance[$config_key];
        }
        static::$instance[$config_key] = new static($config);
        return static::$instance[$config_key];
    }

    public function __construct($config)
    {
        $this->_on = $config['on'];
        if(!$config['app'] || !$config['logdir']) {
            throw new \Exception("Please check the config params.\n");
        }
        if ($this->_on === static::OFF) {
            return;
        }
        $this->_app = $config['app'];
        $this->_ip = $this->getIp();
        $this->_logdir = $config['logdir']. DIRECTORY_SEPARATOR. $this->_app. DIRECTORY_SEPARATOR. $this->_logdirBaseName;

        date_default_timezone_set('PRC');
        $this->_logFilePath = $this->_logdir
            . DIRECTORY_SEPARATOR
            . $this->_app
            . '.'
            . date('Ymd')
            . '.log';
        if (!file_exists($this->_logdir)) {
            umask(0);
            if (!mkdir($this->_logdir, static::$filePermission, true)) {
                throw new \Exception('Can not mkdir: ' . $this->_logdir);
            }
        }

        if (file_exists($this->_logFilePath) && !is_writable($this->_logFilePath)) {
            throw new \Exception('Can not write monitor log file: ' . $this->_logFilePath . "\n");
        }
    }


    protected function getIp()
    {
        static $ip;
        if($ip !== null)
        {
            return $ip;
        }
            
        $ip = gethostbyname(trim(`hostname`));
        return $ip;
    }

    public function __destruct()
    {
        if ($this->_fileHandle) {
            fclose($this->_fileHandle);
        }
    }

    /**
     * Unified serialization method.(using json_encode without unicode escape).
     * @param mixed $data
     * @return string
     */
    public function serializeData($data)
    {
        return json_encode($data, 256);
    }
}
