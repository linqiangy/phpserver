<?php
namespace thirdparty\MNLogger;

class MNLogger extends Base{
    protected static $filePermission = 0777;
    protected $_logdirBaseName = 'stats';
    protected static $configs=array();
    protected static $instance = array();

    // log('mobile,send', '1');
    public function log($keys, $vals)
    {
        if ($this->_on === self::OFF) {
            return;
        }
        $keys_len = count(explode(',', $keys));
        $vals_len = count(explode(',', $vals));

        if($keys_len > 6) {
            throw new \Exception('Keys count should be <= 6.');
        }

        if($vals_len > 4) {
            throw new \Exception('Values count should be <= 4.');
        }

        $keys = str_replace(",", "\003", $keys);
        $vals = str_replace(",", "\003", $vals);

        $time = date('Y-m-d H:i:s');
        $line = "OWL\001STATS\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$keys}\001{$vals}\004\n";

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
