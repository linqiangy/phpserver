<?php
namespace thirdparty\MNLogger;

class DATALogger extends Base{
    protected static $filePermission = 0777;
    protected $_logdirBaseName = 'data';
    protected static $configs=array();
    protected static $instance = array();
    public function log($key, $data)
    {
        if ($this->_on === self::OFF) {
            return;
        }
        $time = date('Y-m-d H:i:s');
        //交给应用层序列化
        //$data = $this->serializeData($data);
        $line = "OWL\001DATA\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001DATA\001{$key}\001{$data}\004\n";

        if (!$this->_fileHandle) {
            $this->_fileHandle = fopen($this->_logFilePath, 'a');
            if (!$this->_fileHandle) {
                throw new \Exception('Can not open file: ' . $this->_logFilePath);
            }
        }
        if (!fwrite($this->_fileHandle, $line)) {
            throw new \Exception('Can not append to file: ' . $this->_logFilePath);
        }
    }
}
