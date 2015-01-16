<?php 

/**
 * 
 * 如果开发环境没安装inotify，则用这个worker监控文件更新
 * 
 * @author liangl
 *
 */
class FileMonitor extends PHPServerWorker
{
    
    const CMD_TELL_INCLUDE_FILES = 1;
    
    // 该进程是否在重新载入中
    protected $isReloading = false;
    // 文件修改时间映射
    protected $lastMtimeMap = array(); 
    // 需要监控的文件
    protected $filesToInotify = array();
    
    /**
     * 确定包是否完整
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return JMProtocol::checkInput($recv_str);
    }
    
    /**
     * 处理业务
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        if($this->isReloading)
        {
            return;
        }
        $data = JMProtocol::bufferToData($recv_str);
        switch ($data['sub_cmd'])
        {
            // 开发环境如果没有inotify扩展，则用这个进程监控文件更新
            case self::CMD_TELL_INCLUDE_FILES:
                if(PHPServerConfig::get('ENV') !== 'dev')
                {
                    return;
                }
                clearstatcache();
                if($files = json_decode($data['body'], true))
                {
                    foreach($files as $file)
                    {
                        if(!isset($this->filesToInotify[$file]))
                        {
                            $stat = @stat($file);
                            $mtime = isset($stat['mtime']) ? $stat['mtime'] : 0;
                            $this->filesToInotify[$file] = $mtime;
                        }
                    }
                }
                break;
        }
    }
    
    
    /**
     * 该worker进程开始服务的时候会触发一次
     * @return bool
     */
    protected function onServe()
    {
        // 文件更新相关 mac不支持inotify 用这个进程监控 500ms检测一次
        if(!Inotify::isSuport() && PHPServerConfig::get('ENV') == 'dev')
        {
            $this->eventLoopName = 'Select';
            
            // 安装信号处理函数
            $this->installSignal();
            
            $this->event = new $this->eventLoopName();
            
            if($this->protocol == 'udp')
            {
                // 添加读udp事件
                $this->event->add($this->mainSocket,  BaseEvent::EV_ACCEPT, array($this, 'recvUdp'));
            }
            else
            {
                // 添加accept事件 
                $this->event->add($this->mainSocket,  BaseEvent::EV_ACCEPT, array($this, 'accept'));
            }
            
            // 添加管道可读事件 
            $this->event->add($this->channel,  BaseEvent::EV_READ, array($this, 'dealCmd'), 0, 0);
            
            // 增加select超时事件
            $this->event->add(0, Select::EV_SELECT_TIMEOUT, array($this, 'checkFilesModify'), array() , 500);
            
            // 主体循环  
            while(1)
            {
                $ret = $this->event->loop();
                $this->notice("evet->loop returned " . var_export($ret, true));
            }
            
            return true;
        }
        
    }
    
    /**
     * 检查文件更新时间，如果有更改则平滑重启服务（开发的时候用到）
     * @return void
     */
    public function checkFilesModify()
    {
        if($this->isReloading)
        {
            return;
        }
        
        foreach($this->filesToInotify as $file=>$mtime)
        {
            clearstatcache();
            $stat = @stat($file);
            if(false === $stat)
            {
                unset($this->filesToInotify[$file]);
                continue;
            }
            $mtime_now = $stat['mtime'];
            if($mtime != $mtime_now) 
            {
                ServerLog::add("$file updated and reload workers");
                $master_pid = file_get_contents(PID_FILE);
                if($master_pid)
                {
                    posix_kill($master_pid, SIGUSR2);
                    $this->isReloading = true;
                }
            }
        }
    }
    
} 
