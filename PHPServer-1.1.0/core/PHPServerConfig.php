<?php

# echo PHPServerConfig::get('workers.JumeiWorker.framework.path'), PHP_EOL;
# var_dump(PHPServerConfig::get('workers'));

class PHPServerConfig
{
    public $filename;
    public $config;

    private function __construct($domain = 'main') {
        $folder = __dir__ . '/../config';
        $filename = $folder . '/' . $domain;
        if (!empty($_SERVER['JM_ENV'])) {
            $filename .= '-' . $_SERVER['JM_ENV'];
        }
        $filename .= '.php';

        if (!file_exists($filename)) {
            throw new Exception('Configuration file "' . $filename . '" not found');
        }

        $config = include $filename;
        if (!is_array($config) || empty($config)) {
            throw new Exception('Invalid configuration file format');
        }

        $this->config = $config;
        $this->filename = realpath($filename);
    }

    public static function instance($domain = 'main')
    {
        static $instances = array();
        if (empty($instances[$domain])) {
            $instances[$domain] = new self;
        }
        return $instances[$domain];
    }

    public static function get($uri, $domain = 'main')
    {
        $node = self::instance($domain)->config;

        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return null;
            }
            $node = $node[$path];
        }

        return $node;
    }
}
