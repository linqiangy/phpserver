<?php 
/**
 * 
 * 定时任务
 * 
 * @author liangl
 *
 */
class TaskWorker extends PHPServerWorker
{
    /**
     * 多少毫秒执行一次onTime
     * @var int
     */
    protected $timeIntervalMS = 1000; 
    
    /**
     * 上次运行onTime的时间
     * @var int
     */
    protected $lastCallOnTime = 0;
    
    /**
     * 定时任务留空
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return 0;
    }
    
    /**
     * 定时任务留空
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        
    }
    
    
    /**
     * 该worker进程开始服务的时候会触发一次
     * @return bool
     */
    protected function onServe()
    {
        $this->eventLoopName = 'Select';
        $time_interval = PHPServerConfig::get('workers.'.$this->serviceName.'.time_interval_ms');
        if($time_interval > 1)
        {
            $this->timeIntervalMS = $time_interval;
        }
        
        $this->installSignal();
        
        $this->event = new $this->eventLoopName();
        
        // 添加管道可读事件
        $this->event->add($this->channel,  BaseEvent::EV_READ, array($this, 'dealCmd'), 0, 0);
        
        // 增加select超时事件
        $this->event->add(0, Select::EV_SELECT_TIMEOUT, array($this, 'onTime'), array() , $this->timeIntervalMS);
        
        $bootstrap = PHPServerConfig::get('workers.'.$this->serviceName.'.bootstrap');
        if(is_file($bootstrap))
        {
            require_once $bootstrap;
        }
        if(function_exists('on_start'))
        {
            call_user_func('on_start');
        }
        $this->lastCallOnTime = microtime(true);
        // 主体循环
        while(1)
        {
            $ret = $this->event->loop();
            $this->notice("evet->loop returned " . var_export($ret, true));
        }
        
    }
    
    /**
     * 处理住进成发过来的命令
     * @see PHPServerWorker::dealCmd()
     */
    public function dealCmd($channel, $length, $buffer)
    {
        $this->onTime();
        parent::dealCmd($channel, $length, $buffer);
        $this->onTime();
    }
    
    /**
     * 进程停止时(./bin/serverd stop/restart)触发
     * @see PHPServerWorker::onStopServe()
     */
    protected function onStopServe()
    {
        if(function_exists('on_stop'))
        {
            call_user_func('on_stop');
        }
    }
    
    /**
     * 每当到达设定的时间时触发
     */
    public function onTime()
    {
        $time_now = microtime(true);
        if(($time_now - $this->lastCallOnTime)*1000 >= $this->timeIntervalMS)
        {
            $this->lastCallOnTime = $time_now;
            if(function_exists('on_time'))
            {
                StatisticClient::tick($this->serviceName, 'on_time');
                try{
                    call_user_func('on_time');
                    StatisticClient::report($this->serviceName, 'on_time');
                }
                catch (Exception $e)
                {
                    StatisticClient::report($this->serviceName, 'on_time', $e->getMessage(), $e, false, $this->getLocalIp());
                }
            }
        }
        $time_diff = ($this->lastCallOnTime*1000 + $this->timeIntervalMS) - microtime(true)*1000;
        if($time_diff <= 0)
        {
            call_user_func(array($this, 'onTime'));
        }
        else
        {
            $this->event->setReadTimeOut($time_diff);
        }
    }
    
}

