<?php
namespace thirdparty\MNLogger;

class TraceLogger extends Base{
    protected static $filePermission = 0777;
    protected $_logdirBaseName = 'trace';
    protected static $configs=array();
    protected static $instance = array();

    private $_samplePerRequest = 100;

    private $_tempId = null;

    public function setSamplePerRequest($samplePerRequest) {
        if($samplePerRequest < 1 || $samplePerRequest > 5000) {
            throw new Exception("The samplePerRequest can not be < 1 or > 5000.\n");
        }
        $this->_samplePerRequest = $samplePerRequest;
    }

    // HTTP 

    public function HTTP_SR()
    {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(isset($_SERVER['HTTP_X_OWL_RID'])) {
            $owl_context['uuid'] = $_SERVER['HTTP_X_OWL_RID'];
        } else {
            return;
        }

        if(!$this->isSample() && (!isset($owl_context['parent_id']) || !isset($owl_context['uuid']))) {
            return;
        }

        if(!isset($owl_context['parent_id'])) {
            $owl_context['parent_id'] = 0;    
        } else {
            $owl_context['parent_id'] = $owl_context['trace_id'];
        }

        if(!isset($owl_context['uuid'])) {
            $owl_context['uuid'] = 'uuid-'. uniqid();    
        }
        $owl_context['trace_id'] = uniqid();

        $call_type = $call_name = $end_point = $attachment = '';

        $type = 'SR';
        $call_type = 'HTTP';
        $call_name = 'http.'. $_SERVER['REQUEST_METHOD'];
        $end_point = $_SERVER['SERVER_ADDR']. ':'. $_SERVER['SERVER_PORT'];
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $parent_id = $owl_context['parent_id'];
        $id = $owl_context['trace_id'];
        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function HTTP_SS($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
	    return;
        }

        $call_type = $call_name = $end_point = $attachment = '';

        $type = 'SS';
        
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $parent_id = $owl_context['parent_id'];
        $id = $owl_context['trace_id'];
        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";
        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function HTTP_SERVICE_SR() {

        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!$this->isSample() && (!isset($owl_context['parent_id']) || !isset($owl_context['uuid']))) {
            return;
        }

        if(!isset($owl_context['parent_id'])) {
            $owl_context['parent_id'] = 0;    
        } else {
            $owl_context['parent_id'] = $owl_context['trace_id'];
        }

        if(!isset($owl_context['uuid'])) {
            $owl_context['uuid'] = 'uuid-'. uniqid();    
        }

        $owl_context['trace_id'] = uniqid();

        $call_type = $call_name = $end_point = $attachment = $url = $method = $data = $client_app_name = '';

        if(isset($owl_context['app_name'])) {
            $client_app_name = $owl_context['app_name'];    
        }
        
        $type = 'SR';
        $call_type = 'HTTP_SERVICE';
        $call_name = 'http.'. $_SERVER['REQUEST_METHOD'];
        $end_point = $_SERVER['SERVER_ADDR']. ':'. $_SERVER['SERVER_PORT'];
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $parent_id = $owl_context['parent_id'];
        $id = $owl_context['trace_id'];

        $url = $_SERVER['REQUEST_URI'];
        $method = $_SERVER['REQUEST_METHOD'];
        if($method === 'POST') {
            $data = file_get_contents("php://input");    
        }
        $attachment = "URL\003{$url}\002METHOD\003{$method}\002DATA\003{$data}\002CLIENT\003{$client_app_name}";
        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function HTTP_SERVICE_SS($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $call_type = $call_name = $end_point = $attachment = '';

        $type = 'SS';
        
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $parent_id = $owl_context['parent_id'];
        $id = $owl_context['trace_id'];
        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";
        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function HTTP_CS($url, $method, $data) {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $data = $this->serializeData($data);
        $parent_id = $owl_context['trace_id'];
        $this->_tempId = $id = uniqid();

        $call_type = $call_name = $end_point = $attachment = '';    

        $type = 'CS';
        $call_type = 'HTTP_CLIENT';
        $call_name = 'httpClient.'. $method;
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "URL\003{$url}\002METHOD\003{$method}\002DATA\003{$data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function HTTP_CR($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $response_data = $this->serializeData($response_data);
        $parent_id = $owl_context['trace_id'];
        $id = $this->_tempId; 

        $call_type = $call_name = $end_point = $attachment = '';   

        $type = 'CR';

        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function MYSQL_CS($end_point, $method, $sql, $sql_id = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $parent_id = $owl_context['trace_id'];
        $this->_tempId = $id = uniqid();  

        $call_type = $call_name = $attachment = '';  

        $type = 'CS';
        $call_type = 'MYSQL';
        $call_name = 'MYSQL.'. $method;
        
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "SQL\003{$sql}\002SQL_ID\003{$sql_id}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function MYSQL_CR($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $response_data = $this->serializeData($response_data);
        $parent_id = $owl_context['trace_id'];
        $id = $this->_tempId;

        $call_type = $call_name = $end_point = $attachment = '';   

        $type = 'CR';
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    // RPC

    public function RPC_SR($service, $method, $params)
    {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!$this->isSample() && (!isset($owl_context['parent_id']) || !isset($owl_context['uuid']))) {
            return;
        }

        $params = $this->serializeData($params);
        if(!isset($owl_context['parent_id'])) {
            $owl_context['parent_id'] = 0;    
        } else {
            $owl_context['parent_id'] = $owl_context['trace_id'];
        }

        if(!isset($owl_context['uuid'])) {
            $owl_context['uuid'] = 'uuid-'. uniqid();    
        }
        $owl_context['trace_id'] = uniqid();

        $call_type = $call_name = $end_point = $attachment = $client_app_name = '';

        if(isset($owl_context['app_name'])) {
            $client_app_name = $owl_context['app_name'];
        }

        $type = 'SR';
        $call_type = 'RPC';
        $call_name = $service. '::'. $method;
        // todo: get client end point at server side of RPC.
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $parent_id = $owl_context['parent_id'];
        $id = $owl_context['trace_id'];

        $attachment = "PARAMS\003{$params}\002CLIENT\003{$client_app_name}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function RPC_SS($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $call_type = $call_name = $end_point = $attachment = '';

        $type = 'SS';
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $parent_id = $owl_context['parent_id'];
        $id = $owl_context['trace_id'];
        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";
        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function RPC_CS($end_point, $service, $method, $params) {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $params = $this->serializeData($params);
        $parent_id = $owl_context['trace_id'];
        $this->_tempId = $id = uniqid();

        $call_type = $call_name = $attachment = '';    

        $type = 'CS';
        $call_type = 'RPC';
        $call_name = $service. '::'. $method;
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "PARAMS\003{$params}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function RPC_CR($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $response_data = $this->serializeData($response_data);
        $parent_id = $owl_context['trace_id'];
        $id = $this->_tempId; 

        $call_type = $call_name = $end_point = $attachment = '';   

        $type = 'CR';
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');
        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    // Memcache

    public function MC_CS($end_point, $method, $query) {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $parent_id = $owl_context['trace_id'];
        $this->_tempId = $id = uniqid();

        $call_type = $call_name = $attachment = '';    

        $type = 'CS';
        $call_type = 'MC';
        $call_name = 'MC.'. $method;
        
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "QUERY\003{$query}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function MC_CR($response_type, $response_data_size, $response_data) {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $response_data = $this->serializeData($response_data);
        $parent_id = $owl_context['trace_id'];
        $id = $this->_tempId;

        $call_type = $call_name = $end_point = $attachment = '';    

        $type = 'CR';
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    // Redis

    public function REDIS_CS($end_point, $method, $query) {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $parent_id = $owl_context['trace_id'];
        $this->_tempId = $id = uniqid();

        $call_type = $call_name = $attachment = '';    

        $type = 'CS';
        $call_type = 'REDIS';
        $call_name = 'REDIS.'. $method;
        
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "QUERY\003{$query}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function REDIS_CR($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $response_data = $this->serializeData($response_data);
        $parent_id = $owl_context['trace_id'];
        $id = $this->_tempId;

        $call_type = $call_name = $end_point = $attachment = '';    

        $type = 'CR';
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    // MQ

    public function RABBITMQ_CS($end_point, $method, $data) {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $data = $this->serializeData($data);
        $parent_id = $owl_context['trace_id'];
        $this->_tempId = $id = uniqid();

        $call_type = $call_name = $attachment = '';    

        $type = 'CS';
        $call_type = 'MQ';
        $call_name = 'MQ.'. $method;
        
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "DATA\003{$data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    public function RABBITMQ_CR($response_type, $response_data_size, $response_data = '') {
        if ($this->_on === self::OFF) {
            return;
        }

        global $owl_context;

        if(!isset($owl_context['parent_id']) || !isset($owl_context['uuid'])) {
            return;
        }

        $response_data = $this->serializeData($response_data);
        $parent_id = $owl_context['trace_id'];
        $id = $this->_tempId;

        $call_type = $call_name = $end_point = $attachment = '';    

        $type = 'CR';
        $timestamp = $this->microTimeStamp();
        $time = date('Y-m-d H:i:s');

        $attachment = "RESPONSE_TYPE\003{$response_type}\002DATA_SIZE\003{$response_data_size}\002DATA\003{$response_data}";

        $line = "OWL\001TRACE\0010002\001{$this->_app}\001{$time}.000\001{$this->_ip}\001{$type}\001{$owl_context['uuid']}\001{$parent_id}\001{$id}\001{$call_type}\001{$call_name}\001{$end_point}\001{$timestamp}\001{$attachment}\004\n";
        $this->write($line);
    }

    private function microTimeStamp() {
        return (int)(microtime(true)*1000);
    }

    private function isSample() {
        return mt_rand(1, $this->_samplePerRequest) === 1;
    }

    private function write($line) {

        if ($this->_on === self::OFF) {
            return;
        }

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
