<?php
/**
 * 
 * 监控文件更新类，依赖Inotify扩展
 * 
 * @author liangl
 *
 */
class Inotify 
{
    // 文件监控标志
    protected static $inotifySuport = false;
    
    // 要监控的文件数组
    protected static $filesToInotify = array();
    
    // inotify fd
    protected static $inotifyFd = NULL;
    
    // inotify watch fd
    protected static $inotifyWatchFds = NULL;
    
    /**
     * 初始化
     * @return NULL
     */
    public static function init()
    {
        self::$inotifySuport = extension_loaded('inotify');
        if(!self::$inotifySuport)
        {
            return null;
        }
        self::$inotifyFd = inotify_init();
        
        stream_set_blocking(self::$inotifyFd, 0);
        
        return self::$inotifyFd;
    }
    
    /**
     * 是否支持Inotify
     */
    public static function isSuport()
    {
        return false;
        //return self::$inotifySuport;
    }
    
    
    /**
     * 获取inotifyFd
     * @return NULL
     */
    public static function getFd()
    {
        return self::$inotifyFd;
    }
    
    /**
     * 获取更改的文件
     * @return array
     */
    public static function getModifiedFiles()
    {
        // 读取监控事件
        $events = inotify_read(self::$inotifyFd);
        if(empty($events))
        {
            return false;
        }
        
        // 获得哪些文件被修改
        $modify_files = array();
        foreach($events as $ev)
        {
            $modify_files[$ev['wd']] = self::$inotifyWatchFds[$ev['wd']];
        }
        
        return $modify_files;
    }
    
    /**
     * 增加需要监控的文件
     * @param string $file
     */
    public static function addFile($file)
    {
        $wd = inotify_add_watch(self::$inotifyFd, $file, IN_MODIFY);
        self::$inotifyWatchFds[$wd] = $file;
    }
    
    /**
     * 删除某个文件的监控
     * @param string $file
     */
    public static function delFile($file)
    {
        return inotify_rm_watch(self::$inotifyFd, $file);
    }
    
}
