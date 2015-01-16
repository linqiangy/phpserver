<?php 

/**
 * 
 * libev 事件轮询库 
 * libev测试有内存泄露情况，不建议使用
 * 
 * @author liangl3
 *
 */
class Libev implements BaseEvent
{
    // evloop
    protected $evnetLoop = null;
    // 记录事件及处理函数
    public $allEvents = array();

    /**
     * 初始化evloop
     */
    public function __construct()
    {
        $this->eventLoop = new EvLoop(Ev::FLAG_AUTO);
    }
   
    /**
     * 添加事件
     * @see BaseEvent::add()
     */
    public function add($fd, $flag, $func, $args = null)
    {
        $event_key = (int)$fd;
        switch ($flag)
        {
            case self::EV_ACCEPT:
                $real_flag = Ev::READ;
                $this->allEvents[$event_key][$flag] = array('event'=>$this->eventLoop->io($fd, $real_flag, array($this, 'accept'), $fd), 'args'=>$args, 'func' => $func);
                $this->allEvents[$event_key][$flag]['event']->start();
                break;
            case self::EV_READ:
                $real_flag = Ev::READ;
                $this->allEvents[$event_key][$flag] = array('event'=>$this->eventLoop->io($fd, $real_flag, array($this, 'bufferCallBack'), $fd), 'args'=>$args, 'func' => $func);
                $this->allEvents[$event_key][$flag]['event']->start();
                break;
            case self::EV_NOINOTIFY:
                $real_flag = Ev::READ;
                $this->allEvents[$event_key][$flag] = array('event'=>$this->eventLoop->io($fd, $real_flag, array($this, 'accept'), $fd), 'args'=>$args, 'func' => $func);
                $this->allEvents[$event_key][$flag]['event']->start();
                break;
            case self::EV_WRITE:
                $real_flag = Ev::WRITE;
                $this->allEvents[$event_key][$flag] = array('event'=>$this->eventLoop->io($fd, $real_flag, array($this, 'write'), $fd), 'args'=>$args, 'func' => $func);
                $this->allEvents[$event_key][$flag]['event']->start();
                break;
            case self::EV_SIGNAL:
                $real_flag = Ev::SIGNAL;
                $this->allEvents[$event_key][$flag] = array('event'=>$this->eventLoop->signal($fd, array($this, 'signalHandler'), $fd), 'args'=>$args, 'func' => $func);
                $this->allEvents[$event_key][$flag]['event']->start();
                break;
        
        }
        
        return true;
    }
    
    /**
     * 信号处理函数
     * @param obj $watcher
     * @param int $flag
     */
    public function signalHandler($watcher, $flag)
    {
        // call_user_function();
    }
    
    /**
     * 读数据回调函数
     * @param obj $watcher
     * @param int $flag
     */
    public function bufferCallBack($watcher, $flag)
    {
        $fd =  $watcher->data;
        $event_key = (int)$fd;
        
        $data = '';
        while ($tmp = fread($fd, 10240))
        {
            $data .= $tmp;
        }
        call_user_func_array($this->allEvents[$event_key][self::EV_READ]['func'], array($fd, strlen($data), $data, $this->allEvents[$event_key][self::EV_READ]['args']));
    }
    
    /**
     * 有链接回调函数
     * @param obj $watcher
     * @param int $flag
     */
    public function accept($watcher, $flag)
    {
        $fd =  $watcher->data;
        $event_key = (int)$fd;
        call_user_func_array($this->allEvents[$event_key][$flag]['func'], array($fd, $flag));
    }
    
    /**
     * 写数据回调
     * @param obj $watcher
     * @param int $flag
     */
    public function write($watcher, $flag)
    {
        $fd =  $watcher->data;
        $event_key = (int)$fd;
        call_user_func_array($this->allEvents[$event_key][$flag]['func'], array($fd, $flag));
    }
    
    /**
     * 删除某个事件
     * @see BaseEvent::del()
     */
    public function del($fd ,$flag)
    {
        $event_key = (int)$fd;
        
        if(isset($this->allEvents[$event_key][$flag]))
        {
            $this->allEvents[$event_key][$flag]['event']->stop();
            $this->allEvents[$event_key][$flag]['event']->clear();
        }
        unset($this->allEvents[$event_key][$flag]);
        return true;
    }

    /**
     * 删除某个fd的所有监听事件
     * @see BaseEvent::delAll()
     */
    public function delAll($fd)
    {
        $event_key = (int)$fd;
        if(!empty($this->allEvents[$event_key]))
        {
            foreach($this->allEvents[$event_key] as $flag=>$event)
            {
                 $this->del($fd, $flag);
            }
        }
        unset($this->allEvents[$event_key]);
        return true;
    }
    
    /**
     * 主循环(non-PHPdoc)
     * @see BaseEvent::loop()
     */
    public function loop()
    {
        $this->eventLoop->run();
    }

}
