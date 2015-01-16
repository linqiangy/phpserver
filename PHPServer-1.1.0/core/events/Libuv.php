<?php 

/**
 * 
 * libuv事件轮询库的封装
 * 测试发现该库有内存泄露，不建议使用
 * 
 * @see https://github.com/chobie/php-uv
 * @author liangl3
 *
 */
class Libuv implements BaseEvent
{
    public $eventBase = null;
    
    public $allEvents = array();
    
    public $eventBuffer = array();
    
    public $eventSignal = array();
    
    public function __construct()
    {
        $this->eventBase = uv_default_loop();
    }
   
    /**
     * 事件添加
     * @see BaseEvent::add()
     */
    public function add($fd, $flag, $func, $args = null, $read_timeout = 1000, $write_timeout = 1000)
    {
        $event_key = (int)$fd;
        
        switch ($flag)
        {
            case self::EV_ACCEPT:
                $this->allEvents[$event_key][self::EV_ACCEPT] = array('poll'=>uv_poll_init($this->eventBase, $fd), 'args'=>$args, 'func'=>$func);
                uv_poll_start($this->allEvents[$event_key][self::EV_ACCEPT]['poll'], UV::READABLE, array($this, 'accept'));
                return true;
                break;
            case self::EV_READ:
                $this->allEvents[$event_key][self::EV_READ] = array('poll'=>uv_poll_init($this->eventBase, $fd), 'args'=>$args, 'func'=>$func);
                uv_poll_start($this->allEvents[$event_key][self::EV_READ]['poll'], UV::READABLE, array($this, 'bufferCallBack'));
                
                return true;
            case self::EV_WRITE:
                break;
            case self::EV_SIGNAL:
                $this->allEvents[$event_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                return true;
            case self::EV_NOINOTIFY:
                $this->allEvents[$event_key][self::EV_NOINOTIFY] = array('poll'=>uv_poll_init($this->eventBase, $fd), 'args'=>$args, 'func'=>$func);
                uv_poll_start($this->allEvents[$event_key][self::EV_NOINOTIFY]['poll'], UV::READABLE, array($this, 'notify'));
                return true;
                break;
        }
        
        return true;
    }
    
    public function accept($poll, $stat, $ev, $conn)
    {
        $event_key = (int)$conn;
        call_user_func_array($this->allEvents[$event_key][self::EV_ACCEPT]['func'], array($conn, BaseEvent::EV_ACCEPT));
    }
    
    public function signalHandler($signal)
    {
        $event_key = (int)$conn;
        call_user_func_array($this->allEvents[$signal][self::EV_SIGNAL]['func'], array(null, self::EV_SIGNAL, $signal));
    }
    
    public function notify($poll, $stat, $ev, $conn)
    {
        $event_key = (int)$conn;
        call_user_func_array($this->allEvents[$event_key][self::EV_NOINOTIFY]['func'], array($conn, BaseEvent::EV_NOINOTIFY, $this->allEvents[$event_key][self::EV_NOINOTIFY]['args']));
    }
    
    public function bufferCallBack($poll, $stat, $ev, $conn)
    {
        $event_key = (int)$conn;
        $data = '';
        while ($tmp = fread($conn, 10240))
        {
            $data .= $tmp;
        }
        call_user_func_array($this->allEvents[$event_key][self::EV_READ]['func'], array($conn, strlen($data), $data, $this->allEvents[$event_key][self::EV_READ]['args']));
    }
    
    public function del($fd ,$flag)
    {
        $event_key = (int)$fd;
        switch ($flag)
        {
            case self::EV_ACCEPT:
                if(isset($this->allEvents[$event_key][self::EV_ACCEPT]))
                {
                    uv_poll_stop($this->allEvents[$event_key][self::EV_ACCEPT]['poll']);
                }
                unset($this->allEvents[$event_key][self::EV_ACCEPT]);
                return true;
                break;
            case self::EV_READ:
                if(isset($this->allEvents[$event_key][self::EV_READ]))
                {
                    uv_poll_stop($this->allEvents[$event_key][self::EV_READ]['poll']);
                }
                unset($this->allEvents[$event_key][self::EV_READ]);
                return true;
            case self::EV_WRITE:
                break;
            case self::EV_SIGNAL:
                pcntl_signal($fd, SIG_IGN);
                return true;
            case self::EV_NOINOTIFY:
                if(isset($this->allEvents[$event_key][self::EV_NOINOTIFY]))
                {
                    uv_poll_stop($this->allEvents[$event_key][self::EV_NOINOTIFY]['poll']);
                }
                unset($this->allEvents[$event_key][self::EV_NOINOTIFY]);
                return true;
                break;
        }
        if(empty($this->allEvents[$event_key]))
        {
            unset($this->allEvents[$event_key]);
        }
        
        return true;
    }

    public function delAll($fd)
    {
        $event_key = (int)$fd;
        if(!empty($this->allEvents[$event_key]))
        {
            foreach($this->allEvents[$event_key] as $flag=>$item)
            {
                 $this->del($fd, $flag);
            }
        }
        
        unset($this->allEvents[$event_key]);
        
        return true;
    }
    
    public function loop()
    {
        uv_run();
    }
    
}

