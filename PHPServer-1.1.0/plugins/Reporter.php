<?php

/**
 * 
 * 上报相关（mac 不支持inotify，使用这个类上报worker进程使用文件给FileMonitor，由FileMonitor进程监控）
 * 
 * @author visli
 *
 */
class Reporter
{
    const CMD_TELL_INCLUDE_FILES = 1;
    // mac:9216 linux:65507
    const MAX_UDP_PACKAGE_SIZE = 9216;
    
    /**
     * 上报包含文件到监控进程
     * @param array $files
     */
    public static function reportIncludedFiles($worker_files_map)
    {
        //非开发环境或者安装了inotify扩展直接返回
        if(PHPServerConfig::get('ENV') !== 'dev' || Inotify::isSuport())
        {
            return;
        }
        if($worker_files_map && is_array($worker_files_map))
        {
            $files = array();
            foreach($worker_files_map as $worker_name => $files_array)
            {
                foreach($files_array as $file)
                {
                    $files[$file] = $file;
                }
            }
            if($files)
            {
                return self::sendData(self::CMD_TELL_INCLUDE_FILES, array_values($files));
            }
        }
    }
    
    /**
     * 发送数据到监控进程
     * @param int $cmd
     * @param mix $data
     * @param string $address
     */
    protected static function sendData($cmd, $data, $address = 'udp://0.0.0.0:10203')
    {
        if(class_exists('JMProtocol'))
        {
            $packge = new JMProtocol();
            $packge->header['sub_cmd'] = $cmd;
            $packge->body = json_encode($data);
            $socket = stream_socket_client($address);
            $buffer = $packge->getBuffer();
            $buffer_len = strlen($buffer);
            if($buffer_len <= self::MAX_UDP_PACKAGE_SIZE)
            {
                stream_socket_sendto($socket, $buffer);
            }
            else
            {
                $chunk_count = ceil($buffer_len/self::MAX_UDP_PACKAGE_SIZE);
                $chunk_count = $chunk_count == 1 ? 2 : $chunk_count;
                foreach(array_chunk($data, ceil(count($data)/$chunk_count)) as $chunk_data)
                {
                    self::sendData($cmd, $chunk_data);
                }
            }
        }
    }
}
