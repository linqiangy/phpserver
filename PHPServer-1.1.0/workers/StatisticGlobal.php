<?php 

/**
 * phpserver集群统计
 * 统计中心对外服务进程 查询日志 查询请求量 延迟 成功率等
 * 采用http协议对外服务 使用http:://JMServer_ip:20203 地址查询统计结果
 * @author liangl
 *
 */
class StatisticGlobal extends PHPServerWorker
{
    /**
     * 上报ip命令
     * @var int
     */
    const CMD_REPORT_IP = 11;
    
    /**
     * 上报ip结果命令
     * @var int
     */
    const CMD_REPORT_IP_RESULT = 12;
    
    /**
     * 统计相关命令
     * @var int
     */
    const CMD_PROVIDER = 107;
    
    /**
     * 获得统计子命令
     * @var int
     */
    const SUB_CMD_GET_ST = 201;

    /**
     * 获得模块子命令
     * @var int
     */
    const SUB_CMD_GET_MODULES = 202;
    
    /**
     * 获得日志子命令
     * @var int
     */
    const SUB_CMD_GET_LOGS = 203;
    
    /**
     * 获得统计及模块子命令
     * @var unknown_type
     */
    const SUB_CMD_GET_ST_AND_MODULES = 204;
    
    /**
     * UDP 广播地址
     * @var int
     */
    const BROADCAST_PORT = 22070;
    
    /**
     * 监听广播ip的udp socket
     * @var resource
     */
    protected $udpBroadSocket = null;
    
    
    /**
     * 所有phpserver ip列表
     * @var array
     */
    protected $phpServerIpList = array();
    
    /**
     * 统计缓存，10秒钟过期
     * [time=>xx, 'statistic'=>[]]
     * @var array
     */
    protected $statisticDataCache = array();
    
    /**
     * 最近一次获取统计成功的ip列表
     * @var array
     */
    protected $lastSuccessIpArray = array();
    
    /**
     * 最近一次获取统计失败的ip列表
     * @var array
     */
    protected $lastFailedIpArray = array();
    
    /**
     * 模块及接口缓存
     * ['module'=>[m1=>[i1,i2..],m2=>[],..], 'st'=>[]]
     * @var array
     */
    protected $modulesDataCache = array();
    
    /**
     * 上次广播时间
     * @var bool
     */
    protected $lastBroadCast = 0;
    
    /**
     * 保存phpserver ip 的文件路径
     * @var unknown_type
     */
    protected $phpserverIpListFile = '../logs/statistic/phpserver_ip_list_file.php';
    
    /**
     * 进程启动的时候读取本地phpserver_ip列表
     * @see Worker::onServe()
     */
    protected function onServe()
    {
        $this->phpserverIpListFile = SERVER_BASE . 'logs/statistic/phpserver_ip_list_file.php';
        if(is_file($this->phpserverIpListFile))
        {
            include $this->phpserverIpListFile;
        }
        if(!empty($phpserver_ip_list))
        {
            $this->phpServerIpList = $phpserver_ip_list;
        }
    
        ob_start();
    }

    /**
     * 处理广播消息
     * @param string $data
     */
    protected function dealProcessBroadcast($data)
    {
        $pack = new JMProtocol($data);
        if($pack->header['cmd'] != self::CMD_REPORT_IP_RESULT)
        {
            $this->notice("\$pack->header['cmd'](".$pack->header['cmd'].") != self::CMD_REPORT_IP_RESULT(".self::CMD_REPORT_IP_RESULT.")");
            return false;
        }
        list($ip, $port) = explode(':', $this->currentClientAddress);
        $this->phpServerIpList[$ip] = $ip;
    }
    
    /**
     * 判断包是否都到达
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return HTTP::input($recv_str);
    }
    
    /**
     * 处理业务逻辑 查询log 查询统计信息
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        HTTP::decode($recv_str);
        $module = isset($_GET['module']) ? trim($_GET['module']) : '';
        $interface = isset($_GET['interface']) ? trim($_GET['interface']) : '';
        $start_time = isset($_GET['start_time']) ? trim($_GET['start_time']) : '';
        $end_time = isset($_GET['end_time']) ? trim($_GET['end_time']) : '';
        // 管理员
        if(0 === strpos($_SERVER['REQUEST_URI'], '/admin'))
        {
            return $this->admin();
        }
        // 静态文件
        elseif(0 === strpos($_SERVER['REQUEST_URI'], '/html'))
        {
            if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
            {
                HTTP::header("HTTP/1.1 304 Not Modified");
                return $this->sendToClient(HTTP::encode(''));
            }
            
            $modified_time = date('D, d M Y H:i:s');
            $base_name = basename($_SERVER['REQUEST_URI']);
            switch ($base_name)
            {
                case 'highcharts.js':
                    HTTP::header("Content-Type: application/javascript");
                    HTTP::header("Last-Modified: $modified_time");
                    return $this->sendToClient(HTTP::encode(self::$hichart));
                case 'jquery.min.js':
                    HTTP::header("Content-Type: application/javascript");
                    HTTP::header("Last-Modified: $modified_time");
                    return $this->sendToClient(HTTP::encode(self::$jquery));
                default :
                    HTTP::header("HTTP/1.1 404 Not Found");
                    return $this->sendToClient(HTTP::encode(''));
            }
           
        }
        // 请求日志
        elseif(0 === strpos($_SERVER['REQUEST_URI'], '/log'))
        {
            $this->checkRedirect();
            $right_str = '';
            $code = isset($_GET['code']) ? $_GET['code'] : '';
            $msg = isset($_GET['msg']) ? $_GET['msg'] : '';
            $pointer = isset($_GET['pointer']) ? $_GET['pointer'] : '';
            $count = isset($_GET['count']) ? $_GET['count'] : 5;
            $log_data_arr = $this->getStasticLog($module, $interface , $start_time , $end_time, $code, $msg , $pointer, $count);
            $log_data_str = '';
            unset($_GET['ip']);
            unset($_GET['pointer']);
            foreach($log_data_arr as $address => $log_data)
            {
                list($ip, $port) = explode(':', $address);
                $str = str_replace('source_ip:', 'client_ip:', $log_data['data']);
                $log_data_str .= preg_replace('/t?arget_ip:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', 'server_ip:'.$ip."<br/>", $str);
                $_GET['ip'][] = $ip;
                $_GET['pointer'][] = $log_data['pointer'];
            }
            // 过滤掉用户名密码
            $log_data_str = preg_replace(array('/([^#]*?Db[a-zA-Z\/]*Connection[^#]*?[construct|connect]\()([^)]*?),([^)]*?),([^)]*?)(,[^)]*?\))/i', '/(REQUEST_DATA:.*?)"password":"([^"]*?)"/'), array('${1}${2}, ****, ****$5', '$1"password":"****"'), $log_data_str);
            unset($_GET['end_time']);
            $next_page_url = http_build_query($_GET);
            $log_data_str .= "</br><center><a href='/log/?$next_page_url'>下一页</a></center>";
            return $this->display(nl2br($log_data_str));
        }
        // 统计
        else
        {
            $this->checkRedirect();
            // 首页
            if(empty($module))
            {
                return $this->home();
            }
            else
            {
                if($interface)
                {
                    return $this->displayInterface($module, $interface, $start_time, $end_time);
                }
                else
                {
                    $this->multiRequestStAndModules($module);
                    return $this->display();
                }
            }
            
        }
        
        return $this->display();
    }
    
    /**
     * 统计主页
     */
    protected function home()
    {
        $this->multiRequestStAndModules();
        $data = '';
        $address = '127.0.0.1:10101';
        $sock = stream_socket_client($address);
        if(!$sock)
        {
            return $this->display();
        }
        fwrite($sock, 'status');
        $read_fds = array($sock);
        $write_fds = $except_fds = array();
        while($ret = stream_select($read_fds, $write_fds, $except_fds, 1))
        {
            if(!$ret)break;
            foreach($read_fds as $fd)
            {
                if($ret_str = fread($fd, 8192))
                {
                    $data .= $ret_str;
                }
                else
                {
                    break;
                }
            }
        }
        
        $data = '<pre>'.$data.'</pre>';
        return $this->display($data);
    }
    
    protected function checkRedirect($force = false)
    {
        $time_now = time();
        if(($force  && $time_now - $this->lastBroadCast > 30 )|| (empty($this->phpServerIpList) && $time_now - $this->lastBroadCast > 10))
        {
            $this->multiRequestStAndModules();
            $data =
            <<<EOT
<html>
<head>
<title>跳转中，请稍候...</title>
</head>
<body>
<h3>跳转中，请稍候...</h3>
<script>
setTimeout(function(){window.location.href = '{$_SERVER['REQUEST_URI']}';}, 2000);
</script>
</body>
</html>
EOT;
            $this->lastBroadCast = $time_now;
            $this->sendToClient(HTTP::encode($data));
            // 创建udp socket
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $pack = new JMProtocol();
            $pack->header['cmd'] = self::CMD_REPORT_IP;
            $pack->body = '';
            $buffer = $pack->getBuffer();
            // 广播
            socket_sendto($socket, $buffer, strlen($buffer), 0, '255.255.255.255', PHPServerConfig::get('workers.StatisticProvider.port'));
            // 超时相关
            $time_start = microtime(true);
            $global_timeout = 1;
            $ip_list = array();
            $recv_timeout = array('sec'=>0,'usec'=>8000);
            socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,$recv_timeout);
            
            // 循环读数据
            while(microtime(true) - $time_start < $global_timeout)
            {
                $buf = $host = $port = '';
                if(@socket_recvfrom($socket, $buf, 65535, 0, $host, $port))
                {
                    $this->phpServerIpList[$host] = $host;
                }
            }
            
        }
    }
    
    /**
     * 接口统计信息
     * @param string $module
     * @param string $interface
     * @param int $start_time
     * @param int $end_time
     */
    protected function displayInterface($module ,$interface, $start_time, $end_time)
    {
        $module_interface_name = $module == 'PHPServer' ? 'PHPServer集群' : "$module::$interface";
        $right_str = "<h3>$module_interface_name 曲线图</h3>";
        // 汇总统计
        if(!isset($_GET['separate']))
        {
            $data = $this->getStatistic($module, $interface, $start_time, $end_time);
            $success_series_data = $fail_series_data = $success_time_series_data = $fail_time_series_data = array();
            foreach($data as $time_point=>$item)
            {
                if($item['total_count'])
                {
                    $success_series_data[] = "[".($time_point*1000).",{$item['total_count']}]";
                }
                $fail_series_data[] = "[".($time_point*1000).",{$item['fail_count']}]";
                if($item['total_avg_time'])
                {
                    $success_time_series_data[] = "[".($time_point*1000).",{$item['total_avg_time']}]";
                }
                $fail_time_series_data[] = "[".($time_point*1000).",{$item['fail_avg_time']}]";
            }
            $success_series_data = implode(',', $success_series_data);
            $fail_series_data = implode(',', $fail_series_data);
            $success_time_series_data = implode(',', $success_time_series_data);
            $fail_time_series_data = implode(',', $fail_time_series_data);
            $date = $start_time ? date('Y年m月d日', $start_time) : date('Y年m月d日');
            $req_series = 
<<<EOT
        {
            name: '成功曲线',
            data: [
                $success_series_data
            ],
            lineWidth: 2,
            marker:{
                radius: 1
            },
            
            pointInterval: 300*1000
        },
        {
            name: '失败曲线',
            data: [
                $fail_series_data
            ],
            lineWidth: 2,
            marker:{
                radius: 1
            },
            pointInterval: 300*1000,
            color : '#9C0D0D'
        }
EOT;
            $time_series = 
<<<EOT
        {
            name: '成功曲线',
            data: [
                $success_time_series_data
            ],
            lineWidth: 2,
            marker:{
                radius: 1
            },
            
            pointInterval: 300*1000
        },
        {
            name: '失败曲线',
            data: [
                $fail_time_series_data
            ],
            lineWidth: 2,
            marker:{
                radius: 1
            },
            pointInterval: 300*1000,
            color : '#9C0D0D'
        }            
EOT;
        }
        // 分别统计
        else
        {
            $all_data = $this->getSpStatistic($module, $interface, $start_time, $end_time);
            $req_series_success = $req_series_failed = $time_series_success = $time_series_failed = array();
            foreach ($all_data as $ip => $data)
            {
                $success_series_data = $fail_series_data = $success_time_series_data = $fail_time_series_data = array();
                foreach($data as $time_point=>$item)
                {
                    $success_series_data[] = "[".($time_point*1000).",{$item['total_count']}]";
                    $fail_series_data[] = "[".($time_point*1000).",{$item['fail_count']}]";
                    $success_time_series_data[] = "[".($time_point*1000).",{$item['total_avg_time']}]";
                    $fail_time_series_data[] = "[".($time_point*1000).",{$item['fail_avg_time']}]";
                }
                $success_series_data = implode(',', $success_series_data);
                $fail_series_data = implode(',', $fail_series_data);
                $success_time_series_data = implode(',', $success_time_series_data);
                $fail_time_series_data = implode(',', $fail_time_series_data);
                $date = $start_time ? date('Y年m月d日', $start_time) : date('Y年m月d日');
                $req_series_success[$ip] = 
<<<EOT
                {
                    name: '$ip',
                    data: [
                        $success_series_data
                    ],
                    lineWidth: 2,
                    marker:{
                        radius: 1
                    },
                    pointInterval: 300*1000
                }
EOT;
                $req_series_failed[$ip] =
                <<<EOT
                {
                    name: '$ip',
                    data: [
                        $fail_series_data
                    ],
                    lineWidth: 2,
                    marker:{
                        radius: 1
                    },
                    pointInterval: 300*1000
                }
EOT;
                $time_series_success[$ip] =
                <<<EOT
                {
                    name: '$ip',
                    data: [
                        $success_time_series_data
                    ],
                    lineWidth: 2,
                    marker:{
                        radius: 1
                    },
                    pointInterval: 300*1000
                }
EOT;
                $time_series_failed[$ip] =
                <<<EOT
                {
                    name: '$ip',
                    data: [
                        $fail_time_series_data
                    ],
                    lineWidth: 2,
                    marker:{
                        radius: 1
                    },
                    pointInterval: 300*1000
                }
EOT;
            }
            $req_series_success = implode(',', $req_series_success);
            $req_series_failed = implode(',', $req_series_failed);
            $time_series_success = implode(',', $time_series_success);
            $time_series_failed = implode(',', $time_series_failed);
            
        }
        
        // 统计的ip
        $success_ip_list_str = '<center>';
        $ip_list = isset($_GET['ip']) && is_array($_GET['ip']) ? $_GET['ip'] : array();
        $all_ip_list = array_merge($ip_list, $this->phpServerIpList);
        $all_ip_list = array_unique($all_ip_list);
        sort($all_ip_list);
        foreach(array_chunk($all_ip_list, 6) as $ips)
        {
            foreach($ips as $ip)
            {
                $checked = empty($ip_list) || in_array($ip, $ip_list) ? 'checked="checked"' : '';
                if(in_array($ip,$this->lastFailedIpArray))
                {
                    $success_ip_list_str .= '<a title="无法从'.$ip.'获取统计数据"><b style="color:red">'.$ip . '</b></a><input type="checkbox" name="ip[]" value="'.$ip.'" '.$checked.' /> 　';
                }
                else
                {
                    $success_ip_list_str .= $ip . '<input type="checkbox" name="ip[]" value="'.$ip.'" '.$checked.' /> 　';
                }
            }
            $success_ip_list_str .= "</br>";
        }
        $success_ip_list_str .= "</center></br>";
        if(!isset($_GET['separate']))
        {
            $right_str .= 
<<<EOT
<div id="req-container" style="min-width:1100px;height:400px"></div>
<div id="time-container" style="min-width:1100px;height:400px"></div>
<h3>统计来源：</h3>
<div id="ip-list" style="width:1100px;padding:0px 5px;">
    <form action="/st/" method="get">
        <input type="hidden" name="module" value="$module"/>
        <input type="hidden" name="interface" value="$interface"/>
        <input type="hidden" name="start_time" value="$start_time"/>
       $success_ip_list_str
       <center><input type="submit" name="summary" value="汇总统计"/> <input type="submit" name="separate" value="分别统计"/>&nbsp;全选:<input type="checkbox" id="check" onclick="checkedit('check')" checked="checked"></center>
    </form>
</div>
<script>
function checkedit(obj)
{   
    var cks = document.getElementsByTagName("input");
    var a = document.getElementById(obj);
    for(var i =0;i<cks.length-1;i++)
    {
        if(cks[i].type == "checkbox")
        {
            cks[i].checked = a.checked ;
        }
    }
};

Highcharts.setOptions({
    global: {
        useUTC: false
    }
});
\$(function () {
    \$('#req-container').highcharts({
        chart: {
            type: 'spline'
        },
        title: {
            text: '$date  $module_interface_name 请求量曲线'
        },
        subtitle: {
            text: ''
        },
        xAxis: {
            type: 'datetime',
            dateTimeLabelFormats: { 
                hour: '%H:%M'
            }
        },
        yAxis: {
            title: {
                text: '请求量(次/5分钟)'
            },
            min: 0
        },
        tooltip: {
            formatter: function() {
                return '<p style="color:'+this.series.color+';font-weight:bold;">'
                 + this.series.name + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">数量：'+ this.y + '</p>';
            }
        },
        credits: {
            enabled: false,
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [$req_series]
    });
});
\$(function () {
    \$('#time-container').highcharts({
        chart: {
            type: 'spline'
        },
        title: {
            text: '$date $module_interface_name 请求耗时曲线'
        },
        subtitle: {
            text: ''
        },
        xAxis: {
            type: 'datetime',
            dateTimeLabelFormats: { 
                hour: '%H:%M'
            }
        },
        yAxis: {
            title: {
                text: '平均耗时(单位：秒)'
            },
            min: 0
        },
        tooltip: {
            formatter: function() {
                return '<p style="color:'+this.series.color+';font-weight:bold;">'
                 + this.series.name + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">平均耗时：'+ this.y + '</p>';
            }
        },
        credits: {
            enabled: false,
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [$time_series]
    });
});
</script>
EOT;
        }
        // 分别统计
        else
        {
            $right_str .=
            <<<EOT
<div id="req-suc-container" style="min-width:1100px;height:400px"></div>
<div id="req-fail-container" style="min-width:1100px;height:400px"></div>
<div id="time-suc-container" style="min-width:1100px;height:400px"></div>
<div id="time-fail-container" style="min-width:1100px;height:400px"></div>
统计来源：</br>
<div id="ip-list" style="width:1100px;">
    <form action="/st/" method="get">
        <input type="hidden" name="module" value="$module"/>
        <input type="hidden" name="interface" value="$interface"/>
        <input type="hidden" name="start_time" value="$start_time"/>
       $success_ip_list_str
       <center><input type="submit" name="summary" value="汇总统计"/> <input type="submit" name="separate" value="分别统计"/></center>
    </form>
</div>
<script>
Highcharts.setOptions({
    global: {
        useUTC: false
    }
});
\$(function () {
    \$('#req-suc-container').highcharts({
        chart: {
            type: 'spline'
        },
        title: {
            text: '$date $module_interface_name 请求成功曲线'
        },
        subtitle: {
            text: ''
        },
        xAxis: {
            type: 'datetime',
            dateTimeLabelFormats: {
                hour: '%H:%M'
            }
        },
        yAxis: {
            title: {
                text: '成功请求量(次/5分钟)'
            },
            min: 0
        },
        tooltip: {
            formatter: function() {
                return '<p style="color:'+this.series.color+';font-weight:bold;">IP:'
                 + this.series.name + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">成功量：'+ this.y + '</p>';
            }
        },
        credits: {
            enabled: false,
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [$req_series_success]
    });
});
\$(function () {
    \$('#req-fail-container').highcharts({
        chart: {
            type: 'spline'
        },
        title: {
            text: '$date $module_interface_name 请求失败曲线',
            style: {color:'#85020F'}
        },
        subtitle: {
            text: ''
        },
        xAxis: {
            type: 'datetime',
            dateTimeLabelFormats: {
                hour: '%H:%M'
            }
        },
        yAxis: {
            title: {
                text: '失败请求量(次/5分钟)',
                style: {color:'#85020F'}
            },
            min: 0
        },
        tooltip: {
            formatter: function() {
                return '<p style="color:'+this.series.color+';font-weight:bold;">IP:'
                 + this.series.name + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">失败量：'+ this.y + '</p>';
            }
        },
        credits: {
            enabled: false,
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [$req_series_failed]
    });
});
\$(function () {
    \$('#time-suc-container').highcharts({
        chart: {
            type: 'spline'
        },
        title: {
            text: '$date $module_interface_name 请求成功耗时曲线'
        },
        subtitle: {
            text: ''
        },
        xAxis: {
            type: 'datetime',
            dateTimeLabelFormats: {
                hour: '%H:%M'
            }
        },
        yAxis: {
            title: {
                text: '成功平均耗时(单位：秒)'
            },
            min: 0
        },
        tooltip: {
            formatter: function() {
                return '<p style="color:'+this.series.color+';font-weight:bold;">IP:'
                 + this.series.name + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">成功平均耗时：'+ this.y + '</p>';
            }
        },
        credits: {
            enabled: false,
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [$time_series_success]
    });
});
\$(function () {
    \$('#time-fail-container').highcharts({
        chart: {
            type: 'spline'
        },
        title: {
            text: '$date $module_interface_name 请求失败耗时曲线',
            style: {color:'#85020F'}
        },
        subtitle: {
            text: ''
        },
        xAxis: {
            type: 'datetime',
            dateTimeLabelFormats: {
                hour: '%H:%M'
            }
        },
        yAxis: {
            title: {
                text: '失败平均耗时(单位：秒)',
                style: {color:'#85020F'}
            },
            min: 0
        },
        tooltip: {
            formatter: function() {
                return '<p style="color:'+this.series.color+';font-weight:bold;">IP:'
                 + this.series.name + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
                 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">失败平均耗时：'+ this.y + '</p>';
            }
        },
        credits: {
            enabled: false,
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [$time_series_failed]
    });
});
</script>
EOT;
        }
        
        $right_str .= '<h3>统计日期:</h3><div style="padding:5px;"><center>';
        
        $date_array = $this->getAvailableStDate($module, $interface);
        $current_date = date('Y-m-d', $start_time ? $start_time : time());
        if(!in_array(date('Y-m-d'), $date_array))
        {
            $date_array[time()] = date('Y-m-d');
        }
        unset($_GET['start_time']);
        $st_url = http_build_query($_GET);
        $date_array_chunk = array_chunk($date_array, 7, true);
        if($date_array_chunk)
        {
            foreach($date_array_chunk as $date_array)
            {
                foreach($date_array as $time_stamp => $date)
                {
                    $right_str .= ($current_date == $date) ? ('<a href=/st/?'.$st_url.'&start_time='.$time_stamp.'><b>'.$date.'</b></a>&nbsp;&nbsp;') : ('<a href=/st/?'.$st_url.'&start_time='.$time_stamp.'>'.$date.'</a>&nbsp;&nbsp;');
                }
                $right_str .= "<br>";
            }
        }
        unset($_GET['start_time'], $_GET['end_time']);
        $query = http_build_query($_GET);
        $right_str .='</div></center>';
        if(!isset($_GET['separate']))
        {
            $right_str .='<h3>请求明细表:</h3><center><table>
            <tr align="center">
            <th >时间</th><th>请求总数</th><th>平均耗时</th><th>成功请求总数</th><th>成功平均耗时</th><th>失败请求总数</th><th>失败平均耗时</th><th>成功率</th>
            </tr>
            ';
            
            if($data)
            {
                $first_line = true;
                foreach($data as $item)
                {
                    if($first_line)
                    {
                        $first_line = false;
                        if($item['total_count'] == 0)
                        {
                            continue;
                        }
                    }
                    $right_str .= "<tr align='center'><td>{$item['time']}</td><td>{$item['total_count']}</td><td>{$item['total_avg_time']}</td><td>{$item['suc_count']}</td><td>{$item['suc_avg_time']}</td><td>".($item['fail_count']>0?("<a href='/log/?$query&start_time=".(strtotime($item['time'])-300)."&end_time=".(strtotime($item['time']))."'>{$item['fail_count']}</a>"):$item['fail_count'])."</td><td>{$item['fail_avg_time']}</td><td>".($item['total_count'] && $item['precent']<=98?'<font style="color:red">'.$item['precent'].'%</font>' : $item['precent'].'%')."</td></tr>\n";
                }
            }
            
            $right_str .= '</table></center>'; 
        }
        
        return $this->display($right_str);
        
    }
    
    /**
     * 获取模块
     */
    public function getModules()
    {
        return $this->modulesDataCache;
    }
    
    
    public function multiRequestStAndModules($module = '', $interface = '', $time = '')
    {
        $this->statisticDataCache['statistic'] = '';
        $time = $time ? $time : time();
        $pack = new JMProtocol();
        $pack->header['cmd'] = self::CMD_PROVIDER;
        $pack->header['sub_cmd'] = self::SUB_CMD_GET_ST_AND_MODULES;
        $pack->body = json_encode(array('module'=>$module, 'interface'=>$interface, 'time'=>$time));
        $buffer = $pack->getBuffer();
        $ip_list = (!empty($_GET['ip']) && is_array($_GET['ip'])) ? $_GET['ip'] : $this->phpServerIpList;
        $reqest_buffer_array = array();
        $port = PHPServerConfig::get('workers.StatisticProvider.port');
        foreach($ip_list as $ip)
        {
            $reqest_buffer_array["$ip:$port"] = $buffer;
        }
        $read_buffer_array = $this->multiRequest($reqest_buffer_array);
        foreach($read_buffer_array as $address => $buf)
        {
            list($ip, $port) = explode(':',$address);
            $pack = new JMProtocol($buf);
            $body_data = json_decode($pack->body, true);
            $statistic_data = isset($body_data['statistic']) ? $body_data['statistic'] : '';
            $modules_data = isset($body_data['modules']) ? $body_data['modules'] : array();
            // 整理modules
            foreach($modules_data as $mod => $interfaces)
            {
                if(!isset($this->modulesDataCache[$mod]))
                {
                    $this->modulesDataCache[$mod] = array();
                }
                foreach($interfaces as $if)
                {
                    $this->modulesDataCache[$mod][$if] = $if;
                }
            }
            $this->statisticDataCache['statistic'][$ip] = $statistic_data;
        }
    }
    
    /**
     * 批量请求
     * @param array $request_buffer_array ['ip:port'=>req_buf, 'ip:port'=>req_buf, ...]
     * @return multitype:unknown string
     */
    public function multiRequest($request_buffer_array)
    {
        $client_array = $sock_to_ip = $ip_list = array();
        foreach($request_buffer_array as $address => $buffer)
        {
            list($ip, $port) = explode(':', $address);
            $ip_list[$ip] = $ip;
            $client = stream_socket_client("tcp://$address", $errno, $errmsg, 1);
            if(!$client)
            {
                $this->notice("connect tcp://$address fail");
                continue;
            }
            $client_array[$address] = $client;
            stream_set_timeout($client_array[$address], 0, 500000);
            fwrite($client_array[$address], $buffer);
            stream_set_blocking($client_array[$address], 0);
            $sock_to_address[(int)$client] = $address;
        }
        $read = $client_array;
        $write = $except = $read_buffer = array();
        $time_start = microtime(true);
        $timeout = 5;
        // 轮询处理数据
        while(count($read) > 0)
        {
            if(stream_select($read, $write, $except, 0, 200000))
            {
                foreach($read as $socket)
                {
                    $address = $sock_to_address[(int)$socket];
                    $buf = fread($socket, 8192);
                    if(!$buf)
                    {
                        if(feof($socket))
                        {
                            unset($client_array[$address]);
                        }
                        continue;
                    }
                    if(!isset($read_buffer[$address]))
                    {
                        $read_buffer[$address] = $buf;
                    }
                    else
                    {
                        $read_buffer[$address] .= $buf;
                    }
                    // 数据接收完毕
                    if(0 === JMProtocol::checkInput($read_buffer[$address]))
                    {
                        unset($client_array[$address]);
                    }
                }
            }
            // 超时了
            if(microtime(true) - $time_start > $timeout)
            {
                break;
            }
            $read = $client_array;
        }
        
        foreach($read_buffer as $address => $buf)
        {
            list($ip, $port) = explode(':', $address);
            $this->lastSuccessIpArray[$ip] = $ip;
        }
        
        $this->lastFailedIpArray = array_diff($ip_list, $this->lastSuccessIpArray);
        
        ksort($read_buffer);
        
        return $read_buffer;
    } 
    
    /**
     * 渲染页面
     * @param string $data
     */
    protected function display($data=null)
    {
        $left_detail = '';
        $html_left = '<ul>';
        $current_module = empty($_GET['module']) ? '' : $_GET['module'];
        $modules_name_array = $this->getModules($current_module);
        $html_left .= '<li><a href="/st/?module=PHPServer&interface=Statistics">PHPServer集群统计</a></li>';
        if($modules_name_array)
        {
            foreach($modules_name_array as $module => $interfaces)
            {
                if($module == 'PHPServer')
                {
                    continue;
                }
                $html_left .= '<li>&nbsp;&nbsp;<a href="/st/?module='.$module.'">'.$module.'</a></li>';
                if($module == $current_module)
                {
                    foreach ($interfaces as $interface)
                    {
                        $left_detail .= '<li>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/st/?module='.$current_module.'&interface='.$interface.'">'.$interface.'</a></li>';
                    }
                    $html_left .= $left_detail;
                }
            }
        }
        $display_str = <<<EOC
<html>
<head>
<title>PHPServer集群统计监控</title>
<script src="/html/jquery.min.js"></script>
<script src="/html/highcharts.js"></script>
<style>
* {color:#25468D;}
ul li {list-style:none;}
h3 {font-size:16px;padding:5px 20px;color:#555555;background:#EEEEEE;}
table {
    clear: both;
    margin-bottom: 6px !important;
    max-width: none !important;
    border-collapse: separate;
    border-color: #DDDDDD #DDDDDD #DDDDDD #DDDDDD;
    border-image: none;
    border-radius: 4px;
    border-style: solid solid solid solid;
    border-width: 1px 1px 1px 1px;
    border-spacing: 0;
    max-width: 100%;
}
table th, table td {
    border-top: 1px solid #DDDDDD;
    line-height: 20px;
    padding: 8px;
    text-align: left;
    color:#555555;
    vertical-align: top;
}
hr{color:#EEEEEE;}
</style>
</head>
<body>
<table>
<tr valign='top'>
<td style="border-right:3px solid #dddddd">$html_left</td>
<td style="min-width:1200px;min-height:800px">$data</td>
</tr>
</table>
<center>
<br/>
<hr/>
<span style='font-size:13px;color:#AAAAAA'>
&copy; 2014 聚美优品
</span>
</center>
</body>
</html>
EOC;
        return $this->sendToClient(HTTP::encode($display_str));
    }
    
    protected function getStatistic($module, $interface, $start_time='',$end_time='')
    {
        $this->multiRequestStAndModules($module, $interface, $start_time);
        $all_st_str = '';
        if(is_array($this->statisticDataCache['statistic']))
        {
            foreach($this->statisticDataCache['statistic'] as $ip=>$st_str)
            {
                $all_st_str .= $st_str;
            }
        }
        return $this->formatSt($all_st_str, $start_time);
    }
    
    protected function getSpStatistic($module, $interface, $start_time='',$end_time='')
    {
        $this->multiRequestStAndModules($module, $interface, $start_time);
        $all_st_data = array();
        foreach($this->statisticDataCache['statistic'] as $ip=>$st_str)
        {
            $all_st_data[$ip] = $this->formatSt($st_str, $start_time);
        }
        return $all_st_data;
    }
    
    protected function getStasticLog($module, $interface , $start_time, $end_time = '', $code = '', $msg = '', $pointer = '', $count = 10)
    {
        $pack = new JMProtocol();
        $pack->header['cmd'] = self::CMD_PROVIDER;
        $pack->header['sub_cmd'] = self::SUB_CMD_GET_LOGS;
        $ip_list = (!empty($_GET['ip']) && is_array($_GET['ip'])) ? $_GET['ip'] : $this->phpServerIpList;
        $pointer_list = (!empty($_GET['pointer']) && is_array($_GET['pointer'])) ? $_GET['pointer'] : array();
        $port = PHPServerConfig::get('workers.StatisticProvider.port');
        $request_buffer_array = array();
        foreach($ip_list as $key=>$ip)
        {
            $pointer = isset($pointer_list[$key]) ? $pointer_list[$key] : 0;
            $pack->body = json_encode(array('module'=>$module, 'interface'=>$interface, 'start_time'=>$start_time, 'end_time'=>$end_time, 'code'=>$code, 'msg'=>$msg, 'pointer'=>$pointer, 'count'=>$count));
            $request_buffer_array["$ip:$port"] = $pack->getBuffer();
        }
        
        $read_buffer_array = $this->multiRequest($request_buffer_array);
        ksort($read_buffer_array);
        foreach($read_buffer_array as $address => $buf)
        {
            list($ip, $port) = explode(':', $address);
            $pack = new JMProtocol($buf);
            $body_data = json_decode($pack->body, true);
            $log_data = isset($body_data['data']) ? $body_data['data'] : '';
            $point = isset($body_data['pointer']) ? $body_data['pointer'] : 0;
            $read_buffer_array[$address] = array('pointer'=>$point,'data'=>$log_data);
        }
        return $read_buffer_array;
    }
    
    protected function formatSt($str, $start_time)
    {
        // time:[suc_count:xx,suc_cost_time:xx,fail_count:xx,fail_cost_time:xx]
        $st_data = array();
        $st_explode = explode("\n", $str);
        // 汇总计算
        foreach($st_explode as $line)
        {
            // line = IP time suc_count suc_cost_time fail_count fail_cost_time code_json
            $line_data = explode("\t", $line);
            if(!isset($line_data[5]))
            {
                continue;
            }
            $time_line = $line_data[1];
            $suc_count = $line_data[2];
            $suc_cost_time = $line_data[3];
            $fail_count = $line_data[4];
            $fail_cost_time = $line_data[5];
            if(!isset($st_data[$time_line]))
            {
                $st_data[$time_line] = array('suc_count'=>0, 'suc_cost_time'=>0, 'fail_count'=>0, 'fail_cost_time'=>0);
            }
            $st_data[$time_line]['suc_count'] += $suc_count;
            $st_data[$time_line]['suc_cost_time'] += $suc_cost_time;
            $st_data[$time_line]['fail_count'] += $fail_count;
            $st_data[$time_line]['fail_cost_time'] += $fail_cost_time;
        }
        // 按照时间排序
        ksort($st_data);
        // time => [total_count:xx,suc_count:xx,suc_avg_time:xx,fail_count:xx,fail_avg_time:xx,percent:xx]
        $data = array();
        // 计算成功率 耗时
        foreach($st_data as $time_line=>$item)
        {
            $data[$time_line] = array(
                            'time'          => date('Y-m-d H:i:s', $time_line),
                            'total_count'   => $item['suc_count']+$item['fail_count'],
                            'total_avg_time'=> $item['suc_count']+$item['fail_count'] == 0 ? 0 : round(($item['suc_cost_time']+$item['fail_cost_time'])/($item['suc_count']+$item['fail_count']), 4),
                            'suc_count'     => $item['suc_count'],
                            'suc_avg_time'  => $item['suc_count'] == 0 ? $item['suc_count'] : round($item['suc_cost_time']/$item['suc_count'], 4),
                            'fail_count'    => $item['fail_count'],
                            'fail_avg_time' => $item['fail_count'] == 0 ? 0 : round($item['fail_cost_time']/$item['fail_count'], 4),
                            'precent'       => $item['suc_count']+$item['fail_count'] == 0 ? 100 : round(($item['suc_count']*100/($item['suc_count']+$item['fail_count'])), 4),
            );
        }
        $time_point = $start_time ? strtotime(date('Y-m-d', $start_time)) : strtotime(date('Y-m-d'));
        for($i=0;$i<288;$i++)
        {
        $data[$time_point] = isset($data[$time_point]) ? $data[$time_point] :
        array(
            'time' => date('Y-m-d H:i:s', $time_point),
                'total_count'   => 0,
                'total_avg_time'=> 0,
                'suc_count'     => 0,
                'suc_avg_time'  => 0,
                'fail_count'    => 0,
                'fail_avg_time' => 0,
                'precent'       => 100,
            );
            $time_point +=300;
        }
        ksort($data);
        return $data;
    }
    
    // 管理phpsever集群ip列表
    protected function admin()
    {
        if(isset($_GET['ip_list']))
        {
            $ip_arr = explode("\n", $_GET['ip_list']);
            $this->phpServerIpList = array();
            foreach($ip_arr as $ip)
            {
                $ip = trim($ip);
                if(ip2long($ip))
                {
                    $this->phpServerIpList[$ip] = $ip;
                }
            }
            file_put_contents($this->phpserverIpListFile, "<?php\n\$phpserver_ip_list = " . var_export($this->phpServerIpList, true).";");
        }
        else 
        {
            $this->checkRedirect(true);
        }
        return $this->displayAdmin();
    }
    
    protected function displayAdmin()
    {
        $this->checkRedirect();
        $ip_list_str = implode("\r\n", $this->phpServerIpList);
        $html = 
<<<EOT
<html>
<head>
<title>PHPServer集群管理</title>
</head>
<body>
<form method="get" action="/admin">
<h3>phpserver集群ip列表</h3>
<textarea name="ip_list" style="width:18em;height:400px">
$ip_list_str
</textarea>
<br/>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
<input type="submit" name='保存' value='保存'/ >
</form>
</body>
</html>
EOT;
        return $this->sendToClient(HTTP::encode($html));
    }
    
    protected function getAvailableStDate($module, $interface)
    {
        for($i=13;$i>=1;$i--)
        {
            $the_time = strtotime("-$i day");
            $date = date('Y-m-d',$the_time);
            $date_array[$the_time] = $date;
        }
        return $date_array;
    }
    
    public function onAlarm()
    {
        $ob_content = ob_get_contents();
        if($ob_content)
        {
            ServerLog::add('StatisticService:ob_content:'.$ob_content);
            ob_clean();
        }
    }
    
    protected static $hichart = '(function(){function s(a,b){var c;a||(a={});for(c in b)a[c]=b[c];return a}function x(){var a,b=arguments.length,c={},d=function(a,b){var c,h;typeof a!=="object"&&(a={});for(h in b)b.hasOwnProperty(h)&&(c=b[h],a[h]=c&&typeof c==="object"&&Object.prototype.toString.call(c)!=="[object Array]"&&typeof c.nodeType!=="number"?d(a[h]||{},c):b[h]);return a};for(a=0;a<b;a++)c=d(c,arguments[a]);return c}function y(a,b){return parseInt(a,b||10)}function ea(a){return typeof a==="string"}function T(a){return typeof a===
"object"}function Ia(a){return Object.prototype.toString.call(a)==="[object Array]"}function ra(a){return typeof a==="number"}function ma(a){return R.log(a)/R.LN10}function fa(a){return R.pow(10,a)}function ga(a,b){for(var c=a.length;c--;)if(a[c]===b){a.splice(c,1);break}}function u(a){return a!==v&&a!==null}function w(a,b,c){var d,e;if(ea(b))u(c)?a.setAttribute(b,c):a&&a.getAttribute&&(e=a.getAttribute(b));else if(u(b)&&T(b))for(d in b)a.setAttribute(d,b[d]);return e}function ja(a){return Ia(a)?
a:[a]}function o(){var a=arguments,b,c,d=a.length;for(b=0;b<d;b++)if(c=a[b],typeof c!=="undefined"&&c!==null)return c}function I(a,b){if(sa&&b&&b.opacity!==v)b.filter="alpha(opacity="+b.opacity*100+")";s(a.style,b)}function U(a,b,c,d,e){a=z.createElement(a);b&&s(a,b);e&&I(a,{padding:0,border:S,margin:0});c&&I(a,c);d&&d.appendChild(a);return a}function ha(a,b){var c=function(){};c.prototype=new a;s(c.prototype,b);return c}function Aa(a,b,c,d){var e=L.lang,a=+a||0,f=b===-1?(a.toString().split(".")[1]||
"").length:isNaN(b=M(b))?2:b,b=c===void 0?e.decimalPoint:c,d=d===void 0?e.thousandsSep:d,e=a<0?"-":"",c=String(y(a=M(a).toFixed(f))),g=c.length>3?c.length%3:0;return e+(g?c.substr(0,g)+d:"")+c.substr(g).replace(/(\\d{3})(?=\\d)/g,"$1"+d)+(f?b+M(a-c).toFixed(f).slice(2):"")}function Ba(a,b){return Array((b||2)+1-String(a).length).join(0)+a}function mb(a,b,c){var d=a[b];a[b]=function(){var a=Array.prototype.slice.call(arguments);a.unshift(d);return c.apply(this,a)}}function Ca(a,b){for(var c="{",d=!1,
e,f,g,h,i,j=[];(c=a.indexOf(c))!==-1;){e=a.slice(0,c);if(d){f=e.split(":");g=f.shift().split(".");i=g.length;e=b;for(h=0;h<i;h++)e=e[g[h]];if(f.length)f=f.join(":"),g=/\\.([0-9])/,h=L.lang,i=void 0,/f$/.test(f)?(i=(i=f.match(g))?i[1]:-1,e=Aa(e,i,h.decimalPoint,f.indexOf(",")>-1?h.thousandsSep:"")):e=Ya(f,e)}j.push(e);a=a.slice(c+1);c=(d=!d)?"}":"{"}j.push(a);return j.join("")}function nb(a){return R.pow(10,P(R.log(a)/R.LN10))}function ob(a,b,c,d){var e,c=o(c,1);e=a/c;b||(b=[1,2,2.5,5,10],d&&d.allowDecimals===
!1&&(c===1?b=[1,2,5,10]:c<=0.1&&(b=[1/c])));for(d=0;d<b.length;d++)if(a=b[d],e<=(b[d]+(b[d+1]||b[d]))/2)break;a*=c;return a}function Cb(a,b){var c=b||[[Db,[1,2,5,10,20,25,50,100,200,500]],[pb,[1,2,5,10,15,30]],[Za,[1,2,5,10,15,30]],[Qa,[1,2,3,4,6,8,12]],[ta,[1,2]],[$a,[1,2]],[Ra,[1,2,3,4,6]],[Da,null]],d=c[c.length-1],e=D[d[0]],f=d[1],g;for(g=0;g<c.length;g++)if(d=c[g],e=D[d[0]],f=d[1],c[g+1]&&a<=(e*f[f.length-1]+D[c[g+1][0]])/2)break;e===D[Da]&&a<5*e&&(f=[1,2,5]);c=ob(a/e,f,d[0]===Da?r(nb(a/e),1):
1);return{unitRange:e,count:c,unitName:d[0]}}function Eb(a,b,c,d){var e=[],f={},g=L.global.useUTC,h,i=new Date(b),j=a.unitRange,k=a.count;if(u(b)){j>=D[pb]&&(i.setMilliseconds(0),i.setSeconds(j>=D[Za]?0:k*P(i.getSeconds()/k)));if(j>=D[Za])i[Fb](j>=D[Qa]?0:k*P(i[qb]()/k));if(j>=D[Qa])i[Gb](j>=D[ta]?0:k*P(i[rb]()/k));if(j>=D[ta])i[sb](j>=D[Ra]?1:k*P(i[Sa]()/k));j>=D[Ra]&&(i[Hb](j>=D[Da]?0:k*P(i[ab]()/k)),h=i[bb]());j>=D[Da]&&(h-=h%k,i[Ib](h));if(j===D[$a])i[sb](i[Sa]()-i[tb]()+o(d,1));b=1;h=i[bb]();
for(var d=i.getTime(),l=i[ab](),m=i[Sa](),p=g?0:(864E5+i.getTimezoneOffset()*6E4)%864E5;d<c;)e.push(d),j===D[Da]?d=cb(h+b*k,0):j===D[Ra]?d=cb(h,l+b*k):!g&&(j===D[ta]||j===D[$a])?d=cb(h,l,m+b*k*(j===D[ta]?1:7)):d+=j*k,b++;e.push(d);n(ub(e,function(a){return j<=D[Qa]&&a%D[ta]===p}),function(a){f[a]=ta})}e.info=s(a,{higherRanks:f,totalRange:j*k});return e}function Jb(){this.symbol=this.color=0}function Kb(a,b){var c=a.length,d,e;for(e=0;e<c;e++)a[e].ss_i=e;a.sort(function(a,c){d=b(a,c);return d===0?
a.ss_i-c.ss_i:d});for(e=0;e<c;e++)delete a[e].ss_i}function Ja(a){for(var b=a.length,c=a[0];b--;)a[b]<c&&(c=a[b]);return c}function ua(a){for(var b=a.length,c=a[0];b--;)a[b]>c&&(c=a[b]);return c}function Ka(a,b){for(var c in a)a[c]&&a[c]!==b&&a[c].destroy&&a[c].destroy(),delete a[c]}function Ta(a){db||(db=U(Ea));a&&db.appendChild(a);db.innerHTML=""}function ka(a,b){var c="Highcharts error #"+a+": www.highcharts.com/errors/"+a;if(b)throw c;else N.console&&console.log(c)}function ia(a){return parseFloat(a.toPrecision(14))}
function La(a,b){Fa=o(a,b.animation)}function Lb(){var a=L.global.useUTC,b=a?"getUTC":"get",c=a?"setUTC":"set";cb=a?Date.UTC:function(a,b,c,g,h,i){return(new Date(a,b,o(c,1),o(g,0),o(h,0),o(i,0))).getTime()};qb=b+"Minutes";rb=b+"Hours";tb=b+"Day";Sa=b+"Date";ab=b+"Month";bb=b+"FullYear";Fb=c+"Minutes";Gb=c+"Hours";sb=c+"Date";Hb=c+"Month";Ib=c+"FullYear"}function va(){}function Ma(a,b,c,d){this.axis=a;this.pos=b;this.type=c||"";this.isNew=!0;!c&&!d&&this.addLabel()}function vb(a,b){this.axis=a;if(b)this.options=
b,this.id=b.id}function Mb(a,b,c,d,e,f){var g=a.chart.inverted;this.axis=a;this.isNegative=c;this.options=b;this.x=d;this.total=null;this.points={};this.stack=e;this.percent=f==="percent";this.alignOptions={align:b.align||(g?c?"left":"right":"center"),verticalAlign:b.verticalAlign||(g?"middle":c?"bottom":"top"),y:o(b.y,g?4:c?14:-6),x:o(b.x,g?c?-6:6:0)};this.textAlign=b.textAlign||(g?c?"right":"left":"center")}function eb(){this.init.apply(this,arguments)}function wb(){this.init.apply(this,arguments)}
function xb(a,b){this.init(a,b)}function fb(a,b){this.init(a,b)}function yb(){this.init.apply(this,arguments)}var v,z=document,N=window,R=Math,t=R.round,P=R.floor,wa=R.ceil,r=R.max,J=R.min,M=R.abs,V=R.cos,ba=R.sin,xa=R.PI,Ua=xa*2/360,na=navigator.userAgent,Nb=N.opera,sa=/msie/i.test(na)&&!Nb,gb=z.documentMode===8,hb=/AppleWebKit/.test(na),ib=/Firefox/.test(na),Ob=/(Mobile|Android|Windows Phone)/.test(na),ya="http://www.w3.org/2000/svg",W=!!z.createElementNS&&!!z.createElementNS(ya,"svg").createSVGRect,
Ub=ib&&parseInt(na.split("Firefox/")[1],10)<4,ca=!W&&!sa&&!!z.createElement("canvas").getContext,Va,jb=z.documentElement.ontouchstart!==v,Pb={},zb=0,db,L,Ya,Fa,Ab,D,oa=function(){},Ga=[],Ea="div",S="none",Qb="rgba(192,192,192,"+(W?1.0E-4:0.002)+")",Db="millisecond",pb="second",Za="minute",Qa="hour",ta="day",$a="week",Ra="month",Da="year",Rb="stroke-width",cb,qb,rb,tb,Sa,ab,bb,Fb,Gb,sb,Hb,Ib,X={};N.Highcharts=N.Highcharts?ka(16,!0):{};Ya=function(a,b,c){if(!u(b)||isNaN(b))return"Invalid date";var a=
o(a,"%Y-%m-%d %H:%M:%S"),d=new Date(b),e,f=d[rb](),g=d[tb](),h=d[Sa](),i=d[ab](),j=d[bb](),k=L.lang,l=k.weekdays,d=s({a:l[g].substr(0,3),A:l[g],d:Ba(h),e:h,b:k.shortMonths[i],B:k.months[i],m:Ba(i+1),y:j.toString().substr(2,2),Y:j,H:Ba(f),I:Ba(f%12||12),l:f%12||12,M:Ba(d[qb]()),p:f<12?"AM":"PM",P:f<12?"am":"pm",S:Ba(d.getSeconds()),L:Ba(t(b%1E3),3)},Highcharts.dateFormats);for(e in d)for(;a.indexOf("%"+e)!==-1;)a=a.replace("%"+e,typeof d[e]==="function"?d[e](b):d[e]);return c?a.substr(0,1).toUpperCase()+
a.substr(1):a};Jb.prototype={wrapColor:function(a){if(this.color>=a)this.color=0},wrapSymbol:function(a){if(this.symbol>=a)this.symbol=0}};D=function(){for(var a=0,b=arguments,c=b.length,d={};a<c;a++)d[b[a++]]=b[a];return d}(Db,1,pb,1E3,Za,6E4,Qa,36E5,ta,864E5,$a,6048E5,Ra,26784E5,Da,31556952E3);Ab={init:function(a,b,c){var b=b||"",d=a.shift,e=b.indexOf("C")>-1,f=e?7:3,g,b=b.split(" "),c=[].concat(c),h,i,j=function(a){for(g=a.length;g--;)a[g]==="M"&&a.splice(g+1,0,a[g+1],a[g+2],a[g+1],a[g+2])};e&&
(j(b),j(c));a.isArea&&(h=b.splice(b.length-6,6),i=c.splice(c.length-6,6));if(d<=c.length/f&&b.length===c.length)for(;d--;)c=[].concat(c).splice(0,f).concat(c);a.shift=0;if(b.length)for(a=c.length;b.length<a;)d=[].concat(b).splice(b.length-f,f),e&&(d[f-6]=d[f-2],d[f-5]=d[f-1]),b=b.concat(d);h&&(b=b.concat(h),c=c.concat(i));return[b,c]},step:function(a,b,c,d){var e=[],f=a.length;if(c===1)e=d;else if(f===b.length&&c<1)for(;f--;)d=parseFloat(a[f]),e[f]=isNaN(d)?a[f]:c*parseFloat(b[f]-d)+d;else e=b;return e}};
(function(a){N.HighchartsAdapter=N.HighchartsAdapter||a&&{init:function(b){var c=a.fx,d=c.step,e,f=a.Tween,g=f&&f.propHooks;e=a.cssHooks.opacity;a.extend(a.easing,{easeOutQuad:function(a,b,c,d,e){return-d*(b/=e)*(b-2)+c}});a.each(["cur","_default","width","height","opacity"],function(a,b){var e=d,k,l;b==="cur"?e=c.prototype:b==="_default"&&f&&(e=g[b],b="set");(k=e[b])&&(e[b]=function(c){c=a?c:this;if(c.prop!=="align")return l=c.elem,l.attr?l.attr(c.prop,b==="cur"?v:c.now):k.apply(this,arguments)})});
mb(e,"get",function(a,b,c){return b.attr?b.opacity||0:a.call(this,b,c)});e=function(a){var c=a.elem,d;if(!a.started)d=b.init(c,c.d,c.toD),a.start=d[0],a.end=d[1],a.started=!0;c.attr("d",b.step(a.start,a.end,a.pos,c.toD))};f?g.d={set:e}:d.d=e;this.each=Array.prototype.forEach?function(a,b){return Array.prototype.forEach.call(a,b)}:function(a,b){for(var c=0,d=a.length;c<d;c++)if(b.call(a[c],a[c],c,a)===!1)return c};a.fn.highcharts=function(){var a="Chart",b=arguments,c,d;ea(b[0])&&(a=b[0],b=Array.prototype.slice.call(b,
1));c=b[0];if(c!==v)c.chart=c.chart||{},c.chart.renderTo=this[0],new Highcharts[a](c,b[1]),d=this;c===v&&(d=Ga[w(this[0],"data-highcharts-chart")]);return d}},getScript:a.getScript,inArray:a.inArray,adapterRun:function(b,c){return a(b)[c]()},grep:a.grep,map:function(a,c){for(var d=[],e=0,f=a.length;e<f;e++)d[e]=c.call(a[e],a[e],e,a);return d},offset:function(b){return a(b).offset()},addEvent:function(b,c,d){a(b).bind(c,d)},removeEvent:function(b,c,d){var e=z.removeEventListener?"removeEventListener":
"detachEvent";z[e]&&b&&!b[e]&&(b[e]=function(){});a(b).unbind(c,d)},fireEvent:function(b,c,d,e){var f=a.Event(c),g="detached"+c,h;!sa&&d&&(delete d.layerX,delete d.layerY);s(f,d);b[c]&&(b[g]=b[c],b[c]=null);a.each(["preventDefault","stopPropagation"],function(a,b){var c=f[b];f[b]=function(){try{c.call(f)}catch(a){b==="preventDefault"&&(h=!0)}}});a(b).trigger(f);b[g]&&(b[c]=b[g],b[g]=null);e&&!f.isDefaultPrevented()&&!h&&e(f)},washMouseEvent:function(a){var c=a.originalEvent||a;if(c.pageX===v)c.pageX=
a.pageX,c.pageY=a.pageY;return c},animate:function(b,c,d){var e=a(b);if(!b.style)b.style={};if(c.d)b.toD=c.d,c.d=1;e.stop();c.opacity!==v&&b.attr&&(c.opacity+="px");e.animate(c,d)},stop:function(b){a(b).stop()}}})(N.jQuery);var Y=N.HighchartsAdapter,G=Y||{};Y&&Y.init.call(Y,Ab);var kb=G.adapterRun,Vb=G.getScript,pa=G.inArray,n=G.each,ub=G.grep,Wb=G.offset,Na=G.map,K=G.addEvent,$=G.removeEvent,A=G.fireEvent,Xb=G.washMouseEvent,Bb=G.animate,Wa=G.stop,G={enabled:!0,x:0,y:15,style:{color:"#666",cursor:"default",
fontSize:"11px",lineHeight:"14px"}};L={colors:"#2f7ed8,#0d233a,#8bbc21,#910000,#1aadce,#492970,#f28f43,#77a1e5,#c42525,#a6c96a".split(","),symbols:["circle","diamond","square","triangle","triangle-down"],lang:{loading:"Loading...",months:"January,February,March,April,May,June,July,August,September,October,November,December".split(","),shortMonths:"Jan,Feb,Mar,Apr,May,Jun,Jul,Aug,Sep,Oct,Nov,Dec".split(","),weekdays:"Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday".split(","),decimalPoint:".",
numericSymbols:"k,M,G,T,P,E".split(","),resetZoom:"Reset zoom",resetZoomTitle:"Reset zoom level 1:1",thousandsSep:","},global:{useUTC:!0,canvasToolsURL:"http://code.highcharts.com/3.0.7/modules/canvas-tools.js",VMLRadialGradientURL:"http://code.highcharts.com/3.0.7/gfx/vml-radial-gradient.png"},chart:{borderColor:"#4572A7",borderRadius:5,defaultSeriesType:"line",ignoreHiddenSeries:!0,spacing:[10,10,15,10],style:{fontFamily:\'"Lucida Grande", "Lucida Sans Unicode", Verdana, Arial, Helvetica, sans-serif\',
fontSize:"12px"},backgroundColor:"#FFFFFF",plotBorderColor:"#C0C0C0",resetZoomButton:{theme:{zIndex:20},position:{align:"right",x:-10,y:10}}},title:{text:"Chart title",align:"center",margin:15,style:{color:"#274b6d",fontSize:"16px"}},subtitle:{text:"",align:"center",style:{color:"#4d759e"}},plotOptions:{line:{allowPointSelect:!1,showCheckbox:!1,animation:{duration:1E3},events:{},lineWidth:2,marker:{enabled:!0,lineWidth:0,radius:4,lineColor:"#FFFFFF",states:{hover:{enabled:!0},select:{fillColor:"#FFFFFF",
lineColor:"#000000",lineWidth:2}}},point:{events:{}},dataLabels:x(G,{align:"center",enabled:!1,formatter:function(){return this.y===null?"":Aa(this.y,-1)},verticalAlign:"bottom",y:0}),cropThreshold:300,pointRange:0,states:{hover:{marker:{}},select:{marker:{}}},stickyTracking:!0}},labels:{style:{position:"absolute",color:"#3E576F"}},legend:{enabled:!0,align:"center",layout:"horizontal",labelFormatter:function(){return this.name},borderWidth:1,borderColor:"#909090",borderRadius:5,navigation:{activeColor:"#274b6d",
inactiveColor:"#CCC"},shadow:!1,itemStyle:{cursor:"pointer",color:"#274b6d",fontSize:"12px"},itemHoverStyle:{color:"#000"},itemHiddenStyle:{color:"#CCC"},itemCheckboxStyle:{position:"absolute",width:"13px",height:"13px"},symbolWidth:16,symbolPadding:5,verticalAlign:"bottom",x:0,y:0,title:{style:{fontWeight:"bold"}}},loading:{labelStyle:{fontWeight:"bold",position:"relative",top:"1em"},style:{position:"absolute",backgroundColor:"white",opacity:0.5,textAlign:"center"}},tooltip:{enabled:!0,animation:W,
backgroundColor:"rgba(255, 255, 255, .85)",borderWidth:1,borderRadius:3,dateTimeLabelFormats:{millisecond:"%A, %b %e, %H:%M:%S.%L",second:"%A, %b %e, %H:%M:%S",minute:"%A, %b %e, %H:%M",hour:"%A, %b %e, %H:%M",day:"%A, %b %e, %Y",week:"Week from %A, %b %e, %Y",month:"%B %Y",year:"%Y"},headerFormat:\'<span style="font-size: 10px">{point.key}</span><br/>\',pointFormat:\'<span style="color:{series.color}">{series.name}</span>: <b>{point.y}</b><br/>\',shadow:!0,snap:Ob?25:10,style:{color:"#333333",cursor:"default",
fontSize:"12px",padding:"8px",whiteSpace:"nowrap"}},credits:{enabled:!0,text:"Highcharts.com",href:"http://www.highcharts.com",position:{align:"right",x:-10,verticalAlign:"bottom",y:-5},style:{cursor:"pointer",color:"#909090",fontSize:"9px"}}};var Z=L.plotOptions,Y=Z.line;Lb();var Yb=/rgba\\(\\s*([0-9]{1,3})\\s*,\\s*([0-9]{1,3})\\s*,\\s*([0-9]{1,3})\\s*,\\s*([0-9]?(?:\\.[0-9]+)?)\\s*\\)/,Zb=/#([a-fA-F0-9]{2})([a-fA-F0-9]{2})([a-fA-F0-9]{2})/,$b=/rgb\\(\\s*([0-9]{1,3})\\s*,\\s*([0-9]{1,3})\\s*,\\s*([0-9]{1,3})\\s*\\)/,
qa=function(a){var b=[],c,d;(function(a){a&&a.stops?d=Na(a.stops,function(a){return qa(a[1])}):(c=Yb.exec(a))?b=[y(c[1]),y(c[2]),y(c[3]),parseFloat(c[4],10)]:(c=Zb.exec(a))?b=[y(c[1],16),y(c[2],16),y(c[3],16),1]:(c=$b.exec(a))&&(b=[y(c[1]),y(c[2]),y(c[3]),1])})(a);return{get:function(c){var f;d?(f=x(a),f.stops=[].concat(f.stops),n(d,function(a,b){f.stops[b]=[f.stops[b][0],a.get(c)]})):f=b&&!isNaN(b[0])?c==="rgb"?"rgb("+b[0]+","+b[1]+","+b[2]+")":c==="a"?b[3]:"rgba("+b.join(",")+")":a;return f},brighten:function(a){if(d)n(d,
function(b){b.brighten(a)});else if(ra(a)&&a!==0){var c;for(c=0;c<3;c++)b[c]+=y(a*255),b[c]<0&&(b[c]=0),b[c]>255&&(b[c]=255)}return this},rgba:b,setOpacity:function(a){b[3]=a;return this}}};va.prototype={init:function(a,b){this.element=b==="span"?U(b):z.createElementNS(ya,b);this.renderer=a;this.attrSetters={}},opacity:1,animate:function(a,b,c){b=o(b,Fa,!0);Wa(this);if(b){b=x(b);if(c)b.complete=c;Bb(this,a,b)}else this.attr(a),c&&c()},attr:function(a,b){var c,d,e,f,g=this.element,h=g.nodeName.toLowerCase(),
i=this.renderer,j,k=this.attrSetters,l=this.shadows,m,p,q=this;ea(a)&&u(b)&&(c=a,a={},a[c]=b);if(ea(a))c=a,h==="circle"?c={x:"cx",y:"cy"}[c]||c:c==="strokeWidth"&&(c="stroke-width"),q=w(g,c)||this[c]||0,c!=="d"&&c!=="visibility"&&c!=="fill"&&(q=parseFloat(q));else{for(c in a)if(j=!1,d=a[c],e=k[c]&&k[c].call(this,d,c),e!==!1){e!==v&&(d=e);if(c==="d")d&&d.join&&(d=d.join(" ")),/(NaN| {2}|^$)/.test(d)&&(d="M 0 0");else if(c==="x"&&h==="text")for(e=0;e<g.childNodes.length;e++)f=g.childNodes[e],w(f,"x")===
w(g,"x")&&w(f,"x",d);else if(this.rotation&&(c==="x"||c==="y"))p=!0;else if(c==="fill")d=i.color(d,g,c);else if(h==="circle"&&(c==="x"||c==="y"))c={x:"cx",y:"cy"}[c]||c;else if(h==="rect"&&c==="r")w(g,{rx:d,ry:d}),j=!0;else if(c==="translateX"||c==="translateY"||c==="rotation"||c==="verticalAlign"||c==="scaleX"||c==="scaleY")j=p=!0;else if(c==="stroke")d=i.color(d,g,c);else if(c==="dashstyle")if(c="stroke-dasharray",d=d&&d.toLowerCase(),d==="solid")d=S;else{if(d){d=d.replace("shortdashdotdot","3,1,1,1,1,1,").replace("shortdashdot",
"3,1,1,1").replace("shortdot","1,1,").replace("shortdash","3,1,").replace("longdash","8,3,").replace(/dot/g,"1,3,").replace("dash","4,3,").replace(/,$/,"").split(",");for(e=d.length;e--;)d[e]=y(d[e])*o(a["stroke-width"],this["stroke-width"]);d=d.join(",")}}else if(c==="width")d=y(d);else if(c==="align")c="text-anchor",d={left:"start",center:"middle",right:"end"}[d];else if(c==="title")e=g.getElementsByTagName("title")[0],e||(e=z.createElementNS(ya,"title"),g.appendChild(e)),e.textContent=d;c==="strokeWidth"&&
(c="stroke-width");if(c==="stroke-width"||c==="stroke"){this[c]=d;if(this.stroke&&this["stroke-width"])w(g,"stroke",this.stroke),w(g,"stroke-width",this["stroke-width"]),this.hasStroke=!0;else if(c==="stroke-width"&&d===0&&this.hasStroke)g.removeAttribute("stroke"),this.hasStroke=!1;j=!0}this.symbolName&&/^(x|y|width|height|r|start|end|innerR|anchorX|anchorY)/.test(c)&&(m||(this.symbolAttr(a),m=!0),j=!0);if(l&&/^(width|height|visibility|x|y|d|transform|cx|cy|r)$/.test(c))for(e=l.length;e--;)w(l[e],
c,c==="height"?r(d-(l[e].cutHeight||0),0):d);if((c==="width"||c==="height")&&h==="rect"&&d<0)d=0;this[c]=d;c==="text"?(d!==this.textStr&&delete this.bBox,this.textStr=d,this.added&&i.buildText(this)):j||w(g,c,d)}p&&this.updateTransform()}return q},addClass:function(a){var b=this.element,c=w(b,"class")||"";c.indexOf(a)===-1&&w(b,"class",c+" "+a);return this},symbolAttr:function(a){var b=this;n("x,y,r,start,end,width,height,innerR,anchorX,anchorY".split(","),function(c){b[c]=o(a[c],b[c])});b.attr({d:b.renderer.symbols[b.symbolName](b.x,
b.y,b.width,b.height,b)})},clip:function(a){return this.attr("clip-path",a?"url("+this.renderer.url+"#"+a.id+")":S)},crisp:function(a,b,c,d,e){var f,g={},h={},i,a=a||this.strokeWidth||this.attr&&this.attr("stroke-width")||0;i=t(a)%2/2;h.x=P(b||this.x||0)+i;h.y=P(c||this.y||0)+i;h.width=P((d||this.width||0)-2*i);h.height=P((e||this.height||0)-2*i);h.strokeWidth=a;for(f in h)this[f]!==h[f]&&(this[f]=g[f]=h[f]);return g},css:function(a){var b=this.element,c=this.textWidth=a&&a.width&&b.nodeName.toLowerCase()===
"text"&&y(a.width),d,e="",f=function(a,b){return"-"+b.toLowerCase()};if(a&&a.color)a.fill=a.color;this.styles=a=s(this.styles,a);c&&delete a.width;if(sa&&!W)I(this.element,a);else{for(d in a)e+=d.replace(/([A-Z])/g,f)+":"+a[d]+";";w(b,"style",e)}c&&this.added&&this.renderer.buildText(this);return this},on:function(a,b){var c=this,d=c.element;jb&&a==="click"?(d.ontouchstart=function(a){c.touchEventFired=Date.now();a.preventDefault();b.call(d,a)},d.onclick=function(a){(na.indexOf("Android")===-1||Date.now()-
(c.touchEventFired||0)>1100)&&b.call(d,a)}):d["on"+a]=b;return this},setRadialReference:function(a){this.element.radialReference=a;return this},translate:function(a,b){return this.attr({translateX:a,translateY:b})},invert:function(){this.inverted=!0;this.updateTransform();return this},htmlCss:function(a){var b=this.element;if(b=a&&b.tagName==="SPAN"&&a.width)delete a.width,this.textWidth=b,this.updateTransform();this.styles=s(this.styles,a);I(this.element,a);return this},htmlGetBBox:function(){var a=
this.element,b=this.bBox;if(!b){if(a.nodeName==="text")a.style.position="absolute";b=this.bBox={x:a.offsetLeft,y:a.offsetTop,width:a.offsetWidth,height:a.offsetHeight}}return b},htmlUpdateTransform:function(){if(this.added){var a=this.renderer,b=this.element,c=this.translateX||0,d=this.translateY||0,e=this.x||0,f=this.y||0,g=this.textAlign||"left",h={left:0,center:0.5,right:1}[g],i=g&&g!=="left",j=this.shadows;I(b,{marginLeft:c,marginTop:d});j&&n(j,function(a){I(a,{marginLeft:c+1,marginTop:d+1})});
this.inverted&&n(b.childNodes,function(c){a.invertChild(c,b)});if(b.tagName==="SPAN"){var k,l,j=this.rotation,m;k=0;var p=1,q=0,aa;m=y(this.textWidth);var B=this.xCorr||0,O=this.yCorr||0,Sb=[j,g,b.innerHTML,this.textWidth].join(",");if(Sb!==this.cTT){u(j)&&(k=j*Ua,p=V(k),q=ba(k),this.setSpanRotation(j,q,p));k=o(this.elemWidth,b.offsetWidth);l=o(this.elemHeight,b.offsetHeight);if(k>m&&/[ \\-]/.test(b.textContent||b.innerText))I(b,{width:m+"px",display:"block",whiteSpace:"normal"}),k=m;m=a.fontMetrics(b.style.fontSize).b;
B=p<0&&-k;O=q<0&&-l;aa=p*q<0;B+=q*m*(aa?1-h:h);O-=p*m*(j?aa?h:1-h:1);i&&(B-=k*h*(p<0?-1:1),j&&(O-=l*h*(q<0?-1:1)),I(b,{textAlign:g}));this.xCorr=B;this.yCorr=O}I(b,{left:e+B+"px",top:f+O+"px"});if(hb)l=b.offsetHeight;this.cTT=Sb}}else this.alignOnAdd=!0},setSpanRotation:function(a){var b={};b[sa?"-ms-transform":hb?"-webkit-transform":ib?"MozTransform":Nb?"-o-transform":""]=b.transform="rotate("+a+"deg)";I(this.element,b)},updateTransform:function(){var a=this.translateX||0,b=this.translateY||0,c=
this.scaleX,d=this.scaleY,e=this.inverted,f=this.rotation;e&&(a+=this.attr("width"),b+=this.attr("height"));a=["translate("+a+","+b+")"];e?a.push("rotate(90) scale(-1,1)"):f&&a.push("rotate("+f+" "+(this.x||0)+" "+(this.y||0)+")");(u(c)||u(d))&&a.push("scale("+o(c,1)+" "+o(d,1)+")");a.length&&w(this.element,"transform",a.join(" "))},toFront:function(){var a=this.element;a.parentNode.appendChild(a);return this},align:function(a,b,c){var d,e,f,g,h={};e=this.renderer;f=e.alignedObjects;if(a){if(this.alignOptions=
a,this.alignByTranslate=b,!c||ea(c))this.alignTo=d=c||"renderer",ga(f,this),f.push(this),c=null}else a=this.alignOptions,b=this.alignByTranslate,d=this.alignTo;c=o(c,e[d],e);d=a.align;e=a.verticalAlign;f=(c.x||0)+(a.x||0);g=(c.y||0)+(a.y||0);if(d==="right"||d==="center")f+=(c.width-(a.width||0))/{right:1,center:2}[d];h[b?"translateX":"x"]=t(f);if(e==="bottom"||e==="middle")g+=(c.height-(a.height||0))/({bottom:1,middle:2}[e]||1);h[b?"translateY":"y"]=t(g);this[this.placed?"animate":"attr"](h);this.placed=
!0;this.alignAttr=h;return this},getBBox:function(){var a=this.bBox,b=this.renderer,c,d=this.rotation;c=this.element;var e=this.styles,f=d*Ua;if(!a){if(c.namespaceURI===ya||b.forExport){try{a=c.getBBox?s({},c.getBBox()):{width:c.offsetWidth,height:c.offsetHeight}}catch(g){}if(!a||a.width<0)a={width:0,height:0}}else a=this.htmlGetBBox();if(b.isSVG){b=a.width;c=a.height;if(sa&&e&&e.fontSize==="11px"&&c.toPrecision(3)==="22.7")a.height=c=14;if(d)a.width=M(c*ba(f))+M(b*V(f)),a.height=M(c*V(f))+M(b*ba(f))}this.bBox=
a}return a},show:function(){return this.attr({visibility:"visible"})},hide:function(){return this.attr({visibility:"hidden"})},fadeOut:function(a){var b=this;b.animate({opacity:0},{duration:a||150,complete:function(){b.hide()}})},add:function(a){var b=this.renderer,c=a||b,d=c.element||b.box,e=d.childNodes,f=this.element,g=w(f,"zIndex"),h;if(a)this.parentGroup=a;this.parentInverted=a&&a.inverted;this.textStr!==void 0&&b.buildText(this);if(g)c.handleZ=!0,g=y(g);if(c.handleZ)for(c=0;c<e.length;c++)if(a=
e[c],b=w(a,"zIndex"),a!==f&&(y(b)>g||!u(g)&&u(b))){d.insertBefore(f,a);h=!0;break}h||d.appendChild(f);this.added=!0;A(this,"add");return this},safeRemoveChild:function(a){var b=a.parentNode;b&&b.removeChild(a)},destroy:function(){var a=this,b=a.element||{},c=a.shadows,d=a.renderer.isSVG&&b.nodeName==="SPAN"&&a.parentGroup,e,f;b.onclick=b.onmouseout=b.onmouseover=b.onmousemove=b.point=null;Wa(a);if(a.clipPath)a.clipPath=a.clipPath.destroy();if(a.stops){for(f=0;f<a.stops.length;f++)a.stops[f]=a.stops[f].destroy();
a.stops=null}a.safeRemoveChild(b);for(c&&n(c,function(b){a.safeRemoveChild(b)});d&&d.div.childNodes.length===0;)b=d.parentGroup,a.safeRemoveChild(d.div),delete d.div,d=b;a.alignTo&&ga(a.renderer.alignedObjects,a);for(e in a)delete a[e];return null},shadow:function(a,b,c){var d=[],e,f,g=this.element,h,i,j,k;if(a){i=o(a.width,3);j=(a.opacity||0.15)/i;k=this.parentInverted?"(-1,-1)":"("+o(a.offsetX,1)+", "+o(a.offsetY,1)+")";for(e=1;e<=i;e++){f=g.cloneNode(0);h=i*2+1-2*e;w(f,{isShadow:"true",stroke:a.color||
"black","stroke-opacity":j*e,"stroke-width":h,transform:"translate"+k,fill:S});if(c)w(f,"height",r(w(f,"height")-h,0)),f.cutHeight=h;b?b.element.appendChild(f):g.parentNode.insertBefore(f,g);d.push(f)}this.shadows=d}return this}};var za=function(){this.init.apply(this,arguments)};za.prototype={Element:va,init:function(a,b,c,d){var e=location,f,g;f=this.createElement("svg").attr({version:"1.1"});g=f.element;a.appendChild(g);a.innerHTML.indexOf("xmlns")===-1&&w(g,"xmlns",ya);this.isSVG=!0;this.box=
g;this.boxWrapper=f;this.alignedObjects=[];this.url=(ib||hb)&&z.getElementsByTagName("base").length?e.href.replace(/#.*?$/,"").replace(/([\\(\'\\)])/g,"\\\\$1").replace(/ /g,"%20"):"";this.createElement("desc").add().element.appendChild(z.createTextNode("Created with Highcharts 3.0.7"));this.defs=this.createElement("defs").add();this.forExport=d;this.gradients={};this.setSize(b,c,!1);var h;if(ib&&a.getBoundingClientRect)this.subPixelFix=b=function(){I(a,{left:0,top:0});h=a.getBoundingClientRect();I(a,
{left:wa(h.left)-h.left+"px",top:wa(h.top)-h.top+"px"})},b(),K(N,"resize",b)},isHidden:function(){return!this.boxWrapper.getBBox().width},destroy:function(){var a=this.defs;this.box=null;this.boxWrapper=this.boxWrapper.destroy();Ka(this.gradients||{});this.gradients=null;if(a)this.defs=a.destroy();this.subPixelFix&&$(N,"resize",this.subPixelFix);return this.alignedObjects=null},createElement:function(a){var b=new this.Element;b.init(this,a);return b},draw:function(){},buildText:function(a){for(var b=
a.element,c=this,d=c.forExport,e=o(a.textStr,"").toString().replace(/<(b|strong)>/g,\'<span style="font-weight:bold">\').replace(/<(i|em)>/g,\'<span style="font-style:italic">\').replace(/<a/g,"<span").replace(/<\\/(b|strong|i|em|a)>/g,"</span>").split(/<br.*?>/g),f=b.childNodes,g=/style="([^"]+)"/,h=/href="(http[^"]+)"/,i=w(b,"x"),j=a.styles,k=a.textWidth,l=j&&j.lineHeight,m=f.length;m--;)b.removeChild(f[m]);k&&!a.added&&this.box.appendChild(b);e[e.length-1]===""&&e.pop();n(e,function(e,f){var m,o=0,
e=e.replace(/<span/g,"|||<span").replace(/<\\/span>/g,"</span>|||");m=e.split("|||");n(m,function(e){if(e!==""||m.length===1){var p={},n=z.createElementNS(ya,"tspan"),r;g.test(e)&&(r=e.match(g)[1].replace(/(;| |^)color([ :])/,"$1fill$2"),w(n,"style",r));h.test(e)&&!d&&(w(n,"onclick",\'location.href="\'+e.match(h)[1]+\'"\'),I(n,{cursor:"pointer"}));e=(e.replace(/<(.|\\n)*?>/g,"")||" ").replace(/&lt;/g,"<").replace(/&gt;/g,">");if(e!==" "&&(n.appendChild(z.createTextNode(e)),o?p.dx=0:p.x=i,w(n,p),!o&&f&&
(!W&&d&&I(n,{display:"block"}),w(n,"dy",l||c.fontMetrics(/px$/.test(n.style.fontSize)?n.style.fontSize:j.fontSize).h,hb&&n.offsetHeight)),b.appendChild(n),o++,k))for(var e=e.replace(/([^\\^])-/g,"$1- ").split(" "),u,t,p=a._clipHeight,E=[],v=y(l||16),s=1;e.length||E.length;)delete a.bBox,u=a.getBBox(),t=u.width,!W&&c.forExport&&(t=c.measureSpanWidth(n.firstChild.data,a.styles)),u=t>k,!u||e.length===1?(e=E,E=[],e.length&&(s++,p&&s*v>p?(e=["..."],a.attr("title",a.textStr)):(n=z.createElementNS(ya,"tspan"),
w(n,{dy:v,x:i}),r&&w(n,"style",r),b.appendChild(n),t>k&&(k=t)))):(n.removeChild(n.firstChild),E.unshift(e.pop())),e.length&&n.appendChild(z.createTextNode(e.join(" ").replace(/- /g,"-")))}})})},button:function(a,b,c,d,e,f,g,h,i){var j=this.label(a,b,c,i,null,null,null,null,"button"),k=0,l,m,p,q,n,o,a={x1:0,y1:0,x2:0,y2:1},e=x({"stroke-width":1,stroke:"#CCCCCC",fill:{linearGradient:a,stops:[[0,"#FEFEFE"],[1,"#F6F6F6"]]},r:2,padding:5,style:{color:"black"}},e);p=e.style;delete e.style;f=x(e,{stroke:"#68A",
fill:{linearGradient:a,stops:[[0,"#FFF"],[1,"#ACF"]]}},f);q=f.style;delete f.style;g=x(e,{stroke:"#68A",fill:{linearGradient:a,stops:[[0,"#9BD"],[1,"#CDF"]]}},g);n=g.style;delete g.style;h=x(e,{style:{color:"#CCC"}},h);o=h.style;delete h.style;K(j.element,sa?"mouseover":"mouseenter",function(){k!==3&&j.attr(f).css(q)});K(j.element,sa?"mouseout":"mouseleave",function(){k!==3&&(l=[e,f,g][k],m=[p,q,n][k],j.attr(l).css(m))});j.setState=function(a){(j.state=k=a)?a===2?j.attr(g).css(n):a===3&&j.attr(h).css(o):
j.attr(e).css(p)};return j.on("click",function(){k!==3&&d.call(j)}).attr(e).css(s({cursor:"default"},p))},crispLine:function(a,b){a[1]===a[4]&&(a[1]=a[4]=t(a[1])-b%2/2);a[2]===a[5]&&(a[2]=a[5]=t(a[2])+b%2/2);return a},path:function(a){var b={fill:S};Ia(a)?b.d=a:T(a)&&s(b,a);return this.createElement("path").attr(b)},circle:function(a,b,c){a=T(a)?a:{x:a,y:b,r:c};return this.createElement("circle").attr(a)},arc:function(a,b,c,d,e,f){if(T(a))b=a.y,c=a.r,d=a.innerR,e=a.start,f=a.end,a=a.x;a=this.symbol("arc",
a||0,b||0,c||0,c||0,{innerR:d||0,start:e||0,end:f||0});a.r=c;return a},rect:function(a,b,c,d,e,f){e=T(a)?a.r:e;e=this.createElement("rect").attr({rx:e,ry:e,fill:S});return e.attr(T(a)?a:e.crisp(f,a,b,r(c,0),r(d,0)))},setSize:function(a,b,c){var d=this.alignedObjects,e=d.length;this.width=a;this.height=b;for(this.boxWrapper[o(c,!0)?"animate":"attr"]({width:a,height:b});e--;)d[e].align()},g:function(a){var b=this.createElement("g");return u(a)?b.attr({"class":"highcharts-"+a}):b},image:function(a,b,
c,d,e){var f={preserveAspectRatio:S};arguments.length>1&&s(f,{x:b,y:c,width:d,height:e});f=this.createElement("image").attr(f);f.element.setAttributeNS?f.element.setAttributeNS("http://www.w3.org/1999/xlink","href",a):f.element.setAttribute("hc-svg-href",a);return f},symbol:function(a,b,c,d,e,f){var g,h=this.symbols[a],h=h&&h(t(b),t(c),d,e,f),i=/^url\\((.*?)\\)$/,j,k;if(h)g=this.path(h),s(g,{symbolName:a,x:b,y:c,width:d,height:e}),f&&s(g,f);else if(i.test(a))k=function(a,b){a.element&&(a.attr({width:b[0],
height:b[1]}),a.alignByTranslate||a.translate(t((d-b[0])/2),t((e-b[1])/2)))},j=a.match(i)[1],a=Pb[j],g=this.image(j).attr({x:b,y:c}),g.isImg=!0,a?k(g,a):(g.attr({width:0,height:0}),U("img",{onload:function(){k(g,Pb[j]=[this.width,this.height])},src:j}));return g},symbols:{circle:function(a,b,c,d){var e=0.166*c;return["M",a+c/2,b,"C",a+c+e,b,a+c+e,b+d,a+c/2,b+d,"C",a-e,b+d,a-e,b,a+c/2,b,"Z"]},square:function(a,b,c,d){return["M",a,b,"L",a+c,b,a+c,b+d,a,b+d,"Z"]},triangle:function(a,b,c,d){return["M",
a+c/2,b,"L",a+c,b+d,a,b+d,"Z"]},"triangle-down":function(a,b,c,d){return["M",a,b,"L",a+c,b,a+c/2,b+d,"Z"]},diamond:function(a,b,c,d){return["M",a+c/2,b,"L",a+c,b+d/2,a+c/2,b+d,a,b+d/2,"Z"]},arc:function(a,b,c,d,e){var f=e.start,c=e.r||c||d,g=e.end-0.001,d=e.innerR,h=e.open,i=V(f),j=ba(f),k=V(g),g=ba(g),e=e.end-f<xa?0:1;return["M",a+c*i,b+c*j,"A",c,c,0,e,1,a+c*k,b+c*g,h?"M":"L",a+d*k,b+d*g,"A",d,d,0,e,0,a+d*i,b+d*j,h?"":"Z"]}},clipRect:function(a,b,c,d){var e="highcharts-"+zb++,f=this.createElement("clipPath").attr({id:e}).add(this.defs),
a=this.rect(a,b,c,d,0).add(f);a.id=e;a.clipPath=f;return a},color:function(a,b,c){var d=this,e,f=/^rgba/,g,h,i,j,k,l,m,p=[];a&&a.linearGradient?g="linearGradient":a&&a.radialGradient&&(g="radialGradient");if(g){c=a[g];h=d.gradients;j=a.stops;b=b.radialReference;Ia(c)&&(a[g]=c={x1:c[0],y1:c[1],x2:c[2],y2:c[3],gradientUnits:"userSpaceOnUse"});g==="radialGradient"&&b&&!u(c.gradientUnits)&&(c=x(c,{cx:b[0]-b[2]/2+c.cx*b[2],cy:b[1]-b[2]/2+c.cy*b[2],r:c.r*b[2],gradientUnits:"userSpaceOnUse"}));for(m in c)m!==
"id"&&p.push(m,c[m]);for(m in j)p.push(j[m]);p=p.join(",");h[p]?a=h[p].id:(c.id=a="highcharts-"+zb++,h[p]=i=d.createElement(g).attr(c).add(d.defs),i.stops=[],n(j,function(a){f.test(a[1])?(e=qa(a[1]),k=e.get("rgb"),l=e.get("a")):(k=a[1],l=1);a=d.createElement("stop").attr({offset:a[0],"stop-color":k,"stop-opacity":l}).add(i);i.stops.push(a)}));return"url("+d.url+"#"+a+")"}else return f.test(a)?(e=qa(a),w(b,c+"-opacity",e.get("a")),e.get("rgb")):(b.removeAttribute(c+"-opacity"),a)},text:function(a,
b,c,d){var e=L.chart.style,f=ca||!W&&this.forExport;if(d&&!this.forExport)return this.html(a,b,c);b=t(o(b,0));c=t(o(c,0));a=this.createElement("text").attr({x:b,y:c,text:a}).css({fontFamily:e.fontFamily,fontSize:e.fontSize});f&&a.css({position:"absolute"});a.x=b;a.y=c;return a},html:function(a,b,c){var d=L.chart.style,e=this.createElement("span"),f=e.attrSetters,g=e.element,h=e.renderer;f.text=function(a){a!==g.innerHTML&&delete this.bBox;g.innerHTML=a;return!1};f.x=f.y=f.align=function(a,b){b===
"align"&&(b="textAlign");e[b]=a;e.htmlUpdateTransform();return!1};e.attr({text:a,x:t(b),y:t(c)}).css({position:"absolute",whiteSpace:"nowrap",fontFamily:d.fontFamily,fontSize:d.fontSize});e.css=e.htmlCss;if(h.isSVG)e.add=function(a){var b,c=h.box.parentNode,d=[];if(this.parentGroup=a){if(b=a.div,!b){for(;a;)d.push(a),a=a.parentGroup;n(d.reverse(),function(a){var d;b=a.div=a.div||U(Ea,{className:w(a.element,"class")},{position:"absolute",left:(a.translateX||0)+"px",top:(a.translateY||0)+"px"},b||c);
d=b.style;s(a.attrSetters,{translateX:function(a){d.left=a+"px"},translateY:function(a){d.top=a+"px"},visibility:function(a,b){d[b]=a}})})}}else b=c;b.appendChild(g);e.added=!0;e.alignOnAdd&&e.htmlUpdateTransform();return e};return e},fontMetrics:function(a){var a=y(a||11),a=a<24?a+4:t(a*1.2),b=t(a*0.8);return{h:a,b:b}},label:function(a,b,c,d,e,f,g,h,i){function j(){var a,b;a=o.element.style;O=(Oa===void 0||Ha===void 0||q.styles.textAlign)&&o.getBBox();q.width=(Oa||O.width||0)+2*da+lb;q.height=(Ha||
O.height||0)+2*da;w=da+p.fontMetrics(a&&a.fontSize).b;if(y){if(!B)a=t(-r*da),b=h?-w:0,q.box=B=d?p.symbol(d,a,b,q.width,q.height,Xa):p.rect(a,b,q.width,q.height,0,Xa[Rb]),B.add(q);B.isImg||B.attr(x({width:q.width,height:q.height},Xa));Xa=null}}function k(){var a=q.styles,a=a&&a.textAlign,b=lb+da*(1-r),c;c=h?0:w;if(u(Oa)&&(a==="center"||a==="right"))b+={center:0.5,right:1}[a]*(Oa-O.width);(b!==o.x||c!==o.y)&&o.attr({x:b,y:c});o.x=b;o.y=c}function l(a,b){B?B.attr(a,b):Xa[a]=b}function m(){o.add(q);q.attr({text:a,
x:b,y:c});B&&u(e)&&q.attr({anchorX:e,anchorY:f})}var p=this,q=p.g(i),o=p.text("",0,0,g).attr({zIndex:1}),B,O,r=0,da=3,lb=0,Oa,Ha,E,H,C=0,Xa={},w,g=q.attrSetters,y;K(q,"add",m);g.width=function(a){Oa=a;return!1};g.height=function(a){Ha=a;return!1};g.padding=function(a){u(a)&&a!==da&&(da=a,k());return!1};g.paddingLeft=function(a){u(a)&&a!==lb&&(lb=a,k());return!1};g.align=function(a){r={left:0,center:0.5,right:1}[a];return!1};g.text=function(a,b){o.attr(b,a);j();k();return!1};g[Rb]=function(a,b){y=
!0;C=a%2/2;l(b,a);return!1};g.stroke=g.fill=g.r=function(a,b){b==="fill"&&(y=!0);l(b,a);return!1};g.anchorX=function(a,b){e=a;l(b,a+C-E);return!1};g.anchorY=function(a,b){f=a;l(b,a-H);return!1};g.x=function(a){q.x=a;a-=r*((Oa||O.width)+da);E=t(a);q.attr("translateX",E);return!1};g.y=function(a){H=q.y=t(a);q.attr("translateY",H);return!1};var z=q.css;return s(q,{css:function(a){if(a){var b={},a=x(a);n("fontSize,fontWeight,fontFamily,color,lineHeight,width,textDecoration,textShadow".split(","),function(c){a[c]!==
v&&(b[c]=a[c],delete a[c])});o.css(b)}return z.call(q,a)},getBBox:function(){return{width:O.width+2*da,height:O.height+2*da,x:O.x-da,y:O.y-da}},shadow:function(a){B&&B.shadow(a);return q},destroy:function(){$(q,"add",m);$(q.element,"mouseenter");$(q.element,"mouseleave");o&&(o=o.destroy());B&&(B=B.destroy());va.prototype.destroy.call(q);q=p=j=k=l=m=null}})}};Va=za;var F;if(!W&&!ca){Highcharts.VMLElement=F={init:function(a,b){var c=["<",b,\' filled="f" stroked="f"\'],d=["position: ","absolute",";"],
e=b===Ea;(b==="shape"||e)&&d.push("left:0;top:0;width:1px;height:1px;");d.push("visibility: ",e?"hidden":"visible");c.push(\' style="\',d.join(""),\'"/>\');if(b)c=e||b==="span"||b==="img"?c.join(""):a.prepVML(c),this.element=U(c);this.renderer=a;this.attrSetters={}},add:function(a){var b=this.renderer,c=this.element,d=b.box,d=a?a.element||a:d;a&&a.inverted&&b.invertChild(c,d);d.appendChild(c);this.added=!0;this.alignOnAdd&&!this.deferUpdateTransform&&this.updateTransform();A(this,"add");return this},
updateTransform:va.prototype.htmlUpdateTransform,setSpanRotation:function(a,b,c){I(this.element,{filter:a?["progid:DXImageTransform.Microsoft.Matrix(M11=",c,", M12=",-b,", M21=",b,", M22=",c,", sizingMethod=\'auto expand\')"].join(""):S})},pathToVML:function(a){for(var b=a.length,c=[],d;b--;)if(ra(a[b]))c[b]=t(a[b]*10)-5;else if(a[b]==="Z")c[b]="x";else if(c[b]=a[b],a.isArc&&(a[b]==="wa"||a[b]==="at"))d=a[b]==="wa"?1:-1,c[b+5]===c[b+7]&&(c[b+7]-=d),c[b+6]===c[b+8]&&(c[b+8]-=d);return c.join(" ")||"x"},
attr:function(a,b){var c,d,e,f=this.element||{},g=f.style,h=f.nodeName,i=this.renderer,j=this.symbolName,k,l=this.shadows,m,p=this.attrSetters,q=this;ea(a)&&u(b)&&(c=a,a={},a[c]=b);if(ea(a))c=a,q=c==="strokeWidth"||c==="stroke-width"?this.strokeweight:this[c];else for(c in a)if(d=a[c],m=!1,e=p[c]&&p[c].call(this,d,c),e!==!1&&d!==null){e!==v&&(d=e);if(j&&/^(x|y|r|start|end|width|height|innerR|anchorX|anchorY)/.test(c))k||(this.symbolAttr(a),k=!0),m=!0;else if(c==="d"){d=d||[];this.d=d.join(" ");f.path=
d=this.pathToVML(d);if(l)for(e=l.length;e--;)l[e].path=l[e].cutOff?this.cutOffPath(d,l[e].cutOff):d;m=!0}else if(c==="visibility"){if(l)for(e=l.length;e--;)l[e].style[c]=d;h==="DIV"&&(d=d==="hidden"?"-999em":0,gb||(g[c]=d?"visible":"hidden"),c="top");g[c]=d;m=!0}else if(c==="zIndex")d&&(g[c]=d),m=!0;else if(pa(c,["x","y","width","height"])!==-1)this[c]=d,c==="x"||c==="y"?c={x:"left",y:"top"}[c]:d=r(0,d),this.updateClipping?(this[c]=d,this.updateClipping()):g[c]=d,m=!0;else if(c==="class"&&h==="DIV")f.className=
d;else if(c==="stroke")d=i.color(d,f,c),c="strokecolor";else if(c==="stroke-width"||c==="strokeWidth")f.stroked=d?!0:!1,c="strokeweight",this[c]=d,ra(d)&&(d+="px");else if(c==="dashstyle")(f.getElementsByTagName("stroke")[0]||U(i.prepVML(["<stroke/>"]),null,null,f))[c]=d||"solid",this.dashstyle=d,m=!0;else if(c==="fill")if(h==="SPAN")g.color=d;else{if(h!=="IMG")f.filled=d!==S?!0:!1,d=i.color(d,f,c,this),c="fillcolor"}else if(c==="opacity")m=!0;else if(h==="shape"&&c==="rotation")this[c]=f.style[c]=
d,f.style.left=-t(ba(d*Ua)+1)+"px",f.style.top=t(V(d*Ua))+"px";else if(c==="translateX"||c==="translateY"||c==="rotation")this[c]=d,this.updateTransform(),m=!0;else if(c==="text")this.bBox=null,f.innerHTML=d,m=!0;m||(gb?f[c]=d:w(f,c,d))}return q},clip:function(a){var b=this,c;a?(c=a.members,ga(c,b),c.push(b),b.destroyClip=function(){ga(c,b)},a=a.getCSS(b)):(b.destroyClip&&b.destroyClip(),a={clip:gb?"inherit":"rect(auto)"});return b.css(a)},css:va.prototype.htmlCss,safeRemoveChild:function(a){a.parentNode&&
Ta(a)},destroy:function(){this.destroyClip&&this.destroyClip();return va.prototype.destroy.apply(this)},on:function(a,b){this.element["on"+a]=function(){var a=N.event;a.target=a.srcElement;b(a)};return this},cutOffPath:function(a,b){var c,a=a.split(/[ ,]/);c=a.length;if(c===9||c===11)a[c-4]=a[c-2]=y(a[c-2])-10*b;return a.join(" ")},shadow:function(a,b,c){var d=[],e,f=this.element,g=this.renderer,h,i=f.style,j,k=f.path,l,m,p,q;k&&typeof k.value!=="string"&&(k="x");m=k;if(a){p=o(a.width,3);q=(a.opacity||
0.15)/p;for(e=1;e<=3;e++){l=p*2+1-2*e;c&&(m=this.cutOffPath(k.value,l+0.5));j=[\'<shape isShadow="true" strokeweight="\',l,\'" filled="false" path="\',m,\'" coordsize="10 10" style="\',f.style.cssText,\'" />\'];h=U(g.prepVML(j),null,{left:y(i.left)+o(a.offsetX,1),top:y(i.top)+o(a.offsetY,1)});if(c)h.cutOff=l+1;j=[\'<stroke color="\',a.color||"black",\'" opacity="\',q*e,\'"/>\'];U(g.prepVML(j),null,null,h);b?b.element.appendChild(h):f.parentNode.insertBefore(h,f);d.push(h)}this.shadows=d}return this}};F=ha(va,F);
var la={Element:F,isIE8:na.indexOf("MSIE 8.0")>-1,init:function(a,b,c){var d,e;this.alignedObjects=[];d=this.createElement(Ea);e=d.element;e.style.position="relative";a.appendChild(d.element);this.isVML=!0;this.box=e;this.boxWrapper=d;this.setSize(b,c,!1);if(!z.namespaces.hcv){z.namespaces.add("hcv","urn:schemas-microsoft-com:vml");try{z.createStyleSheet().cssText="hcv\\\\:fill, hcv\\\\:path, hcv\\\\:shape, hcv\\\\:stroke{ behavior:url(#default#VML); display: inline-block; } "}catch(f){z.styleSheets[0].cssText+=
"hcv\\\\:fill, hcv\\\\:path, hcv\\\\:shape, hcv\\\\:stroke{ behavior:url(#default#VML); display: inline-block; } "}}},isHidden:function(){return!this.box.offsetWidth},clipRect:function(a,b,c,d){var e=this.createElement(),f=T(a);return s(e,{members:[],left:(f?a.x:a)+1,top:(f?a.y:b)+1,width:(f?a.width:c)-1,height:(f?a.height:d)-1,getCSS:function(a){var b=a.element,c=b.nodeName,a=a.inverted,d=this.top-(c==="shape"?b.offsetTop:0),e=this.left,b=e+this.width,f=d+this.height,d={clip:"rect("+t(a?e:d)+"px,"+t(a?f:
b)+"px,"+t(a?b:f)+"px,"+t(a?d:e)+"px)"};!a&&gb&&c==="DIV"&&s(d,{width:b+"px",height:f+"px"});return d},updateClipping:function(){n(e.members,function(a){a.css(e.getCSS(a))})}})},color:function(a,b,c,d){var e=this,f,g=/^rgba/,h,i,j=S;a&&a.linearGradient?i="gradient":a&&a.radialGradient&&(i="pattern");if(i){var k,l,m=a.linearGradient||a.radialGradient,p,q,o,B,O,r="",a=a.stops,u,t=[],v=function(){h=[\'<fill colors="\'+t.join(",")+\'" opacity="\',o,\'" o:opacity2="\',q,\'" type="\',i,\'" \',r,\'focus="100%" method="any" />\'];
U(e.prepVML(h),null,null,b)};p=a[0];u=a[a.length-1];p[0]>0&&a.unshift([0,p[1]]);u[0]<1&&a.push([1,u[1]]);n(a,function(a,b){g.test(a[1])?(f=qa(a[1]),k=f.get("rgb"),l=f.get("a")):(k=a[1],l=1);t.push(a[0]*100+"% "+k);b?(o=l,B=k):(q=l,O=k)});if(c==="fill")if(i==="gradient")c=m.x1||m[0]||0,a=m.y1||m[1]||0,p=m.x2||m[2]||0,m=m.y2||m[3]||0,r=\'angle="\'+(90-R.atan((m-a)/(p-c))*180/xa)+\'"\',v();else{var j=m.r,s=j*2,E=j*2,H=m.cx,C=m.cy,x=b.radialReference,w,j=function(){x&&(w=d.getBBox(),H+=(x[0]-w.x)/w.width-
0.5,C+=(x[1]-w.y)/w.height-0.5,s*=x[2]/w.width,E*=x[2]/w.height);r=\'src="\'+L.global.VMLRadialGradientURL+\'" size="\'+s+","+E+\'" origin="0.5,0.5" position="\'+H+","+C+\'" color2="\'+O+\'" \';v()};d.added?j():K(d,"add",j);j=B}else j=k}else if(g.test(a)&&b.tagName!=="IMG")f=qa(a),h=["<",c,\' opacity="\',f.get("a"),\'"/>\'],U(this.prepVML(h),null,null,b),j=f.get("rgb");else{j=b.getElementsByTagName(c);if(j.length)j[0].opacity=1,j[0].type="solid";j=a}return j},prepVML:function(a){var b=this.isIE8,a=a.join("");b?
(a=a.replace("/>",\' xmlns="urn:schemas-microsoft-com:vml" />\'),a=a.indexOf(\'style="\')===-1?a.replace("/>",\' style="display:inline-block;behavior:url(#default#VML);" />\'):a.replace(\'style="\',\'style="display:inline-block;behavior:url(#default#VML);\')):a=a.replace("<","<hcv:");return a},text:za.prototype.html,path:function(a){var b={coordsize:"10 10"};Ia(a)?b.d=a:T(a)&&s(b,a);return this.createElement("shape").attr(b)},circle:function(a,b,c){var d=this.symbol("circle");if(T(a))c=a.r,b=a.y,a=a.x;d.isCircle=
!0;d.r=c;return d.attr({x:a,y:b})},g:function(a){var b;a&&(b={className:"highcharts-"+a,"class":"highcharts-"+a});return this.createElement(Ea).attr(b)},image:function(a,b,c,d,e){var f=this.createElement("img").attr({src:a});arguments.length>1&&f.attr({x:b,y:c,width:d,height:e});return f},rect:function(a,b,c,d,e,f){var g=this.symbol("rect");g.r=T(a)?a.r:e;return g.attr(T(a)?a:g.crisp(f,a,b,r(c,0),r(d,0)))},invertChild:function(a,b){var c=b.style;I(a,{flip:"x",left:y(c.width)-1,top:y(c.height)-1,rotation:-90})},
symbols:{arc:function(a,b,c,d,e){var f=e.start,g=e.end,h=e.r||c||d,c=e.innerR,d=V(f),i=ba(f),j=V(g),k=ba(g);if(g-f===0)return["x"];f=["wa",a-h,b-h,a+h,b+h,a+h*d,b+h*i,a+h*j,b+h*k];e.open&&!c&&f.push("e","M",a,b);f.push("at",a-c,b-c,a+c,b+c,a+c*j,b+c*k,a+c*d,b+c*i,"x","e");f.isArc=!0;return f},circle:function(a,b,c,d,e){e&&(c=d=2*e.r);e&&e.isCircle&&(a-=c/2,b-=d/2);return["wa",a,b,a+c,b+d,a+c,b+d/2,a+c,b+d/2,"e"]},rect:function(a,b,c,d,e){var f=a+c,g=b+d,h;!u(e)||!e.r?f=za.prototype.symbols.square.apply(0,
arguments):(h=J(e.r,c,d),f=["M",a+h,b,"L",f-h,b,"wa",f-2*h,b,f,b+2*h,f-h,b,f,b+h,"L",f,g-h,"wa",f-2*h,g-2*h,f,g,f,g-h,f-h,g,"L",a+h,g,"wa",a,g-2*h,a+2*h,g,a+h,g,a,g-h,"L",a,b+h,"wa",a,b,a+2*h,b+2*h,a,b+h,a+h,b,"x","e"]);return f}}};Highcharts.VMLRenderer=F=function(){this.init.apply(this,arguments)};F.prototype=x(za.prototype,la);Va=F}za.prototype.measureSpanWidth=function(a,b){var c=z.createElement("span"),d=z.createTextNode(a);c.appendChild(d);I(c,b);this.box.appendChild(c);return c.offsetWidth};
var Tb;if(ca)Highcharts.CanVGRenderer=F=function(){ya="http://www.w3.org/1999/xhtml"},F.prototype.symbols={},Tb=function(){function a(){var a=b.length,d;for(d=0;d<a;d++)b[d]();b=[]}var b=[];return{push:function(c,d){b.length===0&&Vb(d,a);b.push(c)}}}(),Va=F;Ma.prototype={addLabel:function(){var a=this.axis,b=a.options,c=a.chart,d=a.horiz,e=a.categories,f=a.names,g=this.pos,h=b.labels,i=a.tickPositions,d=d&&e&&!h.step&&!h.staggerLines&&!h.rotation&&c.plotWidth/i.length||!d&&(c.margin[3]||c.chartWidth*
0.33),j=g===i[0],k=g===i[i.length-1],l,f=e?o(e[g],f[g],g):g,e=this.label,m=i.info;a.isDatetimeAxis&&m&&(l=b.dateTimeLabelFormats[m.higherRanks[g]||m.unitName]);this.isFirst=j;this.isLast=k;b=a.labelFormatter.call({axis:a,chart:c,isFirst:j,isLast:k,dateTimeLabelFormat:l,value:a.isLog?ia(fa(f)):f});g=d&&{width:r(1,t(d-2*(h.padding||10)))+"px"};g=s(g,h.style);if(u(e))e&&e.attr({text:b}).css(g);else{l={align:a.labelAlign};if(ra(h.rotation))l.rotation=h.rotation;if(d&&h.ellipsis)l._clipHeight=a.len/i.length;
this.label=u(b)&&h.enabled?c.renderer.text(b,0,0,h.useHTML).attr(l).css(g).add(a.labelGroup):null}},getLabelSize:function(){var a=this.label,b=this.axis;return a?(this.labelBBox=a.getBBox())[b.horiz?"height":"width"]:0},getLabelSides:function(){var a=this.axis,b=this.labelBBox.width,a=b*{left:0,center:0.5,right:1}[a.labelAlign]-a.options.labels.x;return[-a,b-a]},handleOverflow:function(a,b){var c=!0,d=this.axis,e=d.chart,f=this.isFirst,g=this.isLast,h=b.x,i=d.reversed,j=d.tickPositions;if(f||g){var k=
this.getLabelSides(),l=k[0],k=k[1],e=e.plotLeft,m=e+d.len,j=(d=d.ticks[j[a+(f?1:-1)]])&&d.label.xy&&d.label.xy.x+d.getLabelSides()[f?0:1];f&&!i||g&&i?h+l<e&&(h=e-l,d&&h+k>j&&(c=!1)):h+k>m&&(h=m-k,d&&h+l<j&&(c=!1));b.x=h}return c},getPosition:function(a,b,c,d){var e=this.axis,f=e.chart,g=d&&f.oldChartHeight||f.chartHeight;return{x:a?e.translate(b+c,null,null,d)+e.transB:e.left+e.offset+(e.opposite?(d&&f.oldChartWidth||f.chartWidth)-e.right-e.left:0),y:a?g-e.bottom+e.offset-(e.opposite?e.height:0):
g-e.translate(b+c,null,null,d)-e.transB}},getLabelPosition:function(a,b,c,d,e,f,g,h){var i=this.axis,j=i.transA,k=i.reversed,l=i.staggerLines,m=i.chart.renderer.fontMetrics(e.style.fontSize).b,p=e.rotation,a=a+e.x-(f&&d?f*j*(k?-1:1):0),b=b+e.y-(f&&!d?f*j*(k?1:-1):0);p&&i.side===2&&(b-=m-m*V(p*Ua));!u(e.y)&&!p&&(b+=m-c.getBBox().height/2);l&&(b+=g/(h||1)%l*(i.labelOffset/l));return{x:a,y:b}},getMarkPath:function(a,b,c,d,e,f){return f.crispLine(["M",a,b,"L",a+(e?0:-c),b+(e?c:0)],d)},render:function(a,
b,c){var d=this.axis,e=d.options,f=d.chart.renderer,g=d.horiz,h=this.type,i=this.label,j=this.pos,k=e.labels,l=this.gridLine,m=h?h+"Grid":"grid",p=h?h+"Tick":"tick",q=e[m+"LineWidth"],n=e[m+"LineColor"],B=e[m+"LineDashStyle"],r=e[p+"Length"],m=e[p+"Width"]||0,u=e[p+"Color"],t=e[p+"Position"],p=this.mark,s=k.step,w=!0,x=d.tickmarkOffset,E=this.getPosition(g,j,x,b),H=E.x,E=E.y,C=g&&H===d.pos+d.len||!g&&E===d.pos?-1:1,y=d.staggerLines;this.isActive=!0;if(q){j=d.getPlotLinePath(j+x,q*C,b,!0);if(l===v){l=
{stroke:n,"stroke-width":q};if(B)l.dashstyle=B;if(!h)l.zIndex=1;if(b)l.opacity=0;this.gridLine=l=q?f.path(j).attr(l).add(d.gridGroup):null}if(!b&&l&&j)l[this.isNew?"attr":"animate"]({d:j,opacity:c})}if(m&&r)t==="inside"&&(r=-r),d.opposite&&(r=-r),b=this.getMarkPath(H,E,r,m*C,g,f),p?p.animate({d:b,opacity:c}):this.mark=f.path(b).attr({stroke:u,"stroke-width":m,opacity:c}).add(d.axisGroup);if(i&&!isNaN(H))i.xy=E=this.getLabelPosition(H,E,i,g,k,x,a,s),this.isFirst&&!this.isLast&&!o(e.showFirstLabel,
1)||this.isLast&&!this.isFirst&&!o(e.showLastLabel,1)?w=!1:!y&&g&&k.overflow==="justify"&&!this.handleOverflow(a,E)&&(w=!1),s&&a%s&&(w=!1),w&&!isNaN(E.y)?(E.opacity=c,i[this.isNew?"attr":"animate"](E),this.isNew=!1):i.attr("y",-9999)},destroy:function(){Ka(this,this.axis)}};vb.prototype={render:function(){var a=this,b=a.axis,c=b.horiz,d=(b.pointRange||0)/2,e=a.options,f=e.label,g=a.label,h=e.width,i=e.to,j=e.from,k=u(j)&&u(i),l=e.value,m=e.dashStyle,p=a.svgElem,q=[],n,B=e.color,O=e.zIndex,t=e.events,
v=b.chart.renderer;b.isLog&&(j=ma(j),i=ma(i),l=ma(l));if(h){if(q=b.getPlotLinePath(l,h),d={stroke:B,"stroke-width":h},m)d.dashstyle=m}else if(k){if(j=r(j,b.min-d),i=J(i,b.max+d),q=b.getPlotBandPath(j,i,e),d={fill:B},e.borderWidth)d.stroke=e.borderColor,d["stroke-width"]=e.borderWidth}else return;if(u(O))d.zIndex=O;if(p)if(q)p.animate({d:q},null,p.onGetPath);else{if(p.hide(),p.onGetPath=function(){p.show()},g)a.label=g=g.destroy()}else if(q&&q.length&&(a.svgElem=p=v.path(q).attr(d).add(),t))for(n in e=
function(b){p.on(b,function(c){t[b].apply(a,[c])})},t)e(n);if(f&&u(f.text)&&q&&q.length&&b.width>0&&b.height>0){f=x({align:c&&k&&"center",x:c?!k&&4:10,verticalAlign:!c&&k&&"middle",y:c?k?16:10:k?6:-4,rotation:c&&!k&&90},f);if(!g)a.label=g=v.text(f.text,0,0,f.useHTML).attr({align:f.textAlign||f.align,rotation:f.rotation,zIndex:O}).css(f.style).add();b=[q[1],q[4],o(q[6],q[1])];q=[q[2],q[5],o(q[7],q[2])];c=Ja(b);k=Ja(q);g.align(f,!1,{x:c,y:k,width:ua(b)-c,height:ua(q)-k});g.show()}else g&&g.hide();return a},
destroy:function(){ga(this.axis.plotLinesAndBands,this);delete this.axis;Ka(this)}};Mb.prototype={destroy:function(){Ka(this,this.axis)},render:function(a){var b=this.options,c=b.format,c=c?Ca(c,this):b.formatter.call(this);this.label?this.label.attr({text:c,visibility:"hidden"}):this.label=this.axis.chart.renderer.text(c,0,0,b.useHTML).css(b.style).attr({align:this.textAlign,rotation:b.rotation,visibility:"hidden"}).add(a)},setOffset:function(a,b){var c=this.axis,d=c.chart,e=d.inverted,f=this.isNegative,
g=c.translate(this.percent?100:this.total,0,0,0,1),c=c.translate(0),c=M(g-c),h=d.xAxis[0].translate(this.x)+a,i=d.plotHeight,f={x:e?f?g:g-c:h,y:e?i-h-b:f?i-g-c:i-g,width:e?c:b,height:e?b:c};if(e=this.label)e.align(this.alignOptions,null,f),f=e.alignAttr,e.attr({visibility:this.options.crop===!1||d.isInsidePlot(f.x,f.y)?W?"inherit":"visible":"hidden"})}};eb.prototype={defaultOptions:{dateTimeLabelFormats:{millisecond:"%H:%M:%S.%L",second:"%H:%M:%S",minute:"%H:%M",hour:"%H:%M",day:"%e. %b",week:"%e. %b",
month:"%b \'%y",year:"%Y"},endOnTick:!1,gridLineColor:"#C0C0C0",labels:G,lineColor:"#C0D0E0",lineWidth:1,minPadding:0.01,maxPadding:0.01,minorGridLineColor:"#E0E0E0",minorGridLineWidth:1,minorTickColor:"#A0A0A0",minorTickLength:2,minorTickPosition:"outside",startOfWeek:1,startOnTick:!1,tickColor:"#C0D0E0",tickLength:5,tickmarkPlacement:"between",tickPixelInterval:100,tickPosition:"outside",tickWidth:1,title:{align:"middle",style:{color:"#4d759e",fontWeight:"bold"}},type:"linear"},defaultYAxisOptions:{endOnTick:!0,
gridLineWidth:1,tickPixelInterval:72,showLastLabel:!0,labels:{x:-8,y:3},lineWidth:0,maxPadding:0.05,minPadding:0.05,startOnTick:!0,tickWidth:0,title:{rotation:270,text:"Values"},stackLabels:{enabled:!1,formatter:function(){return Aa(this.total,-1)},style:G.style}},defaultLeftAxisOptions:{labels:{x:-8,y:null},title:{rotation:270}},defaultRightAxisOptions:{labels:{x:8,y:null},title:{rotation:90}},defaultBottomAxisOptions:{labels:{x:0,y:14},title:{rotation:0}},defaultTopAxisOptions:{labels:{x:0,y:-5},
title:{rotation:0}},init:function(a,b){var c=b.isX;this.horiz=a.inverted?!c:c;this.xOrY=(this.isXAxis=c)?"x":"y";this.opposite=b.opposite;this.side=this.horiz?this.opposite?0:2:this.opposite?1:3;this.setOptions(b);var d=this.options,e=d.type;this.labelFormatter=d.labels.formatter||this.defaultLabelFormatter;this.userOptions=b;this.minPixelPadding=0;this.chart=a;this.reversed=d.reversed;this.zoomEnabled=d.zoomEnabled!==!1;this.categories=d.categories||e==="category";this.names=[];this.isLog=e==="logarithmic";
this.isDatetimeAxis=e==="datetime";this.isLinked=u(d.linkedTo);this.tickmarkOffset=this.categories&&d.tickmarkPlacement==="between"?0.5:0;this.ticks={};this.minorTicks={};this.plotLinesAndBands=[];this.alternateBands={};this.len=0;this.minRange=this.userMinRange=d.minRange||d.maxZoom;this.range=d.range;this.offset=d.offset||0;this.stacks={};this.oldStacks={};this.stackExtremes={};this.min=this.max=null;var f,d=this.options.events;pa(this,a.axes)===-1&&(a.axes.push(this),a[c?"xAxis":"yAxis"].push(this));
this.series=this.series||[];if(a.inverted&&c&&this.reversed===v)this.reversed=!0;this.removePlotLine=this.removePlotBand=this.removePlotBandOrLine;for(f in d)K(this,f,d[f]);if(this.isLog)this.val2lin=ma,this.lin2val=fa},setOptions:function(a){this.options=x(this.defaultOptions,this.isXAxis?{}:this.defaultYAxisOptions,[this.defaultTopAxisOptions,this.defaultRightAxisOptions,this.defaultBottomAxisOptions,this.defaultLeftAxisOptions][this.side],x(L[this.isXAxis?"xAxis":"yAxis"],a))},update:function(a,
b){var c=this.chart,a=c.options[this.xOrY+"Axis"][this.options.index]=x(this.userOptions,a);this.destroy(!0);this._addedPlotLB=this.userMin=this.userMax=v;this.init(c,s(a,{events:v}));c.isDirtyBox=!0;o(b,!0)&&c.redraw()},remove:function(a){var b=this.chart,c=this.xOrY+"Axis";n(this.series,function(a){a.remove(!1)});ga(b.axes,this);ga(b[c],this);b.options[c].splice(this.options.index,1);n(b[c],function(a,b){a.options.index=b});this.destroy();b.isDirtyBox=!0;o(a,!0)&&b.redraw()},defaultLabelFormatter:function(){var a=
this.axis,b=this.value,c=a.categories,d=this.dateTimeLabelFormat,e=L.lang.numericSymbols,f=e&&e.length,g,h=a.options.labels.format,a=a.isLog?b:a.tickInterval;if(h)g=Ca(h,this);else if(c)g=b;else if(d)g=Ya(d,b);else if(f&&a>=1E3)for(;f--&&g===v;)c=Math.pow(1E3,f+1),a>=c&&e[f]!==null&&(g=Aa(b/c,-1)+e[f]);g===v&&(g=b>=1E3?Aa(b,0):Aa(b,-1));return g},getSeriesExtremes:function(){var a=this,b=a.chart;a.hasVisibleSeries=!1;a.dataMin=a.dataMax=null;a.stackExtremes={};a.buildStacks();n(a.series,function(c){if(c.visible||
!b.options.chart.ignoreHiddenSeries){var d;d=c.options.threshold;var e;a.hasVisibleSeries=!0;a.isLog&&d<=0&&(d=null);if(a.isXAxis){if(d=c.xData,d.length)a.dataMin=J(o(a.dataMin,d[0]),Ja(d)),a.dataMax=r(o(a.dataMax,d[0]),ua(d))}else{c.getExtremes();e=c.dataMax;c=c.dataMin;if(u(c)&&u(e))a.dataMin=J(o(a.dataMin,c),c),a.dataMax=r(o(a.dataMax,e),e);if(u(d))if(a.dataMin>=d)a.dataMin=d,a.ignoreMinPadding=!0;else if(a.dataMax<d)a.dataMax=d,a.ignoreMaxPadding=!0}}})},translate:function(a,b,c,d,e,f){var g=
this.len,h=1,i=0,j=d?this.oldTransA:this.transA,d=d?this.oldMin:this.min,k=this.minPixelPadding,e=(this.options.ordinal||this.isLog&&e)&&this.lin2val;if(!j)j=this.transA;c&&(h*=-1,i=g);this.reversed&&(h*=-1,i-=h*g);b?(a=a*h+i,a-=k,a=a/j+d,e&&(a=this.lin2val(a))):(e&&(a=this.val2lin(a)),f==="between"&&(f=0.5),a=h*(a-d)*j+i+h*k+(ra(f)?j*f*this.pointRange:0));return a},toPixels:function(a,b){return this.translate(a,!1,!this.horiz,null,!0)+(b?0:this.pos)},toValue:function(a,b){return this.translate(a-
(b?0:this.pos),!0,!this.horiz,null,!0)},getPlotLinePath:function(a,b,c,d){var e=this.chart,f=this.left,g=this.top,h,i,j,a=this.translate(a,null,null,c),k=c&&e.oldChartHeight||e.chartHeight,l=c&&e.oldChartWidth||e.chartWidth,m;h=this.transB;c=i=t(a+h);h=j=t(k-a-h);if(isNaN(a))m=!0;else if(this.horiz){if(h=g,j=k-this.bottom,c<f||c>f+this.width)m=!0}else if(c=f,i=l-this.right,h<g||h>g+this.height)m=!0;return m&&!d?null:e.renderer.crispLine(["M",c,h,"L",i,j],b||0)},getPlotBandPath:function(a,b){var c=
this.getPlotLinePath(b),d=this.getPlotLinePath(a);d&&c?d.push(c[4],c[5],c[1],c[2]):d=null;return d},getLinearTickPositions:function(a,b,c){for(var d,b=ia(P(b/a)*a),c=ia(wa(c/a)*a),e=[];b<=c;){e.push(b);b=ia(b+a);if(b===d)break;d=b}return e},getLogTickPositions:function(a,b,c,d){var e=this.options,f=this.len,g=[];if(!d)this._minorAutoInterval=null;if(a>=0.5)a=t(a),g=this.getLinearTickPositions(a,b,c);else if(a>=0.08)for(var f=P(b),h,i,j,k,l,e=a>0.3?[1,2,4]:a>0.15?[1,2,4,6,8]:[1,2,3,4,5,6,7,8,9];f<
c+1&&!l;f++){i=e.length;for(h=0;h<i&&!l;h++)j=ma(fa(f)*e[h]),j>b&&(!d||k<=c)&&g.push(k),k>c&&(l=!0),k=j}else if(b=fa(b),c=fa(c),a=e[d?"minorTickInterval":"tickInterval"],a=o(a==="auto"?null:a,this._minorAutoInterval,(c-b)*(e.tickPixelInterval/(d?5:1))/((d?f/this.tickPositions.length:f)||1)),a=ob(a,null,nb(a)),g=Na(this.getLinearTickPositions(a,b,c),ma),!d)this._minorAutoInterval=a/5;if(!d)this.tickInterval=a;return g},getMinorTickPositions:function(){var a=this.options,b=this.tickPositions,c=this.minorTickInterval,
d=[],e;if(this.isLog){e=b.length;for(a=1;a<e;a++)d=d.concat(this.getLogTickPositions(c,b[a-1],b[a],!0))}else if(this.isDatetimeAxis&&a.minorTickInterval==="auto")d=d.concat(Eb(Cb(c),this.min,this.max,a.startOfWeek)),d[0]<this.min&&d.shift();else for(b=this.min+(b[0]-this.min)%c;b<=this.max;b+=c)d.push(b);return d},adjustForMinRange:function(){var a=this.options,b=this.min,c=this.max,d,e=this.dataMax-this.dataMin>=this.minRange,f,g,h,i,j;if(this.isXAxis&&this.minRange===v&&!this.isLog)u(a.min)||u(a.max)?
this.minRange=null:(n(this.series,function(a){i=a.xData;for(g=j=a.xIncrement?1:i.length-1;g>0;g--)if(h=i[g]-i[g-1],f===v||h<f)f=h}),this.minRange=J(f*5,this.dataMax-this.dataMin));if(c-b<this.minRange){var k=this.minRange;d=(k-c+b)/2;d=[b-d,o(a.min,b-d)];if(e)d[2]=this.dataMin;b=ua(d);c=[b+k,o(a.max,b+k)];if(e)c[2]=this.dataMax;c=Ja(c);c-b<k&&(d[0]=c-k,d[1]=o(a.min,c-k),b=ua(d))}this.min=b;this.max=c},setAxisTranslation:function(a){var b=this.max-this.min,c=0,d,e=0,f=0,g=this.linkedParent,h=this.transA;
if(this.isXAxis)g?(e=g.minPointOffset,f=g.pointRangePadding):n(this.series,function(a){var g=a.pointRange,h=a.options.pointPlacement,l=a.closestPointRange;g>b&&(g=0);c=r(c,g);e=r(e,ea(h)?0:g/2);f=r(f,h==="on"?0:g);!a.noSharedTooltip&&u(l)&&(d=u(d)?J(d,l):l)}),g=this.ordinalSlope&&d?this.ordinalSlope/d:1,this.minPointOffset=e*=g,this.pointRangePadding=f*=g,this.pointRange=J(c,b),this.closestPointRange=d;if(a)this.oldTransA=h;this.translationSlope=this.transA=h=this.len/(b+f||1);this.transB=this.horiz?
this.left:this.bottom;this.minPixelPadding=h*e},setTickPositions:function(a){var b=this,c=b.chart,d=b.options,e=b.isLog,f=b.isDatetimeAxis,g=b.isXAxis,h=b.isLinked,i=b.options.tickPositioner,j=d.maxPadding,k=d.minPadding,l=d.tickInterval,m=d.minTickInterval,p=d.tickPixelInterval,q,aa=b.categories;h?(b.linkedParent=c[g?"xAxis":"yAxis"][d.linkedTo],c=b.linkedParent.getExtremes(),b.min=o(c.min,c.dataMin),b.max=o(c.max,c.dataMax),d.type!==b.linkedParent.options.type&&ka(11,1)):(b.min=o(b.userMin,d.min,
b.dataMin),b.max=o(b.userMax,d.max,b.dataMax));if(e)!a&&J(b.min,o(b.dataMin,b.min))<=0&&ka(10,1),b.min=ia(ma(b.min)),b.max=ia(ma(b.max));if(b.range&&(b.userMin=b.min=r(b.min,b.max-b.range),b.userMax=b.max,a))b.range=null;b.beforePadding&&b.beforePadding();b.adjustForMinRange();if(!aa&&!b.usePercentage&&!h&&u(b.min)&&u(b.max)&&(c=b.max-b.min)){if(!u(d.min)&&!u(b.userMin)&&k&&(b.dataMin<0||!b.ignoreMinPadding))b.min-=c*k;if(!u(d.max)&&!u(b.userMax)&&j&&(b.dataMax>0||!b.ignoreMaxPadding))b.max+=c*j}b.min===
b.max||b.min===void 0||b.max===void 0?b.tickInterval=1:h&&!l&&p===b.linkedParent.options.tickPixelInterval?b.tickInterval=b.linkedParent.tickInterval:(b.tickInterval=o(l,aa?1:(b.max-b.min)*p/r(b.len,p)),!u(l)&&b.len<p&&!this.isRadial&&(q=!0,b.tickInterval/=4));g&&!a&&n(b.series,function(a){a.processData(b.min!==b.oldMin||b.max!==b.oldMax)});b.setAxisTranslation(!0);b.beforeSetTickPositions&&b.beforeSetTickPositions();if(b.postProcessTickInterval)b.tickInterval=b.postProcessTickInterval(b.tickInterval);
if(b.pointRange)b.tickInterval=r(b.pointRange,b.tickInterval);if(!l&&b.tickInterval<m)b.tickInterval=m;if(!f&&!e&&!l)b.tickInterval=ob(b.tickInterval,null,nb(b.tickInterval),d);b.minorTickInterval=d.minorTickInterval==="auto"&&b.tickInterval?b.tickInterval/5:d.minorTickInterval;b.tickPositions=a=d.tickPositions?[].concat(d.tickPositions):i&&i.apply(b,[b.min,b.max]);if(!a)!b.ordinalPositions&&(b.max-b.min)/b.tickInterval>r(2*b.len,200)&&ka(19,!0),a=f?(b.getNonLinearTimeTicks||Eb)(Cb(b.tickInterval,
d.units),b.min,b.max,d.startOfWeek,b.ordinalPositions,b.closestPointRange,!0):e?b.getLogTickPositions(b.tickInterval,b.min,b.max):b.getLinearTickPositions(b.tickInterval,b.min,b.max),q&&a.splice(1,a.length-2),b.tickPositions=a;if(!h)e=a[0],f=a[a.length-1],h=b.minPointOffset||0,d.startOnTick?b.min=e:b.min-h>e&&a.shift(),d.endOnTick?b.max=f:b.max+h<f&&a.pop(),a.length===1&&(b.min-=0.001,b.max+=0.001)},setMaxTicks:function(){var a=this.chart,b=a.maxTicks||{},c=this.tickPositions,d=this._maxTicksKey=
[this.xOrY,this.pos,this.len].join("-");if(!this.isLinked&&!this.isDatetimeAxis&&c&&c.length>(b[d]||0)&&this.options.alignTicks!==!1)b[d]=c.length;a.maxTicks=b},adjustTickAmount:function(){var a=this._maxTicksKey,b=this.tickPositions,c=this.chart.maxTicks;if(c&&c[a]&&!this.isDatetimeAxis&&!this.categories&&!this.isLinked&&this.options.alignTicks!==!1){var d=this.tickAmount,e=b.length;this.tickAmount=a=c[a];if(e<a){for(;b.length<a;)b.push(ia(b[b.length-1]+this.tickInterval));this.transA*=(e-1)/(a-
1);this.max=b[b.length-1]}if(u(d)&&a!==d)this.isDirty=!0}},setScale:function(){var a=this.stacks,b,c,d,e;this.oldMin=this.min;this.oldMax=this.max;this.oldAxisLength=this.len;this.setAxisSize();e=this.len!==this.oldAxisLength;n(this.series,function(a){if(a.isDirtyData||a.isDirty||a.xAxis.isDirty)d=!0});if(e||d||this.isLinked||this.forceRedraw||this.userMin!==this.oldUserMin||this.userMax!==this.oldUserMax){if(!this.isXAxis)for(b in a)for(c in a[b])a[b][c].total=null,a[b][c].cum=0;this.forceRedraw=
!1;this.getSeriesExtremes();this.setTickPositions();this.oldUserMin=this.userMin;this.oldUserMax=this.userMax;if(!this.isDirty)this.isDirty=e||this.min!==this.oldMin||this.max!==this.oldMax}else if(!this.isXAxis){if(this.oldStacks)a=this.stacks=this.oldStacks;for(b in a)for(c in a[b])a[b][c].cum=a[b][c].total}this.setMaxTicks()},setExtremes:function(a,b,c,d,e){var f=this,g=f.chart,c=o(c,!0),e=s(e,{min:a,max:b});A(f,"setExtremes",e,function(){f.userMin=a;f.userMax=b;f.eventArgs=e;f.isDirtyExtremes=
!0;c&&g.redraw(d)})},zoom:function(a,b){this.allowZoomOutside||(u(this.dataMin)&&a<=this.dataMin&&(a=v),u(this.dataMax)&&b>=this.dataMax&&(b=v));this.displayBtn=a!==v||b!==v;this.setExtremes(a,b,!1,v,{trigger:"zoom"});return!0},setAxisSize:function(){var a=this.chart,b=this.options,c=b.offsetLeft||0,d=b.offsetRight||0,e=this.horiz,f,g;this.left=g=o(b.left,a.plotLeft+c);this.top=f=o(b.top,a.plotTop);this.width=c=o(b.width,a.plotWidth-c+d);this.height=b=o(b.height,a.plotHeight);this.bottom=a.chartHeight-
b-f;this.right=a.chartWidth-c-g;this.len=r(e?c:b,0);this.pos=e?g:f},getExtremes:function(){var a=this.isLog;return{min:a?ia(fa(this.min)):this.min,max:a?ia(fa(this.max)):this.max,dataMin:this.dataMin,dataMax:this.dataMax,userMin:this.userMin,userMax:this.userMax}},getThreshold:function(a){var b=this.isLog,c=b?fa(this.min):this.min,b=b?fa(this.max):this.max;c>a||a===null?a=c:b<a&&(a=b);return this.translate(a,0,1,0,1)},addPlotBand:function(a){this.addPlotBandOrLine(a,"plotBands")},addPlotLine:function(a){this.addPlotBandOrLine(a,
"plotLines")},addPlotBandOrLine:function(a,b){var c=(new vb(this,a)).render(),d=this.userOptions;c&&(b&&(d[b]=d[b]||[],d[b].push(a)),this.plotLinesAndBands.push(c));return c},autoLabelAlign:function(a){a=(o(a,0)-this.side*90+720)%360;return a>15&&a<165?"right":a>195&&a<345?"left":"center"},getOffset:function(){var a=this,b=a.chart,c=b.renderer,d=a.options,e=a.tickPositions,f=a.ticks,g=a.horiz,h=a.side,i=b.inverted?[1,0,3,2][h]:h,j,k=0,l,m=0,p=d.title,q=d.labels,aa=0,B=b.axisOffset,t=b.clipOffset,
s=[-1,1,1,-1][h],w,x=1,y=o(q.maxStaggerLines,5),Ha,E,H,C;a.hasData=j=a.hasVisibleSeries||u(a.min)&&u(a.max)&&!!e;a.showAxis=b=j||o(d.showEmpty,!0);a.staggerLines=a.horiz&&q.staggerLines;if(!a.axisGroup)a.gridGroup=c.g("grid").attr({zIndex:d.gridZIndex||1}).add(),a.axisGroup=c.g("axis").attr({zIndex:d.zIndex||2}).add(),a.labelGroup=c.g("axis-labels").attr({zIndex:q.zIndex||7}).add();if(j||a.isLinked){a.labelAlign=o(q.align||a.autoLabelAlign(q.rotation));n(e,function(b){f[b]?f[b].addLabel():f[b]=new Ma(a,
b)});if(a.horiz&&!a.staggerLines&&y&&!q.rotation){for(w=a.reversed?[].concat(e).reverse():e;x<y;){j=[];Ha=!1;for(q=0;q<w.length;q++)E=w[q],H=(H=f[E].label&&f[E].label.getBBox())?H.width:0,C=q%x,H&&(E=a.translate(E),j[C]!==v&&E<j[C]&&(Ha=!0),j[C]=E+H);if(Ha)x++;else break}if(x>1)a.staggerLines=x}n(e,function(b){if(h===0||h===2||{1:"left",3:"right"}[h]===a.labelAlign)aa=r(f[b].getLabelSize(),aa)});if(a.staggerLines)aa*=a.staggerLines,a.labelOffset=aa}else for(w in f)f[w].destroy(),delete f[w];if(p&&
p.text&&p.enabled!==!1){if(!a.axisTitle)a.axisTitle=c.text(p.text,0,0,p.useHTML).attr({zIndex:7,rotation:p.rotation||0,align:p.textAlign||{low:"left",middle:"center",high:"right"}[p.align]}).css(p.style).add(a.axisGroup),a.axisTitle.isNew=!0;if(b)k=a.axisTitle.getBBox()[g?"height":"width"],m=o(p.margin,g?5:10),l=p.offset;a.axisTitle[b?"show":"hide"]()}a.offset=s*o(d.offset,B[h]);a.axisTitleMargin=o(l,aa+m+(h!==2&&aa&&s*d.labels[g?"y":"x"]));B[h]=r(B[h],a.axisTitleMargin+k+s*a.offset);t[i]=r(t[i],
P(d.lineWidth/2)*2)},getLinePath:function(a){var b=this.chart,c=this.opposite,d=this.offset,e=this.horiz,f=this.left+(c?this.width:0)+d,d=b.chartHeight-this.bottom-(c?this.height:0)+d;c&&(a*=-1);return b.renderer.crispLine(["M",e?this.left:f,e?d:this.top,"L",e?b.chartWidth-this.right:f,e?d:b.chartHeight-this.bottom],a)},getTitlePosition:function(){var a=this.horiz,b=this.left,c=this.top,d=this.len,e=this.options.title,f=a?b:c,g=this.opposite,h=this.offset,i=y(e.style.fontSize||12),d={low:f+(a?0:d),
middle:f+d/2,high:f+(a?d:0)}[e.align],b=(a?c+this.height:b)+(a?1:-1)*(g?-1:1)*this.axisTitleMargin+(this.side===2?i:0);return{x:a?d:b+(g?this.width:0)+h+(e.x||0),y:a?b-(g?this.height:0)+h:d+(e.y||0)}},render:function(){var a=this,b=a.chart,c=b.renderer,d=a.options,e=a.isLog,f=a.isLinked,g=a.tickPositions,h=a.axisTitle,i=a.stacks,j=a.ticks,k=a.minorTicks,l=a.alternateBands,m=d.stackLabels,p=d.alternateGridColor,q=a.tickmarkOffset,o=d.lineWidth,B,r=b.hasRendered&&u(a.oldMin)&&!isNaN(a.oldMin);B=a.hasData;
var t=a.showAxis,s,w;n([j,k,l],function(a){for(var b in a)a[b].isActive=!1});if(B||f)if(a.minorTickInterval&&!a.categories&&n(a.getMinorTickPositions(),function(b){k[b]||(k[b]=new Ma(a,b,"minor"));r&&k[b].isNew&&k[b].render(null,!0);k[b].render(null,!1,1)}),g.length&&(n(g.slice(1).concat([g[0]]),function(b,c){c=c===g.length-1?0:c+1;if(!f||b>=a.min&&b<=a.max)j[b]||(j[b]=new Ma(a,b)),r&&j[b].isNew&&j[b].render(c,!0),j[b].render(c,!1,1)}),q&&a.min===0&&(j[-1]||(j[-1]=new Ma(a,-1,null,!0)),j[-1].render(-1))),
p&&n(g,function(b,c){if(c%2===0&&b<a.max)l[b]||(l[b]=new vb(a)),s=b+q,w=g[c+1]!==v?g[c+1]+q:a.max,l[b].options={from:e?fa(s):s,to:e?fa(w):w,color:p},l[b].render(),l[b].isActive=!0}),!a._addedPlotLB)n((d.plotLines||[]).concat(d.plotBands||[]),function(b){a.addPlotBandOrLine(b)}),a._addedPlotLB=!0;n([j,k,l],function(a){var c,d,e=[],f=Fa?Fa.duration||500:0,g=function(){for(d=e.length;d--;)a[e[d]]&&!a[e[d]].isActive&&(a[e[d]].destroy(),delete a[e[d]])};for(c in a)if(!a[c].isActive)a[c].render(c,!1,0),
a[c].isActive=!1,e.push(c);a===l||!b.hasRendered||!f?g():f&&setTimeout(g,f)});if(o)B=a.getLinePath(o),a.axisLine?a.axisLine.animate({d:B}):a.axisLine=c.path(B).attr({stroke:d.lineColor,"stroke-width":o,zIndex:7}).add(a.axisGroup),a.axisLine[t?"show":"hide"]();if(h&&t)h[h.isNew?"attr":"animate"](a.getTitlePosition()),h.isNew=!1;if(m&&m.enabled){var x,y,d=a.stackTotalGroup;if(!d)a.stackTotalGroup=d=c.g("stack-labels").attr({visibility:"visible",zIndex:6}).add();d.translate(b.plotLeft,b.plotTop);for(x in i)for(y in c=
i[x],c)c[y].render(d)}a.isDirty=!1},removePlotBandOrLine:function(a){for(var b=this.plotLinesAndBands,c=this.options,d=this.userOptions,e=b.length;e--;)b[e].id===a&&b[e].destroy();n([c.plotLines||[],d.plotLines||[],c.plotBands||[],d.plotBands||[]],function(b){for(e=b.length;e--;)b[e].id===a&&ga(b,b[e])})},setTitle:function(a,b){this.update({title:a},b)},redraw:function(){var a=this.chart.pointer;a.reset&&a.reset(!0);this.render();n(this.plotLinesAndBands,function(a){a.render()});n(this.series,function(a){a.isDirty=
!0})},buildStacks:function(){var a=this.series,b=a.length;if(!this.isXAxis){for(;b--;)a[b].setStackedPoints();if(this.usePercentage)for(b=0;b<a.length;b++)a[b].setPercentStacks()}},setCategories:function(a,b){this.update({categories:a},b)},destroy:function(a){var b=this,c=b.stacks,d,e=b.plotLinesAndBands;a||$(b);for(d in c)Ka(c[d]),c[d]=null;n([b.ticks,b.minorTicks,b.alternateBands],function(a){Ka(a)});for(a=e.length;a--;)e[a].destroy();n("stackTotalGroup,axisLine,axisGroup,gridGroup,labelGroup,axisTitle".split(","),
function(a){b[a]&&(b[a]=b[a].destroy())})}};wb.prototype={init:function(a,b){var c=b.borderWidth,d=b.style,e=y(d.padding);this.chart=a;this.options=b;this.crosshairs=[];this.now={x:0,y:0};this.isHidden=!0;this.label=a.renderer.label("",0,0,b.shape,null,null,b.useHTML,null,"tooltip").attr({padding:e,fill:b.backgroundColor,"stroke-width":c,r:b.borderRadius,zIndex:8}).css(d).css({padding:0}).add().attr({y:-999});ca||this.label.shadow(b.shadow);this.shared=b.shared},destroy:function(){n(this.crosshairs,
function(a){a&&a.destroy()});if(this.label)this.label=this.label.destroy();clearTimeout(this.hideTimer);clearTimeout(this.tooltipTimeout)},move:function(a,b,c,d){var e=this,f=e.now,g=e.options.animation!==!1&&!e.isHidden;s(f,{x:g?(2*f.x+a)/3:a,y:g?(f.y+b)/2:b,anchorX:g?(2*f.anchorX+c)/3:c,anchorY:g?(f.anchorY+d)/2:d});e.label.attr(f);if(g&&(M(a-f.x)>1||M(b-f.y)>1))clearTimeout(this.tooltipTimeout),this.tooltipTimeout=setTimeout(function(){e&&e.move(a,b,c,d)},32)},hide:function(){var a=this,b;clearTimeout(this.hideTimer);
if(!this.isHidden)b=this.chart.hoverPoints,this.hideTimer=setTimeout(function(){a.label.fadeOut();a.isHidden=!0},o(this.options.hideDelay,500)),b&&n(b,function(a){a.setState()}),this.chart.hoverPoints=null},hideCrosshairs:function(){n(this.crosshairs,function(a){a&&a.hide()})},getAnchor:function(a,b){var c,d=this.chart,e=d.inverted,f=d.plotTop,g=0,h=0,i,a=ja(a);c=a[0].tooltipPos;this.followPointer&&b&&(b.chartX===v&&(b=d.pointer.normalize(b)),c=[b.chartX-d.plotLeft,b.chartY-f]);c||(n(a,function(a){i=
a.series.yAxis;g+=a.plotX;h+=(a.plotLow?(a.plotLow+a.plotHigh)/2:a.plotY)+(!e&&i?i.top-f:0)}),g/=a.length,h/=a.length,c=[e?d.plotWidth-h:g,this.shared&&!e&&a.length>1&&b?b.chartY-f:e?d.plotHeight-g:h]);return Na(c,t)},getPosition:function(a,b,c){var d=this.chart,e=d.plotLeft,f=d.plotTop,g=d.plotWidth,h=d.plotHeight,i=o(this.options.distance,12),j=c.plotX,c=c.plotY,d=j+e+(d.inverted?i:-a-i),k=c-b+f+15,l;d<7&&(d=e+r(j,0)+i);d+a>e+g&&(d-=d+a-(e+g),k=c-b+f-i,l=!0);k<f+5&&(k=f+5,l&&c>=k&&c<=k+b&&(k=c+
f+i));k+b>f+h&&(k=r(f,f+h-b-i));return{x:d,y:k}},defaultFormatter:function(a){var b=this.points||ja(this),c=b[0].series,d;d=[c.tooltipHeaderFormatter(b[0])];n(b,function(a){c=a.series;d.push(c.tooltipFormatter&&c.tooltipFormatter(a)||a.point.tooltipFormatter(c.tooltipOptions.pointFormat))});d.push(a.options.footerFormat||"");return d.join("")},refresh:function(a,b){var c=this.chart,d=this.label,e=this.options,f,g,h={},i,j=[];i=e.formatter||this.defaultFormatter;var h=c.hoverPoints,k,l=e.crosshairs,
m=this.shared;clearTimeout(this.hideTimer);this.followPointer=ja(a)[0].series.tooltipOptions.followPointer;g=this.getAnchor(a,b);f=g[0];g=g[1];m&&(!a.series||!a.series.noSharedTooltip)?(c.hoverPoints=a,h&&n(h,function(a){a.setState()}),n(a,function(a){a.setState("hover");j.push(a.getLabelConfig())}),h={x:a[0].category,y:a[0].y},h.points=j,a=a[0]):h=a.getLabelConfig();i=i.call(h,this);h=a.series;i===!1?this.hide():(this.isHidden&&(Wa(d),d.attr("opacity",1).show()),d.attr({text:i}),k=e.borderColor||
a.color||h.color||"#606060",d.attr({stroke:k}),this.updatePosition({plotX:f,plotY:g}),this.isHidden=!1);if(l){l=ja(l);for(d=l.length;d--;)if(m=a.series,e=m[d?"yAxis":"xAxis"],l[d]&&e)if(h=d?o(a.stackY,a.y):a.x,e.isLog&&(h=ma(h)),d===1&&m.modifyValue&&(h=m.modifyValue(h)),e=e.getPlotLinePath(h,1),this.crosshairs[d])this.crosshairs[d].attr({d:e,visibility:"visible"});else{h={"stroke-width":l[d].width||1,stroke:l[d].color||"#C0C0C0",zIndex:l[d].zIndex||2};if(l[d].dashStyle)h.dashstyle=l[d].dashStyle;
this.crosshairs[d]=c.renderer.path(e).attr(h).add()}}A(c,"tooltipRefresh",{text:i,x:f+c.plotLeft,y:g+c.plotTop,borderColor:k})},updatePosition:function(a){var b=this.chart,c=this.label,c=(this.options.positioner||this.getPosition).call(this,c.width,c.height,a);this.move(t(c.x),t(c.y),a.plotX+b.plotLeft,a.plotY+b.plotTop)}};xb.prototype={init:function(a,b){var c=b.chart,d=c.events,e=ca?"":c.zoomType,c=a.inverted,f;this.options=b;this.chart=a;this.zoomX=f=/x/.test(e);this.zoomY=e=/y/.test(e);this.zoomHor=
f&&!c||e&&c;this.zoomVert=e&&!c||f&&c;this.runChartClick=d&&!!d.click;this.pinchDown=[];this.lastValidTouch={};if(b.tooltip.enabled)a.tooltip=new wb(a,b.tooltip);this.setDOMEvents()},normalize:function(a,b){var c,d,a=a||N.event;if(!a.target)a.target=a.srcElement;a=Xb(a);d=a.touches?a.touches.item(0):a;if(!b)this.chartPosition=b=Wb(this.chart.container);d.pageX===v?(c=r(a.x,a.clientX-b.left),d=a.y):(c=d.pageX-b.left,d=d.pageY-b.top);return s(a,{chartX:t(c),chartY:t(d)})},getCoordinates:function(a){var b=
{xAxis:[],yAxis:[]};n(this.chart.axes,function(c){b[c.isXAxis?"xAxis":"yAxis"].push({axis:c,value:c.toValue(a[c.horiz?"chartX":"chartY"])})});return b},getIndex:function(a){var b=this.chart;return b.inverted?b.plotHeight+b.plotTop-a.chartY:a.chartX-b.plotLeft},runPointActions:function(a){var b=this.chart,c=b.series,d=b.tooltip,e,f=b.hoverPoint,g=b.hoverSeries,h,i,j=b.chartWidth,k=this.getIndex(a);if(d&&this.options.tooltip.shared&&(!g||!g.noSharedTooltip)){e=[];h=c.length;for(i=0;i<h;i++)if(c[i].visible&&
c[i].options.enableMouseTracking!==!1&&!c[i].noSharedTooltip&&c[i].tooltipPoints.length&&(b=c[i].tooltipPoints[k])&&b.series)b._dist=M(k-b.clientX),j=J(j,b._dist),e.push(b);for(h=e.length;h--;)e[h]._dist>j&&e.splice(h,1);if(e.length&&e[0].clientX!==this.hoverX)d.refresh(e,a),this.hoverX=e[0].clientX}if(g&&g.tracker){if((b=g.tooltipPoints[k])&&b!==f)b.onMouseOver(a)}else d&&d.followPointer&&!d.isHidden&&(a=d.getAnchor([{}],a),d.updatePosition({plotX:a[0],plotY:a[1]}))},reset:function(a){var b=this.chart,
c=b.hoverSeries,d=b.hoverPoint,e=b.tooltip,b=e&&e.shared?b.hoverPoints:d;(a=a&&e&&b)&&ja(b)[0].plotX===v&&(a=!1);if(a)e.refresh(b);else{if(d)d.onMouseOut();if(c)c.onMouseOut();e&&(e.hide(),e.hideCrosshairs());this.hoverX=null}},scaleGroups:function(a,b){var c=this.chart,d;n(c.series,function(e){d=a||e.getPlotBox();e.xAxis&&e.xAxis.zoomEnabled&&(e.group.attr(d),e.markerGroup&&(e.markerGroup.attr(d),e.markerGroup.clip(b?c.clipRect:null)),e.dataLabelsGroup&&e.dataLabelsGroup.attr(d))});c.clipRect.attr(b||
c.clipBox)},pinchTranslate:function(a,b,c,d,e,f,g,h){a&&this.pinchTranslateDirection(!0,c,d,e,f,g,h);b&&this.pinchTranslateDirection(!1,c,d,e,f,g,h)},pinchTranslateDirection:function(a,b,c,d,e,f,g,h){var i=this.chart,j=a?"x":"y",k=a?"X":"Y",l="chart"+k,m=a?"width":"height",p=i["plot"+(a?"Left":"Top")],q,o,n=h||1,r=i.inverted,t=i.bounds[a?"h":"v"],u=b.length===1,s=b[0][l],v=c[0][l],w=!u&&b[1][l],x=!u&&c[1][l],y,c=function(){!u&&M(s-w)>20&&(n=h||M(v-x)/M(s-w));o=(p-v)/n+s;q=i["plot"+(a?"Width":"Height")]/
n};c();b=o;b<t.min?(b=t.min,y=!0):b+q>t.max&&(b=t.max-q,y=!0);y?(v-=0.8*(v-g[j][0]),u||(x-=0.8*(x-g[j][1])),c()):g[j]=[v,x];r||(f[j]=o-p,f[m]=q);f=r?1/n:n;e[m]=q;e[j]=b;d[r?a?"scaleY":"scaleX":"scale"+k]=n;d["translate"+k]=f*p+(v-f*s)},pinch:function(a){var b=this,c=b.chart,d=b.pinchDown,e=c.tooltip&&c.tooltip.options.followTouchMove,f=a.touches,g=f.length,h=b.lastValidTouch,i=b.zoomHor||b.pinchHor,j=b.zoomVert||b.pinchVert,k=i||j,l=b.selectionMarker,m={},p=g===1&&(b.inClass(a.target,"highcharts-tracker")&&
c.runTrackerClick||c.runChartClick),q={};(k||e)&&!p&&a.preventDefault();Na(f,function(a){return b.normalize(a)});if(a.type==="touchstart")n(f,function(a,b){d[b]={chartX:a.chartX,chartY:a.chartY}}),h.x=[d[0].chartX,d[1]&&d[1].chartX],h.y=[d[0].chartY,d[1]&&d[1].chartY],n(c.axes,function(a){if(a.zoomEnabled){var b=c.bounds[a.horiz?"h":"v"],d=a.minPixelPadding,e=a.toPixels(a.dataMin),f=a.toPixels(a.dataMax),g=J(e,f),e=r(e,f);b.min=J(a.pos,g-d);b.max=r(a.pos+a.len,e+d)}});else if(d.length){if(!l)b.selectionMarker=
l=s({destroy:oa},c.plotBox);b.pinchTranslate(i,j,d,f,m,l,q,h);b.hasPinched=k;b.scaleGroups(m,q);!k&&e&&g===1&&this.runPointActions(b.normalize(a))}},dragStart:function(a){var b=this.chart;b.mouseIsDown=a.type;b.cancelClick=!1;b.mouseDownX=this.mouseDownX=a.chartX;b.mouseDownY=this.mouseDownY=a.chartY},drag:function(a){var b=this.chart,c=b.options.chart,d=a.chartX,e=a.chartY,f=this.zoomHor,g=this.zoomVert,h=b.plotLeft,i=b.plotTop,j=b.plotWidth,k=b.plotHeight,l,m=this.mouseDownX,p=this.mouseDownY;d<
h?d=h:d>h+j&&(d=h+j);e<i?e=i:e>i+k&&(e=i+k);this.hasDragged=Math.sqrt(Math.pow(m-d,2)+Math.pow(p-e,2));if(this.hasDragged>10){l=b.isInsidePlot(m-h,p-i);if(b.hasCartesianSeries&&(this.zoomX||this.zoomY)&&l&&!this.selectionMarker)this.selectionMarker=b.renderer.rect(h,i,f?1:j,g?1:k,0).attr({fill:c.selectionMarkerFill||"rgba(69,114,167,0.25)",zIndex:7}).add();this.selectionMarker&&f&&(d-=m,this.selectionMarker.attr({width:M(d),x:(d>0?0:d)+m}));this.selectionMarker&&g&&(d=e-p,this.selectionMarker.attr({height:M(d),
y:(d>0?0:d)+p}));l&&!this.selectionMarker&&c.panning&&b.pan(a,c.panning)}},drop:function(a){var b=this.chart,c=this.hasPinched;if(this.selectionMarker){var d={xAxis:[],yAxis:[],originalEvent:a.originalEvent||a},e=this.selectionMarker,f=e.x,g=e.y,h;if(this.hasDragged||c)n(b.axes,function(a){if(a.zoomEnabled){var b=a.horiz,c=a.toValue(b?f:g),b=a.toValue(b?f+e.width:g+e.height);!isNaN(c)&&!isNaN(b)&&(d[a.xOrY+"Axis"].push({axis:a,min:J(c,b),max:r(c,b)}),h=!0)}}),h&&A(b,"selection",d,function(a){b.zoom(s(a,
c?{animation:!1}:null))});this.selectionMarker=this.selectionMarker.destroy();c&&this.scaleGroups()}if(b)I(b.container,{cursor:b._cursor}),b.cancelClick=this.hasDragged>10,b.mouseIsDown=this.hasDragged=this.hasPinched=!1,this.pinchDown=[]},onContainerMouseDown:function(a){a=this.normalize(a);a.preventDefault&&a.preventDefault();this.dragStart(a)},onDocumentMouseUp:function(a){this.drop(a)},onDocumentMouseMove:function(a){var b=this.chart,c=this.chartPosition,d=b.hoverSeries,a=this.normalize(a,c);
c&&d&&!this.inClass(a.target,"highcharts-tracker")&&!b.isInsidePlot(a.chartX-b.plotLeft,a.chartY-b.plotTop)&&this.reset()},onContainerMouseLeave:function(){this.reset();this.chartPosition=null},onContainerMouseMove:function(a){var b=this.chart,a=this.normalize(a);a.returnValue=!1;b.mouseIsDown==="mousedown"&&this.drag(a);(this.inClass(a.target,"highcharts-tracker")||b.isInsidePlot(a.chartX-b.plotLeft,a.chartY-b.plotTop))&&!b.openMenu&&this.runPointActions(a)},inClass:function(a,b){for(var c;a;){if(c=
w(a,"class"))if(c.indexOf(b)!==-1)return!0;else if(c.indexOf("highcharts-container")!==-1)return!1;a=a.parentNode}},onTrackerMouseOut:function(a){var b=this.chart.hoverSeries;if(b&&!b.options.stickyTracking&&!this.inClass(a.toElement||a.relatedTarget,"highcharts-tooltip"))b.onMouseOut()},onContainerClick:function(a){var b=this.chart,c=b.hoverPoint,d=b.plotLeft,e=b.plotTop,f=b.inverted,g,h,i,a=this.normalize(a);a.cancelBubble=!0;if(!b.cancelClick)c&&this.inClass(a.target,"highcharts-tracker")?(g=this.chartPosition,
h=c.plotX,i=c.plotY,s(c,{pageX:g.left+d+(f?b.plotWidth-i:h),pageY:g.top+e+(f?b.plotHeight-h:i)}),A(c.series,"click",s(a,{point:c})),b.hoverPoint&&c.firePointEvent("click",a)):(s(a,this.getCoordinates(a)),b.isInsidePlot(a.chartX-d,a.chartY-e)&&A(b,"click",a))},onContainerTouchStart:function(a){var b=this.chart;a.touches.length===1?(a=this.normalize(a),b.isInsidePlot(a.chartX-b.plotLeft,a.chartY-b.plotTop)?(this.runPointActions(a),this.pinch(a)):this.reset()):a.touches.length===2&&this.pinch(a)},onContainerTouchMove:function(a){(a.touches.length===
1||a.touches.length===2)&&this.pinch(a)},onDocumentTouchEnd:function(a){this.drop(a)},setDOMEvents:function(){var a=this,b=a.chart.container,c;this._events=c=[[b,"onmousedown","onContainerMouseDown"],[b,"onmousemove","onContainerMouseMove"],[b,"onclick","onContainerClick"],[b,"mouseleave","onContainerMouseLeave"],[z,"mousemove","onDocumentMouseMove"],[z,"mouseup","onDocumentMouseUp"]];jb&&c.push([b,"ontouchstart","onContainerTouchStart"],[b,"ontouchmove","onContainerTouchMove"],[z,"touchend","onDocumentTouchEnd"]);
n(c,function(b){a["_"+b[2]]=function(c){a[b[2]](c)};b[1].indexOf("on")===0?b[0][b[1]]=a["_"+b[2]]:K(b[0],b[1],a["_"+b[2]])})},destroy:function(){var a=this;n(a._events,function(b){b[1].indexOf("on")===0?b[0][b[1]]=null:$(b[0],b[1],a["_"+b[2]])});delete a._events;clearInterval(a.tooltipTimeout)}};fb.prototype={init:function(a,b){var c=this,d=b.itemStyle,e=o(b.padding,8),f=b.itemMarginTop||0;this.options=b;if(b.enabled)c.baseline=y(d.fontSize)+3+f,c.itemStyle=d,c.itemHiddenStyle=x(d,b.itemHiddenStyle),
c.itemMarginTop=f,c.padding=e,c.initialItemX=e,c.initialItemY=e-5,c.maxItemWidth=0,c.chart=a,c.itemHeight=0,c.lastLineHeight=0,c.render(),K(c.chart,"endResize",function(){c.positionCheckboxes()})},colorizeItem:function(a,b){var c=this.options,d=a.legendItem,e=a.legendLine,f=a.legendSymbol,g=this.itemHiddenStyle.color,c=b?c.itemStyle.color:g,h=b?a.color:g,g=a.options&&a.options.marker,i={stroke:h,fill:h},j;d&&d.css({fill:c,color:c});e&&e.attr({stroke:h});if(f){if(g&&f.isMarker)for(j in g=a.convertAttribs(g),
g)d=g[j],d!==v&&(i[j]=d);f.attr(i)}},positionItem:function(a){var b=this.options,c=b.symbolPadding,b=!b.rtl,d=a._legendItemPos,e=d[0],d=d[1],f=a.checkbox;a.legendGroup&&a.legendGroup.translate(b?e:this.legendWidth-e-2*c-4,d);if(f)f.x=e,f.y=d},destroyItem:function(a){var b=a.checkbox;n(["legendItem","legendLine","legendSymbol","legendGroup"],function(b){a[b]&&(a[b]=a[b].destroy())});b&&Ta(a.checkbox)},destroy:function(){var a=this.group,b=this.box;if(b)this.box=b.destroy();if(a)this.group=a.destroy()},
positionCheckboxes:function(a){var b=this.group.alignAttr,c,d=this.clipHeight||this.legendHeight;if(b)c=b.translateY,n(this.allItems,function(e){var f=e.checkbox,g;f&&(g=c+f.y+(a||0)+3,I(f,{left:b.translateX+e.legendItemWidth+f.x-20+"px",top:g+"px",display:g>c-6&&g<c+d-6?"":S}))})},renderTitle:function(){var a=this.padding,b=this.options.title,c=0;if(b.text){if(!this.title)this.title=this.chart.renderer.label(b.text,a-3,a-4,null,null,null,null,null,"legend-title").attr({zIndex:1}).css(b.style).add(this.group);
a=this.title.getBBox();c=a.height;this.offsetWidth=a.width;this.contentGroup.attr({translateY:c})}this.titleHeight=c},renderItem:function(a){var C;var b=this,c=b.chart,d=c.renderer,e=b.options,f=e.layout==="horizontal",g=e.symbolWidth,h=e.symbolPadding,i=b.itemStyle,j=b.itemHiddenStyle,k=b.padding,l=f?o(e.itemDistance,8):0,m=!e.rtl,p=e.width,q=e.itemMarginBottom||0,n=b.itemMarginTop,B=b.initialItemX,t=a.legendItem,u=a.series||a,s=u.options,v=s.showCheckbox,w=e.useHTML;if(!t&&(a.legendGroup=d.g("legend-item").attr({zIndex:1}).add(b.scrollGroup),
u.drawLegendSymbol(b,a),a.legendItem=t=d.text(e.labelFormat?Ca(e.labelFormat,a):e.labelFormatter.call(a),m?g+h:-h,b.baseline,w).css(x(a.visible?i:j)).attr({align:m?"left":"right",zIndex:2}).add(a.legendGroup),(w?t:a.legendGroup).on("mouseover",function(){a.setState("hover");t.css(b.options.itemHoverStyle)}).on("mouseout",function(){t.css(a.visible?i:j);a.setState()}).on("click",function(b){var c=function(){a.setVisible()},b={browserEvent:b};a.firePointEvent?a.firePointEvent("legendItemClick",b,c):
A(a,"legendItemClick",b,c)}),b.colorizeItem(a,a.visible),s&&v))a.checkbox=U("input",{type:"checkbox",checked:a.selected,defaultChecked:a.selected},e.itemCheckboxStyle,c.container),K(a.checkbox,"click",function(b){A(a,"checkboxClick",{checked:b.target.checked},function(){a.select()})});d=t.getBBox();C=a.legendItemWidth=e.itemWidth||g+h+d.width+l+(v?20:0),e=C;b.itemHeight=g=d.height;if(f&&b.itemX-B+e>(p||c.chartWidth-2*k-B))b.itemX=B,b.itemY+=n+b.lastLineHeight+q,b.lastLineHeight=0;b.maxItemWidth=r(b.maxItemWidth,
e);b.lastItemY=n+b.itemY+q;b.lastLineHeight=r(g,b.lastLineHeight);a._legendItemPos=[b.itemX,b.itemY];f?b.itemX+=e:(b.itemY+=n+g+q,b.lastLineHeight=g);b.offsetWidth=p||r((f?b.itemX-B-l:e)+k,b.offsetWidth)},render:function(){var a=this,b=a.chart,c=b.renderer,d=a.group,e,f,g,h,i=a.box,j=a.options,k=a.padding,l=j.borderWidth,m=j.backgroundColor;a.itemX=a.initialItemX;a.itemY=a.initialItemY;a.offsetWidth=0;a.lastItemY=0;if(!d)a.group=d=c.g("legend").attr({zIndex:7}).add(),a.contentGroup=c.g().attr({zIndex:1}).add(d),
a.scrollGroup=c.g().add(a.contentGroup);a.renderTitle();e=[];n(b.series,function(a){var b=a.options;if(o(b.showInLegend,b.linkedTo===v?v:!1,!0))e=e.concat(a.legendItems||(b.legendType==="point"?a.data:a))});Kb(e,function(a,b){return(a.options&&a.options.legendIndex||0)-(b.options&&b.options.legendIndex||0)});j.reversed&&e.reverse();a.allItems=e;a.display=f=!!e.length;n(e,function(b){a.renderItem(b)});g=j.width||a.offsetWidth;h=a.lastItemY+a.lastLineHeight+a.titleHeight;h=a.handleOverflow(h);if(l||
m){g+=k;h+=k;if(i){if(g>0&&h>0)i[i.isNew?"attr":"animate"](i.crisp(null,null,null,g,h)),i.isNew=!1}else a.box=i=c.rect(0,0,g,h,j.borderRadius,l||0).attr({stroke:j.borderColor,"stroke-width":l||0,fill:m||S}).add(d).shadow(j.shadow),i.isNew=!0;i[f?"show":"hide"]()}a.legendWidth=g;a.legendHeight=h;n(e,function(b){a.positionItem(b)});f&&d.align(s({width:g,height:h},j),!0,"spacingBox");b.isResizing||this.positionCheckboxes()},handleOverflow:function(a){var b=this,c=this.chart,d=c.renderer,e=this.options,
f=e.y,f=c.spacingBox.height+(e.verticalAlign==="top"?-f:f)-this.padding,g=e.maxHeight,h=this.clipRect,i=e.navigation,j=o(i.animation,!0),k=i.arrowSize||12,l=this.nav;e.layout==="horizontal"&&(f/=2);g&&(f=J(f,g));if(a>f&&!e.useHTML){this.clipHeight=c=f-20-this.titleHeight;this.pageCount=wa(a/c);this.currentPage=o(this.currentPage,1);this.fullHeight=a;if(!h)h=b.clipRect=d.clipRect(0,0,9999,0),b.contentGroup.clip(h);h.attr({height:c});if(!l)this.nav=l=d.g().attr({zIndex:1}).add(this.group),this.up=d.symbol("triangle",
0,0,k,k).on("click",function(){b.scroll(-1,j)}).add(l),this.pager=d.text("",15,10).css(i.style).add(l),this.down=d.symbol("triangle-down",0,0,k,k).on("click",function(){b.scroll(1,j)}).add(l);b.scroll(0);a=f}else if(l)h.attr({height:c.chartHeight}),l.hide(),this.scrollGroup.attr({translateY:1}),this.clipHeight=0;return a},scroll:function(a,b){var c=this.pageCount,d=this.currentPage+a,e=this.clipHeight,f=this.options.navigation,g=f.activeColor,h=f.inactiveColor,f=this.pager,i=this.padding;d>c&&(d=
c);if(d>0)b!==v&&La(b,this.chart),this.nav.attr({translateX:i,translateY:e+7+this.titleHeight,visibility:"visible"}),this.up.attr({fill:d===1?h:g}).css({cursor:d===1?"default":"pointer"}),f.attr({text:d+"/"+this.pageCount}),this.down.attr({x:18+this.pager.getBBox().width,fill:d===c?h:g}).css({cursor:d===c?"default":"pointer"}),e=-J(e*(d-1),this.fullHeight-e+i)+1,this.scrollGroup.animate({translateY:e}),f.attr({text:d+"/"+c}),this.currentPage=d,this.positionCheckboxes(e)}};/Trident\\/7\\.0/.test(na)&&
mb(fb.prototype,"positionItem",function(a,b){var c=this,d=function(){a.call(c,b)};c.chart.renderer.forExport?d():setTimeout(d)});yb.prototype={init:function(a,b){var c,d=a.series;a.series=null;c=x(L,a);c.series=a.series=d;d=c.chart;this.margin=this.splashArray("margin",d);this.spacing=this.splashArray("spacing",d);var e=d.events;this.bounds={h:{},v:{}};this.callback=b;this.isResizing=0;this.options=c;this.axes=[];this.series=[];this.hasCartesianSeries=d.showAxes;var f=this,g;f.index=Ga.length;Ga.push(f);
d.reflow!==!1&&K(f,"load",function(){f.initReflow()});if(e)for(g in e)K(f,g,e[g]);f.xAxis=[];f.yAxis=[];f.animation=ca?!1:o(d.animation,!0);f.pointCount=0;f.counters=new Jb;f.firstRender()},initSeries:function(a){var b=this.options.chart;(b=X[a.type||b.type||b.defaultSeriesType])||ka(17,!0);b=new b;b.init(this,a);return b},addSeries:function(a,b,c){var d,e=this;a&&(b=o(b,!0),A(e,"addSeries",{options:a},function(){d=e.initSeries(a);e.isDirtyLegend=!0;e.linkSeries();b&&e.redraw(c)}));return d},addAxis:function(a,
b,c,d){var e=b?"xAxis":"yAxis",f=this.options;new eb(this,x(a,{index:this[e].length,isX:b}));f[e]=ja(f[e]||{});f[e].push(a);o(c,!0)&&this.redraw(d)},isInsidePlot:function(a,b,c){var d=c?b:a,a=c?a:b;return d>=0&&d<=this.plotWidth&&a>=0&&a<=this.plotHeight},adjustTickAmounts:function(){this.options.chart.alignTicks!==!1&&n(this.axes,function(a){a.adjustTickAmount()});this.maxTicks=null},redraw:function(a){var b=this.axes,c=this.series,d=this.pointer,e=this.legend,f=this.isDirtyLegend,g,h,i=this.isDirtyBox,
j=c.length,k=j,l=this.renderer,m=l.isHidden(),p=[];La(a,this);m&&this.cloneRenderTo();for(this.layOutTitles();k--;)if(a=c[k],a.options.stacking&&(g=!0,a.isDirty)){h=!0;break}if(h)for(k=j;k--;)if(a=c[k],a.options.stacking)a.isDirty=!0;n(c,function(a){a.isDirty&&a.options.legendType==="point"&&(f=!0)});if(f&&e.options.enabled)e.render(),this.isDirtyLegend=!1;g&&this.getStacks();if(this.hasCartesianSeries){if(!this.isResizing)this.maxTicks=null,n(b,function(a){a.setScale()});this.adjustTickAmounts();
this.getMargins();n(b,function(a){a.isDirty&&(i=!0)});n(b,function(a){if(a.isDirtyExtremes)a.isDirtyExtremes=!1,p.push(function(){A(a,"afterSetExtremes",s(a.eventArgs,a.getExtremes()));delete a.eventArgs});(i||g)&&a.redraw()})}i&&this.drawChartBox();n(c,function(a){a.isDirty&&a.visible&&(!a.isCartesian||a.xAxis)&&a.redraw()});d&&d.reset&&d.reset(!0);l.draw();A(this,"redraw");m&&this.cloneRenderTo(!0);n(p,function(a){a.call()})},showLoading:function(a){var b=this.options,c=this.loadingDiv,d=b.loading;
if(!c)this.loadingDiv=c=U(Ea,{className:"highcharts-loading"},s(d.style,{zIndex:10,display:S}),this.container),this.loadingSpan=U("span",null,d.labelStyle,c);this.loadingSpan.innerHTML=a||b.lang.loading;if(!this.loadingShown)I(c,{opacity:0,display:"",left:this.plotLeft+"px",top:this.plotTop+"px",width:this.plotWidth+"px",height:this.plotHeight+"px"}),Bb(c,{opacity:d.style.opacity},{duration:d.showDuration||0}),this.loadingShown=!0},hideLoading:function(){var a=this.options,b=this.loadingDiv;b&&Bb(b,
{opacity:0},{duration:a.loading.hideDuration||100,complete:function(){I(b,{display:S})}});this.loadingShown=!1},get:function(a){var b=this.axes,c=this.series,d,e;for(d=0;d<b.length;d++)if(b[d].options.id===a)return b[d];for(d=0;d<c.length;d++)if(c[d].options.id===a)return c[d];for(d=0;d<c.length;d++){e=c[d].points||[];for(b=0;b<e.length;b++)if(e[b].id===a)return e[b]}return null},getAxes:function(){var a=this,b=this.options,c=b.xAxis=ja(b.xAxis||{}),b=b.yAxis=ja(b.yAxis||{});n(c,function(a,b){a.index=
b;a.isX=!0});n(b,function(a,b){a.index=b});c=c.concat(b);n(c,function(b){new eb(a,b)});a.adjustTickAmounts()},getSelectedPoints:function(){var a=[];n(this.series,function(b){a=a.concat(ub(b.points||[],function(a){return a.selected}))});return a},getSelectedSeries:function(){return ub(this.series,function(a){return a.selected})},getStacks:function(){var a=this;n(a.yAxis,function(a){if(a.stacks&&a.hasVisibleSeries)a.oldStacks=a.stacks});n(a.series,function(b){if(b.options.stacking&&(b.visible===!0||
a.options.chart.ignoreHiddenSeries===!1))b.stackKey=b.type+o(b.options.stack,"")})},showResetZoom:function(){var a=this,b=L.lang,c=a.options.chart.resetZoomButton,d=c.theme,e=d.states,f=c.relativeTo==="chart"?null:"plotBox";this.resetZoomButton=a.renderer.button(b.resetZoom,null,null,function(){a.zoomOut()},d,e&&e.hover).attr({align:c.position.align,title:b.resetZoomTitle}).add().align(c.position,!1,f)},zoomOut:function(){var a=this;A(a,"selection",{resetSelection:!0},function(){a.zoom()})},zoom:function(a){var b,
c=this.pointer,d=!1,e;!a||a.resetSelection?n(this.axes,function(a){b=a.zoom()}):n(a.xAxis.concat(a.yAxis),function(a){var e=a.axis,h=e.isXAxis;if(c[h?"zoomX":"zoomY"]||c[h?"pinchX":"pinchY"])b=e.zoom(a.min,a.max),e.displayBtn&&(d=!0)});e=this.resetZoomButton;if(d&&!e)this.showResetZoom();else if(!d&&T(e))this.resetZoomButton=e.destroy();b&&this.redraw(o(this.options.chart.animation,a&&a.animation,this.pointCount<100))},pan:function(a,b){var c=this,d=c.hoverPoints,e;d&&n(d,function(a){a.setState()});
n(b==="xy"?[1,0]:[1],function(b){var d=a[b?"chartX":"chartY"],h=c[b?"xAxis":"yAxis"][0],i=c[b?"mouseDownX":"mouseDownY"],j=(h.pointRange||0)/2,k=h.getExtremes(),l=h.toValue(i-d,!0)+j,i=h.toValue(i+c[b?"plotWidth":"plotHeight"]-d,!0)-j;h.series.length&&l>J(k.dataMin,k.min)&&i<r(k.dataMax,k.max)&&(h.setExtremes(l,i,!1,!1,{trigger:"pan"}),e=!0);c[b?"mouseDownX":"mouseDownY"]=d});e&&c.redraw(!1);I(c.container,{cursor:"move"})},setTitle:function(a,b){var f;var c=this,d=c.options,e;e=d.title=x(d.title,
a);f=d.subtitle=x(d.subtitle,b),d=f;n([["title",a,e],["subtitle",b,d]],function(a){var b=a[0],d=c[b],e=a[1],a=a[2];d&&e&&(c[b]=d=d.destroy());a&&a.text&&!d&&(c[b]=c.renderer.text(a.text,0,0,a.useHTML).attr({align:a.align,"class":"highcharts-"+b,zIndex:a.zIndex||4}).css(a.style).add())});c.layOutTitles()},layOutTitles:function(){var a=0,b=this.title,c=this.subtitle,d=this.options,e=d.title,d=d.subtitle,f=this.spacingBox.width-44;if(b&&(b.css({width:(e.width||f)+"px"}).align(s({y:15},e),!1,"spacingBox"),
!e.floating&&!e.verticalAlign))a=b.getBBox().height,a>=18&&a<=25&&(a=15);c&&(c.css({width:(d.width||f)+"px"}).align(s({y:a+e.margin},d),!1,"spacingBox"),!d.floating&&!d.verticalAlign&&(a=wa(a+c.getBBox().height)));this.titleOffset=a},getChartSize:function(){var a=this.options.chart,b=this.renderToClone||this.renderTo;this.containerWidth=kb(b,"width");this.containerHeight=kb(b,"height");this.chartWidth=r(0,a.width||this.containerWidth||600);this.chartHeight=r(0,o(a.height,this.containerHeight>19?this.containerHeight:
400))},cloneRenderTo:function(a){var b=this.renderToClone,c=this.container;a?b&&(this.renderTo.appendChild(c),Ta(b),delete this.renderToClone):(c&&c.parentNode===this.renderTo&&this.renderTo.removeChild(c),this.renderToClone=b=this.renderTo.cloneNode(0),I(b,{position:"absolute",top:"-9999px",display:"block"}),z.body.appendChild(b),c&&b.appendChild(c))},getContainer:function(){var a,b=this.options.chart,c,d,e;this.renderTo=a=b.renderTo;e="highcharts-"+zb++;if(ea(a))this.renderTo=a=z.getElementById(a);
a||ka(13,!0);c=y(w(a,"data-highcharts-chart"));!isNaN(c)&&Ga[c]&&Ga[c].destroy();w(a,"data-highcharts-chart",this.index);a.innerHTML="";a.offsetWidth||this.cloneRenderTo();this.getChartSize();c=this.chartWidth;d=this.chartHeight;this.container=a=U(Ea,{className:"highcharts-container"+(b.className?" "+b.className:""),id:e},s({position:"relative",overflow:"hidden",width:c+"px",height:d+"px",textAlign:"left",lineHeight:"normal",zIndex:0,"-webkit-tap-highlight-color":"rgba(0,0,0,0)"},b.style),this.renderToClone||
a);this._cursor=a.style.cursor;this.renderer=b.forExport?new za(a,c,d,!0):new Va(a,c,d);ca&&this.renderer.create(this,a,c,d)},getMargins:function(){var a=this.spacing,b,c=this.legend,d=this.margin,e=this.options.legend,f=o(e.margin,10),g=e.x,h=e.y,i=e.align,j=e.verticalAlign,k=this.titleOffset;this.resetMargins();b=this.axisOffset;if(k&&!u(d[0]))this.plotTop=r(this.plotTop,k+this.options.title.margin+a[0]);if(c.display&&!e.floating)if(i==="right"){if(!u(d[1]))this.marginRight=r(this.marginRight,c.legendWidth-
g+f+a[1])}else if(i==="left"){if(!u(d[3]))this.plotLeft=r(this.plotLeft,c.legendWidth+g+f+a[3])}else if(j==="top"){if(!u(d[0]))this.plotTop=r(this.plotTop,c.legendHeight+h+f+a[0])}else if(j==="bottom"&&!u(d[2]))this.marginBottom=r(this.marginBottom,c.legendHeight-h+f+a[2]);this.extraBottomMargin&&(this.marginBottom+=this.extraBottomMargin);this.extraTopMargin&&(this.plotTop+=this.extraTopMargin);this.hasCartesianSeries&&n(this.axes,function(a){a.getOffset()});u(d[3])||(this.plotLeft+=b[3]);u(d[0])||
(this.plotTop+=b[0]);u(d[2])||(this.marginBottom+=b[2]);u(d[1])||(this.marginRight+=b[1]);this.setChartSize()},initReflow:function(){function a(a){var g=c.width||kb(d,"width"),h=c.height||kb(d,"height"),a=a?a.target:N;if(!b.hasUserSize&&g&&h&&(a===N||a===z)){if(g!==b.containerWidth||h!==b.containerHeight)clearTimeout(e),b.reflowTimeout=e=setTimeout(function(){if(b.container)b.setSize(g,h,!1),b.hasUserSize=null},100);b.containerWidth=g;b.containerHeight=h}}var b=this,c=b.options.chart,d=b.renderTo,
e;b.reflow=a;K(N,"resize",a);K(b,"destroy",function(){$(N,"resize",a)})},setSize:function(a,b,c){var d=this,e,f,g;d.isResizing+=1;g=function(){d&&A(d,"endResize",null,function(){d.isResizing-=1})};La(c,d);d.oldChartHeight=d.chartHeight;d.oldChartWidth=d.chartWidth;if(u(a))d.chartWidth=e=r(0,t(a)),d.hasUserSize=!!e;if(u(b))d.chartHeight=f=r(0,t(b));I(d.container,{width:e+"px",height:f+"px"});d.setChartSize(!0);d.renderer.setSize(e,f,c);d.maxTicks=null;n(d.axes,function(a){a.isDirty=!0;a.setScale()});
n(d.series,function(a){a.isDirty=!0});d.isDirtyLegend=!0;d.isDirtyBox=!0;d.getMargins();d.redraw(c);d.oldChartHeight=null;A(d,"resize");Fa===!1?g():setTimeout(g,Fa&&Fa.duration||500)},setChartSize:function(a){var b=this.inverted,c=this.renderer,d=this.chartWidth,e=this.chartHeight,f=this.options.chart,g=this.spacing,h=this.clipOffset,i,j,k,l;this.plotLeft=i=t(this.plotLeft);this.plotTop=j=t(this.plotTop);this.plotWidth=k=r(0,t(d-i-this.marginRight));this.plotHeight=l=r(0,t(e-j-this.marginBottom));
this.plotSizeX=b?l:k;this.plotSizeY=b?k:l;this.plotBorderWidth=f.plotBorderWidth||0;this.spacingBox=c.spacingBox={x:g[3],y:g[0],width:d-g[3]-g[1],height:e-g[0]-g[2]};this.plotBox=c.plotBox={x:i,y:j,width:k,height:l};d=2*P(this.plotBorderWidth/2);b=wa(r(d,h[3])/2);c=wa(r(d,h[0])/2);this.clipBox={x:b,y:c,width:P(this.plotSizeX-r(d,h[1])/2-b),height:P(this.plotSizeY-r(d,h[2])/2-c)};a||n(this.axes,function(a){a.setAxisSize();a.setAxisTranslation()})},resetMargins:function(){var a=this.spacing,b=this.margin;
this.plotTop=o(b[0],a[0]);this.marginRight=o(b[1],a[1]);this.marginBottom=o(b[2],a[2]);this.plotLeft=o(b[3],a[3]);this.axisOffset=[0,0,0,0];this.clipOffset=[0,0,0,0]},drawChartBox:function(){var a=this.options.chart,b=this.renderer,c=this.chartWidth,d=this.chartHeight,e=this.chartBackground,f=this.plotBackground,g=this.plotBorder,h=this.plotBGImage,i=a.borderWidth||0,j=a.backgroundColor,k=a.plotBackgroundColor,l=a.plotBackgroundImage,m=a.plotBorderWidth||0,p,q=this.plotLeft,o=this.plotTop,n=this.plotWidth,
r=this.plotHeight,t=this.plotBox,u=this.clipRect,s=this.clipBox;p=i+(a.shadow?8:0);if(i||j)if(e)e.animate(e.crisp(null,null,null,c-p,d-p));else{e={fill:j||S};if(i)e.stroke=a.borderColor,e["stroke-width"]=i;this.chartBackground=b.rect(p/2,p/2,c-p,d-p,a.borderRadius,i).attr(e).add().shadow(a.shadow)}if(k)f?f.animate(t):this.plotBackground=b.rect(q,o,n,r,0).attr({fill:k}).add().shadow(a.plotShadow);if(l)h?h.animate(t):this.plotBGImage=b.image(l,q,o,n,r).add();u?u.animate({width:s.width,height:s.height}):
this.clipRect=b.clipRect(s);if(m)g?g.animate(g.crisp(null,q,o,n,r)):this.plotBorder=b.rect(q,o,n,r,0,-m).attr({stroke:a.plotBorderColor,"stroke-width":m,zIndex:1}).add();this.isDirtyBox=!1},propFromSeries:function(){var a=this,b=a.options.chart,c,d=a.options.series,e,f;n(["inverted","angular","polar"],function(g){c=X[b.type||b.defaultSeriesType];f=a[g]||b[g]||c&&c.prototype[g];for(e=d&&d.length;!f&&e--;)(c=X[d[e].type])&&c.prototype[g]&&(f=!0);a[g]=f})},linkSeries:function(){var a=this,b=a.series;
n(b,function(a){a.linkedSeries.length=0});n(b,function(b){var d=b.options.linkedTo;if(ea(d)&&(d=d===":previous"?a.series[b.index-1]:a.get(d)))d.linkedSeries.push(b),b.linkedParent=d})},render:function(){var a=this,b=a.axes,c=a.renderer,d=a.options,e=d.labels,f=d.credits,g;a.setTitle();a.legend=new fb(a,d.legend);a.getStacks();n(b,function(a){a.setScale()});a.getMargins();a.maxTicks=null;n(b,function(a){a.setTickPositions(!0);a.setMaxTicks()});a.adjustTickAmounts();a.getMargins();a.drawChartBox();
a.hasCartesianSeries&&n(b,function(a){a.render()});if(!a.seriesGroup)a.seriesGroup=c.g("series-group").attr({zIndex:3}).add();n(a.series,function(a){a.translate();a.setTooltipPoints();a.render()});e.items&&n(e.items,function(b){var d=s(e.style,b.style),f=y(d.left)+a.plotLeft,g=y(d.top)+a.plotTop+12;delete d.left;delete d.top;c.text(b.html,f,g).attr({zIndex:2}).css(d).add()});if(f.enabled&&!a.credits)g=f.href,a.credits=c.text(f.text,0,0).on("click",function(){if(g)location.href=g}).attr({align:f.position.align,
zIndex:8}).css(f.style).add().align(f.position);a.hasRendered=!0},destroy:function(){var a=this,b=a.axes,c=a.series,d=a.container,e,f=d&&d.parentNode;A(a,"destroy");Ga[a.index]=v;a.renderTo.removeAttribute("data-highcharts-chart");$(a);for(e=b.length;e--;)b[e]=b[e].destroy();for(e=c.length;e--;)c[e]=c[e].destroy();n("title,subtitle,chartBackground,plotBackground,plotBGImage,plotBorder,seriesGroup,clipRect,credits,pointer,scroller,rangeSelector,legend,resetZoomButton,tooltip,renderer".split(","),function(b){var c=
a[b];c&&c.destroy&&(a[b]=c.destroy())});if(d)d.innerHTML="",$(d),f&&Ta(d);for(e in a)delete a[e]},isReadyToRender:function(){var a=this;return!W&&N==N.top&&z.readyState!=="complete"||ca&&!N.canvg?(ca?Tb.push(function(){a.firstRender()},a.options.global.canvasToolsURL):z.attachEvent("onreadystatechange",function(){z.detachEvent("onreadystatechange",a.firstRender);z.readyState==="complete"&&a.firstRender()}),!1):!0},firstRender:function(){var a=this,b=a.options,c=a.callback;if(a.isReadyToRender())a.getContainer(),
A(a,"init"),a.resetMargins(),a.setChartSize(),a.propFromSeries(),a.getAxes(),n(b.series||[],function(b){a.initSeries(b)}),a.linkSeries(),A(a,"beforeRender"),a.pointer=new xb(a,b),a.render(),a.renderer.draw(),c&&c.apply(a,[a]),n(a.callbacks,function(b){b.apply(a,[a])}),a.cloneRenderTo(!0),A(a,"load")},splashArray:function(a,b){var c=b[a],c=T(c)?c:[c,c,c,c];return[o(b[a+"Top"],c[0]),o(b[a+"Right"],c[1]),o(b[a+"Bottom"],c[2]),o(b[a+"Left"],c[3])]}};yb.prototype.callbacks=[];var Pa=function(){};Pa.prototype=
{init:function(a,b,c){this.series=a;this.applyOptions(b,c);this.pointAttr={};if(a.options.colorByPoint&&(b=a.options.colors||a.chart.options.colors,this.color=this.color||b[a.colorCounter++],a.colorCounter===b.length))a.colorCounter=0;a.chart.pointCount++;return this},applyOptions:function(a,b){var c=this.series,d=c.pointValKey,a=Pa.prototype.optionsToObject.call(this,a);s(this,a);this.options=this.options?s(this.options,a):a;if(d)this.y=this[d];if(this.x===v&&c)this.x=b===v?c.autoIncrement():b;return this},
optionsToObject:function(a){var b={},c=this.series,d=c.pointArrayMap||["y"],e=d.length,f=0,g=0;if(typeof a==="number"||a===null)b[d[0]]=a;else if(Ia(a)){if(a.length>e){c=typeof a[0];if(c==="string")b.name=a[0];else if(c==="number")b.x=a[0];f++}for(;g<e;)b[d[g++]]=a[f++]}else if(typeof a==="object"){b=a;if(a.dataLabels)c._hasPointLabels=!0;if(a.marker)c._hasPointMarkers=!0}return b},destroy:function(){var a=this.series.chart,b=a.hoverPoints,c;a.pointCount--;if(b&&(this.setState(),ga(b,this),!b.length))a.hoverPoints=
null;if(this===a.hoverPoint)this.onMouseOut();if(this.graphic||this.dataLabel)$(this),this.destroyElements();this.legendItem&&a.legend.destroyItem(this);for(c in this)this[c]=null},destroyElements:function(){for(var a="graphic,dataLabel,dataLabelUpper,group,connector,shadowGroup".split(","),b,c=6;c--;)b=a[c],this[b]&&(this[b]=this[b].destroy())},getLabelConfig:function(){return{x:this.category,y:this.y,key:this.name||this.category,series:this.series,point:this,percentage:this.percentage,total:this.total||
this.stackTotal}},select:function(a,b){var c=this,d=c.series,e=d.chart,a=o(a,!c.selected);c.firePointEvent(a?"select":"unselect",{accumulate:b},function(){c.selected=c.options.selected=a;d.options.data[pa(c,d.data)]=c.options;c.setState(a&&"select");b||n(e.getSelectedPoints(),function(a){if(a.selected&&a!==c)a.selected=a.options.selected=!1,d.options.data[pa(a,d.data)]=a.options,a.setState(""),a.firePointEvent("unselect")})})},onMouseOver:function(a){var b=this.series,c=b.chart,d=c.tooltip,e=c.hoverPoint;
if(e&&e!==this)e.onMouseOut();this.firePointEvent("mouseOver");d&&(!d.shared||b.noSharedTooltip)&&d.refresh(this,a);this.setState("hover");c.hoverPoint=this},onMouseOut:function(){var a=this.series.chart,b=a.hoverPoints;if(!b||pa(this,b)===-1)this.firePointEvent("mouseOut"),this.setState(),a.hoverPoint=null},tooltipFormatter:function(a){var b=this.series,c=b.tooltipOptions,d=o(c.valueDecimals,""),e=c.valuePrefix||"",f=c.valueSuffix||"";n(b.pointArrayMap||["y"],function(b){b="{point."+b;if(e||f)a=
a.replace(b+"}",e+b+"}"+f);a=a.replace(b+"}",b+":,."+d+"f}")});return Ca(a,{point:this,series:this.series})},update:function(a,b,c){var d=this,e=d.series,f=d.graphic,g,h=e.data,i=e.chart,j=e.options,b=o(b,!0);d.firePointEvent("update",{options:a},function(){d.applyOptions(a);if(T(a)&&(e.getAttribs(),f))a&&a.marker&&a.marker.symbol?d.graphic=f.destroy():f.attr(d.pointAttr[d.state||""]);g=pa(d,h);e.xData[g]=d.x;e.yData[g]=e.toYData?e.toYData(d):d.y;e.zData[g]=d.z;j.data[g]=d.options;e.isDirty=e.isDirtyData=
!0;if(!e.fixedBox&&e.hasCartesianSeries)i.isDirtyBox=!0;j.legendType==="point"&&i.legend.destroyItem(d);b&&i.redraw(c)})},remove:function(a,b){var c=this,d=c.series,e=d.points,f=d.chart,g,h=d.data;La(b,f);a=o(a,!0);c.firePointEvent("remove",null,function(){g=pa(c,h);h.length===e.length&&e.splice(g,1);h.splice(g,1);d.options.data.splice(g,1);d.xData.splice(g,1);d.yData.splice(g,1);d.zData.splice(g,1);c.destroy();d.isDirty=!0;d.isDirtyData=!0;a&&f.redraw()})},firePointEvent:function(a,b,c){var d=this,
e=this.series.options;(e.point.events[a]||d.options&&d.options.events&&d.options.events[a])&&this.importEvents();a==="click"&&e.allowPointSelect&&(c=function(a){d.select(null,a.ctrlKey||a.metaKey||a.shiftKey)});A(this,a,b,c)},importEvents:function(){if(!this.hasImportedEvents){var a=x(this.series.options.point,this.options).events,b;this.events=a;for(b in a)K(this,b,a[b]);this.hasImportedEvents=!0}},setState:function(a){var b=this.plotX,c=this.plotY,d=this.series,e=d.options.states,f=Z[d.type].marker&&
d.options.marker,g=f&&!f.enabled,h=f&&f.states[a],i=h&&h.enabled===!1,j=d.stateMarkerGraphic,k=this.marker||{},l=d.chart,m=this.pointAttr,a=a||"";if(!(a===this.state||this.selected&&a!=="select"||e[a]&&e[a].enabled===!1||a&&(i||g&&!h.enabled)||a&&k.states&&k.states[a]&&k.states[a].enabled===!1)){if(this.graphic)e=f&&this.graphic.symbolName&&m[a].r,this.graphic.attr(x(m[a],e?{x:b-e,y:c-e,width:2*e,height:2*e}:{}));else{if(a&&h)e=h.radius,k=k.symbol||d.symbol,j&&j.currentSymbol!==k&&(j=j.destroy()),
j?j.attr({x:b-e,y:c-e}):(d.stateMarkerGraphic=j=l.renderer.symbol(k,b-e,c-e,2*e,2*e).attr(m[a]).add(d.markerGroup),j.currentSymbol=k);if(j)j[a&&l.isInsidePlot(b,c)?"show":"hide"]()}this.state=a}}};var Q=function(){};Q.prototype={isCartesian:!0,type:"line",pointClass:Pa,sorted:!0,requireSorting:!0,pointAttrToOptions:{stroke:"lineColor","stroke-width":"lineWidth",fill:"fillColor",r:"radius"},colorCounter:0,init:function(a,b){var c,d,e=a.series;this.chart=a;this.options=b=this.setOptions(b);this.linkedSeries=
[];this.bindAxes();s(this,{name:b.name,state:"",pointAttr:{},visible:b.visible!==!1,selected:b.selected===!0});if(ca)b.animation=!1;d=b.events;for(c in d)K(this,c,d[c]);if(d&&d.click||b.point&&b.point.events&&b.point.events.click||b.allowPointSelect)a.runTrackerClick=!0;this.getColor();this.getSymbol();this.setData(b.data,!1);if(this.isCartesian)a.hasCartesianSeries=!0;e.push(this);this._i=e.length-1;Kb(e,function(a,b){return o(a.options.index,a._i)-o(b.options.index,a._i)});n(e,function(a,b){a.index=
b;a.name=a.name||"Series "+(b+1)})},bindAxes:function(){var a=this,b=a.options,c=a.chart,d;a.isCartesian&&n(["xAxis","yAxis"],function(e){n(c[e],function(c){d=c.options;if(b[e]===d.index||b[e]!==v&&b[e]===d.id||b[e]===v&&d.index===0)c.series.push(a),a[e]=c,c.isDirty=!0});a[e]||ka(18,!0)})},autoIncrement:function(){var a=this.options,b=this.xIncrement,b=o(b,a.pointStart,0);this.pointInterval=o(this.pointInterval,a.pointInterval,1);this.xIncrement=b+this.pointInterval;return b},getSegments:function(){var a=
-1,b=[],c,d=this.points,e=d.length;if(e)if(this.options.connectNulls){for(c=e;c--;)d[c].y===null&&d.splice(c,1);d.length&&(b=[d])}else n(d,function(c,g){c.y===null?(g>a+1&&b.push(d.slice(a+1,g)),a=g):g===e-1&&b.push(d.slice(a+1,g+1))});this.segments=b},setOptions:function(a){var b=this.chart.options,c=b.plotOptions,d=c[this.type];this.userOptions=a;a=x(d,c.series,a);this.tooltipOptions=x(b.tooltip,a.tooltip);d.marker===null&&delete a.marker;return a},getColor:function(){var a=this.options,b=this.userOptions,
c=this.chart.options.colors,d=this.chart.counters,e;e=a.color||Z[this.type].color;if(!e&&!a.colorByPoint)u(b._colorIndex)?a=b._colorIndex:(b._colorIndex=d.color,a=d.color++),e=c[a];this.color=e;d.wrapColor(c.length)},getSymbol:function(){var a=this.userOptions,b=this.options.marker,c=this.chart,d=c.options.symbols,c=c.counters;this.symbol=b.symbol;if(!this.symbol)u(a._symbolIndex)?a=a._symbolIndex:(a._symbolIndex=c.symbol,a=c.symbol++),this.symbol=d[a];if(/^url/.test(this.symbol))b.radius=0;c.wrapSymbol(d.length)},
drawLegendSymbol:function(a){var b=this.options,c=b.marker,d=a.options,e;e=d.symbolWidth;var f=this.chart.renderer,g=this.legendGroup,a=a.baseline-t(f.fontMetrics(d.itemStyle.fontSize).b*0.3);if(b.lineWidth){d={"stroke-width":b.lineWidth};if(b.dashStyle)d.dashstyle=b.dashStyle;this.legendLine=f.path(["M",0,a,"L",e,a]).attr(d).add(g)}if(c&&c.enabled)b=c.radius,this.legendSymbol=e=f.symbol(this.symbol,e/2-b,a-b,2*b,2*b).add(g),e.isMarker=!0},addPoint:function(a,b,c,d){var e=this.options,f=this.data,
g=this.graph,h=this.area,i=this.chart,j=this.xData,k=this.yData,l=this.zData,m=this.xAxis&&this.xAxis.names,p=g&&g.shift||0,q=e.data,r;La(d,i);c&&n([g,h,this.graphNeg,this.areaNeg],function(a){if(a)a.shift=p+1});if(h)h.isArea=!0;b=o(b,!0);d={series:this};this.pointClass.prototype.applyOptions.apply(d,[a]);g=d.x;h=j.length;if(this.requireSorting&&g<j[h-1])for(r=!0;h&&j[h-1]>g;)h--;j.splice(h,0,g);k.splice(h,0,this.toYData?this.toYData(d):d.y);l.splice(h,0,d.z);if(m)m[g]=d.name;q.splice(h,0,a);r&&(this.data.splice(h,
0,null),this.processData());e.legendType==="point"&&this.generatePoints();c&&(f[0]&&f[0].remove?f[0].remove(!1):(f.shift(),j.shift(),k.shift(),l.shift(),q.shift()));this.isDirtyData=this.isDirty=!0;b&&(this.getAttribs(),i.redraw())},setData:function(a,b){var c=this.points,d=this.options,e=this.chart,f=null,g=this.xAxis,h=g&&g.names,i;this.xIncrement=null;this.pointRange=g&&g.categories?1:d.pointRange;this.colorCounter=0;var j=[],k=[],l=[],m=a?a.length:[];i=o(d.turboThreshold,1E3);var p=this.pointArrayMap,
p=p&&p.length,q=!!this.toYData;if(i&&m>i){for(i=0;f===null&&i<m;)f=a[i],i++;if(ra(f)){h=o(d.pointStart,0);d=o(d.pointInterval,1);for(i=0;i<m;i++)j[i]=h,k[i]=a[i],h+=d;this.xIncrement=h}else if(Ia(f))if(p)for(i=0;i<m;i++)d=a[i],j[i]=d[0],k[i]=d.slice(1,p+1);else for(i=0;i<m;i++)d=a[i],j[i]=d[0],k[i]=d[1];else ka(12)}else for(i=0;i<m;i++)if(a[i]!==v&&(d={series:this},this.pointClass.prototype.applyOptions.apply(d,[a[i]]),j[i]=d.x,k[i]=q?this.toYData(d):d.y,l[i]=d.z,h&&d.name))h[d.x]=d.name;ea(k[0])&&
ka(14,!0);this.data=[];this.options.data=a;this.xData=j;this.yData=k;this.zData=l;for(i=c&&c.length||0;i--;)c[i]&&c[i].destroy&&c[i].destroy();if(g)g.minRange=g.userMinRange;this.isDirty=this.isDirtyData=e.isDirtyBox=!0;o(b,!0)&&e.redraw(!1)},remove:function(a,b){var c=this,d=c.chart,a=o(a,!0);if(!c.isRemoving)c.isRemoving=!0,A(c,"remove",null,function(){c.destroy();d.isDirtyLegend=d.isDirtyBox=!0;d.linkSeries();a&&d.redraw(b)});c.isRemoving=!1},processData:function(a){var b=this.xData,c=this.yData,
d=b.length,e;e=0;var f,g,h=this.xAxis,i=this.options,j=i.cropThreshold,k=this.isCartesian;if(k&&!this.isDirty&&!h.isDirty&&!this.yAxis.isDirty&&!a)return!1;if(k&&this.sorted&&(!j||d>j||this.forceCrop))if(a=h.min,h=h.max,b[d-1]<a||b[0]>h)b=[],c=[];else if(b[0]<a||b[d-1]>h)e=this.cropData(this.xData,this.yData,a,h),b=e.xData,c=e.yData,e=e.start,f=!0;for(h=b.length-1;h>=0;h--)d=b[h]-b[h-1],d>0&&(g===v||d<g)?g=d:d<0&&this.requireSorting&&ka(15);this.cropped=f;this.cropStart=e;this.processedXData=b;this.processedYData=
c;if(i.pointRange===null)this.pointRange=g||1;this.closestPointRange=g},cropData:function(a,b,c,d){var e=a.length,f=0,g=e,h=o(this.cropShoulder,1),i;for(i=0;i<e;i++)if(a[i]>=c){f=r(0,i-h);break}for(;i<e;i++)if(a[i]>d){g=i+h;break}return{xData:a.slice(f,g),yData:b.slice(f,g),start:f,end:g}},generatePoints:function(){var a=this.options.data,b=this.data,c,d=this.processedXData,e=this.processedYData,f=this.pointClass,g=d.length,h=this.cropStart||0,i,j=this.hasGroupedData,k,l=[],m;if(!b&&!j)b=[],b.length=
a.length,b=this.data=b;for(m=0;m<g;m++)i=h+m,j?l[m]=(new f).init(this,[d[m]].concat(ja(e[m]))):(b[i]?k=b[i]:a[i]!==v&&(b[i]=k=(new f).init(this,a[i],d[m])),l[m]=k);if(b&&(g!==(c=b.length)||j))for(m=0;m<c;m++)if(m===h&&!j&&(m+=g),b[m])b[m].destroyElements(),b[m].plotX=v;this.data=b;this.points=l},setStackedPoints:function(){if(this.options.stacking&&!(this.visible!==!0&&this.chart.options.chart.ignoreHiddenSeries!==!1)){var a=this.processedXData,b=this.processedYData,c=[],d=b.length,e=this.options,
f=e.threshold,g=e.stack,e=e.stacking,h=this.stackKey,i="-"+h,j=this.negStacks,k=this.yAxis,l=k.stacks,m=k.oldStacks,p,q,o,n,t;for(o=0;o<d;o++){n=a[o];t=b[o];q=(p=j&&t<f)?i:h;l[q]||(l[q]={});if(!l[q][n])m[q]&&m[q][n]?(l[q][n]=m[q][n],l[q][n].total=null):l[q][n]=new Mb(k,k.options.stackLabels,p,n,g,e);q=l[q][n];q.points[this.index]=[q.cum||0];e==="percent"?(p=p?h:i,j&&l[p]&&l[p][n]?(p=l[p][n],q.total=p.total=r(p.total,q.total)+M(t)||0):q.total+=M(t)||0):q.total+=t||0;q.cum=(q.cum||0)+(t||0);q.points[this.index].push(q.cum);
c[o]=q.cum}if(e==="percent")k.usePercentage=!0;this.stackedYData=c;k.oldStacks={}}},setPercentStacks:function(){var a=this,b=a.stackKey,c=a.yAxis.stacks;n([b,"-"+b],function(b){var d;for(var e=a.xData.length,f,g;e--;)if(f=a.xData[e],d=(g=c[b]&&c[b][f])&&g.points[a.index],f=d)g=g.total?100/g.total:0,f[0]=ia(f[0]*g),f[1]=ia(f[1]*g),a.stackedYData[e]=f[1]})},getExtremes:function(){var a=this.yAxis,b=this.processedXData,c=this.stackedYData||this.processedYData,d=c.length,e=[],f=0,g=this.xAxis.getExtremes(),
h=g.min,g=g.max,i,j,k,l;for(l=0;l<d;l++)if(j=b[l],k=c[l],i=k!==null&&k!==v&&(!a.isLog||k.length||k>0),j=this.getExtremesFromAll||this.cropped||(b[l+1]||j)>=h&&(b[l-1]||j)<=g,i&&j)if(i=k.length)for(;i--;)k[i]!==null&&(e[f++]=k[i]);else e[f++]=k;this.dataMin=o(void 0,Ja(e));this.dataMax=o(void 0,ua(e))},translate:function(){this.processedXData||this.processData();this.generatePoints();for(var a=this.options,b=a.stacking,c=this.xAxis,d=c.categories,e=this.yAxis,f=this.points,g=f.length,h=!!this.modifyValue,
i=a.pointPlacement,j=i==="between"||ra(i),k=a.threshold,a=0;a<g;a++){var l=f[a],m=l.x,p=l.y,q=l.low,n=e.stacks[(this.negStacks&&p<k?"-":"")+this.stackKey];if(e.isLog&&p<=0)l.y=p=null;l.plotX=c.translate(m,0,0,0,1,i,this.type==="flags");if(b&&this.visible&&n&&n[m])n=n[m],p=n.points[this.index],q=p[0],p=p[1],q===0&&(q=o(k,e.min)),e.isLog&&q<=0&&(q=null),l.total=l.stackTotal=n.total,l.percentage=b==="percent"&&l.y/n.total*100,l.stackY=p,n.setOffset(this.pointXOffset||0,this.barW||0);l.yBottom=u(q)?e.translate(q,
0,1,0,1):null;h&&(p=this.modifyValue(p,l));l.plotY=typeof p==="number"&&p!==Infinity?e.translate(p,0,1,0,1):v;l.clientX=j?c.translate(m,0,0,0,1):l.plotX;l.negative=l.y<(k||0);l.category=d&&d[l.x]!==v?d[l.x]:l.x}this.getSegments()},setTooltipPoints:function(a){var b=[],c,d,e=this.xAxis,f=e&&e.getExtremes(),g=e?e.tooltipLen||e.len:this.chart.plotSizeX,h,i,j=[];if(this.options.enableMouseTracking!==!1){if(a)this.tooltipPoints=null;n(this.segments||this.points,function(a){b=b.concat(a)});e&&e.reversed&&
(b=b.reverse());this.orderTooltipPoints&&this.orderTooltipPoints(b);a=b.length;for(i=0;i<a;i++)if(e=b[i],c=e.x,c>=f.min&&c<=f.max){h=b[i+1];c=d===v?0:d+1;for(d=b[i+1]?J(r(0,P((e.clientX+(h?h.wrappedClientX||h.clientX:g))/2)),g):g;c>=0&&c<=d;)j[c++]=e}this.tooltipPoints=j}},tooltipHeaderFormatter:function(a){var b=this.tooltipOptions,c=b.xDateFormat,d=b.dateTimeLabelFormats,e=this.xAxis,f=e&&e.options.type==="datetime",b=b.headerFormat,e=e&&e.closestPointRange,g;if(f&&!c)if(e)for(g in D){if(D[g]>=
e){c=d[g];break}}else c=d.day;f&&c&&ra(a.key)&&(b=b.replace("{point.key}","{point.key:"+c+"}"));return Ca(b,{point:a,series:this})},onMouseOver:function(){var a=this.chart,b=a.hoverSeries;if(b&&b!==this)b.onMouseOut();this.options.events.mouseOver&&A(this,"mouseOver");this.setState("hover");a.hoverSeries=this},onMouseOut:function(){var a=this.options,b=this.chart,c=b.tooltip,d=b.hoverPoint;if(d)d.onMouseOut();this&&a.events.mouseOut&&A(this,"mouseOut");c&&!a.stickyTracking&&(!c.shared||this.noSharedTooltip)&&
c.hide();this.setState();b.hoverSeries=null},animate:function(a){var b=this,c=b.chart,d=c.renderer,e;e=b.options.animation;var f=c.clipBox,g=c.inverted,h;if(e&&!T(e))e=Z[b.type].animation;h="_sharedClip"+e.duration+e.easing;if(a)a=c[h],e=c[h+"m"],a||(c[h]=a=d.clipRect(s(f,{width:0})),c[h+"m"]=e=d.clipRect(-99,g?-c.plotLeft:-c.plotTop,99,g?c.chartWidth:c.chartHeight)),b.group.clip(a),b.markerGroup.clip(e),b.sharedClipKey=h;else{if(a=c[h])a.animate({width:c.plotSizeX},e),c[h+"m"].animate({width:c.plotSizeX+
99},e);b.animate=null;b.animationTimeout=setTimeout(function(){b.afterAnimate()},e.duration)}},afterAnimate:function(){var a=this.chart,b=this.sharedClipKey,c=this.group;c&&this.options.clip!==!1&&(c.clip(a.clipRect),this.markerGroup.clip());setTimeout(function(){b&&a[b]&&(a[b]=a[b].destroy(),a[b+"m"]=a[b+"m"].destroy())},100)},drawPoints:function(){var a,b=this.points,c=this.chart,d,e,f,g,h,i,j,k,l=this.options.marker,m,n=this.markerGroup;if(l.enabled||this._hasPointMarkers)for(f=b.length;f--;)if(g=
b[f],d=P(g.plotX),e=g.plotY,k=g.graphic,i=g.marker||{},a=l.enabled&&i.enabled===v||i.enabled,m=c.isInsidePlot(t(d),e,c.inverted),a&&e!==v&&!isNaN(e)&&g.y!==null)if(a=g.pointAttr[g.selected?"select":""],h=a.r,i=o(i.symbol,this.symbol),j=i.indexOf("url")===0,k)k.attr({visibility:m?W?"inherit":"visible":"hidden"}).animate(s({x:d-h,y:e-h},k.symbolName?{width:2*h,height:2*h}:{}));else{if(m&&(h>0||j))g.graphic=c.renderer.symbol(i,d-h,e-h,2*h,2*h).attr(a).add(n)}else if(k)g.graphic=k.destroy()},convertAttribs:function(a,
b,c,d){var e=this.pointAttrToOptions,f,g,h={},a=a||{},b=b||{},c=c||{},d=d||{};for(f in e)g=e[f],h[f]=o(a[g],b[f],c[f],d[f]);return h},getAttribs:function(){var a=this,b=a.options,c=Z[a.type].marker?b.marker:b,d=c.states,e=d.hover,f,g=a.color,h={stroke:g,fill:g},i=a.points||[],j=[],k,l=a.pointAttrToOptions,m=b.negativeColor,p=c.lineColor,q;b.marker?(e.radius=e.radius||c.radius+2,e.lineWidth=e.lineWidth||c.lineWidth+1):e.color=e.color||qa(e.color||g).brighten(e.brightness).get();j[""]=a.convertAttribs(c,
h);n(["hover","select"],function(b){j[b]=a.convertAttribs(d[b],j[""])});a.pointAttr=j;for(g=i.length;g--;){h=i[g];if((c=h.options&&h.options.marker||h.options)&&c.enabled===!1)c.radius=0;if(h.negative&&m)h.color=h.fillColor=m;f=b.colorByPoint||h.color;if(h.options)for(q in l)u(c[l[q]])&&(f=!0);if(f){c=c||{};k=[];d=c.states||{};f=d.hover=d.hover||{};if(!b.marker)f.color=qa(f.color||h.color).brighten(f.brightness||e.brightness).get();k[""]=a.convertAttribs(s({color:h.color,fillColor:h.color,lineColor:p===
null?h.color:v},c),j[""]);k.hover=a.convertAttribs(d.hover,j.hover,k[""]);k.select=a.convertAttribs(d.select,j.select,k[""])}else k=j;h.pointAttr=k}},update:function(a,b){var c=this.chart,d=this.type,e=X[d].prototype,f,a=x(this.userOptions,{animation:!1,index:this.index,pointStart:this.xData[0]},{data:this.options.data},a);this.remove(!1);for(f in e)e.hasOwnProperty(f)&&(this[f]=v);s(this,X[a.type||d].prototype);this.init(c,a);o(b,!0)&&c.redraw(!1)},destroy:function(){var a=this,b=a.chart,c=/AppleWebKit\\/533/.test(na),
d,e,f=a.data||[],g,h,i;A(a,"destroy");$(a);n(["xAxis","yAxis"],function(b){if(i=a[b])ga(i.series,a),i.isDirty=i.forceRedraw=!0,i.stacks={}});a.legendItem&&a.chart.legend.destroyItem(a);for(e=f.length;e--;)(g=f[e])&&g.destroy&&g.destroy();a.points=null;clearTimeout(a.animationTimeout);n("area,graph,dataLabelsGroup,group,markerGroup,tracker,graphNeg,areaNeg,posClip,negClip".split(","),function(b){a[b]&&(d=c&&b==="group"?"hide":"destroy",a[b][d]())});if(b.hoverSeries===a)b.hoverSeries=null;ga(b.series,
a);for(h in a)delete a[h]},drawDataLabels:function(){var a=this,b=a.options,c=b.cursor,d=b.dataLabels,b=a.points,e,f,g,h;if(d.enabled||a._hasPointLabels)a.dlProcessOptions&&a.dlProcessOptions(d),h=a.plotGroup("dataLabelsGroup","data-labels",a.visible?"visible":"hidden",d.zIndex||6),f=d,n(b,function(b){var j,k=b.dataLabel,l,m,n=b.connector,q=!0;e=b.options&&b.options.dataLabels;j=o(e&&e.enabled,f.enabled);if(k&&!j)b.dataLabel=k.destroy();else if(j){d=x(f,e);j=d.rotation;l=b.getLabelConfig();g=d.format?
Ca(d.format,l):d.formatter.call(l,d);d.style.color=o(d.color,d.style.color,a.color,"black");if(k)if(u(g))k.attr({text:g}),q=!1;else{if(b.dataLabel=k=k.destroy(),n)b.connector=n.destroy()}else if(u(g)){k={fill:d.backgroundColor,stroke:d.borderColor,"stroke-width":d.borderWidth,r:d.borderRadius||0,rotation:j,padding:d.padding,zIndex:1};for(m in k)k[m]===v&&delete k[m];k=b.dataLabel=a.chart.renderer[j?"text":"label"](g,0,-999,null,null,null,d.useHTML).attr(k).css(s(d.style,c&&{cursor:c})).add(h).shadow(d.shadow)}k&&
a.alignDataLabel(b,k,d,null,q)}})},alignDataLabel:function(a,b,c,d,e){var f=this.chart,g=f.inverted,h=o(a.plotX,-999),i=o(a.plotY,-999),j=b.getBBox();if(a=this.visible&&f.isInsidePlot(a.plotX,a.plotY,g))d=s({x:g?f.plotWidth-i:h,y:t(g?f.plotHeight-h:i),width:0,height:0},d),s(c,{width:j.width,height:j.height}),c.rotation?(g={align:c.align,x:d.x+c.x+d.width/2,y:d.y+c.y+d.height/2},b[e?"attr":"animate"](g)):(b.align(c,null,d),g=b.alignAttr,o(c.overflow,"justify")==="justify"?this.justifyDataLabel(b,c,
g,j,d,e):o(c.crop,!0)&&(a=f.isInsidePlot(g.x,g.y)&&f.isInsidePlot(g.x+j.width,g.y+j.height)));a||b.attr({y:-999})},justifyDataLabel:function(a,b,c,d,e,f){var g=this.chart,h=b.align,i=b.verticalAlign,j,k;j=c.x;if(j<0)h==="right"?b.align="left":b.x=-j,k=!0;j=c.x+d.width;if(j>g.plotWidth)h==="left"?b.align="right":b.x=g.plotWidth-j,k=!0;j=c.y;if(j<0)i==="bottom"?b.verticalAlign="top":b.y=-j,k=!0;j=c.y+d.height;if(j>g.plotHeight)i==="top"?b.verticalAlign="bottom":b.y=g.plotHeight-j,k=!0;if(k)a.placed=
!f,a.align(b,null,e)},getSegmentPath:function(a){var b=this,c=[],d=b.options.step;n(a,function(e,f){var g=e.plotX,h=e.plotY,i;b.getPointSpline?c.push.apply(c,b.getPointSpline(a,e,f)):(c.push(f?"L":"M"),d&&f&&(i=a[f-1],d==="right"?c.push(i.plotX,h):d==="center"?c.push((i.plotX+g)/2,i.plotY,(i.plotX+g)/2,h):c.push(g,i.plotY)),c.push(e.plotX,e.plotY))});return c},getGraphPath:function(){var a=this,b=[],c,d=[];n(a.segments,function(e){c=a.getSegmentPath(e);e.length>1?b=b.concat(c):d.push(e[0])});a.singlePoints=
d;return a.graphPath=b},drawGraph:function(){var a=this,b=this.options,c=[["graph",b.lineColor||this.color]],d=b.lineWidth,e=b.dashStyle,f=b.linecap!=="square",g=this.getGraphPath(),h=b.negativeColor;h&&c.push(["graphNeg",h]);n(c,function(c,h){var k=c[0],l=a[k];if(l)Wa(l),l.animate({d:g});else if(d&&g.length)l={stroke:c[1],"stroke-width":d,zIndex:1},e?l.dashstyle=e:f&&(l["stroke-linecap"]=l["stroke-linejoin"]="round"),a[k]=a.chart.renderer.path(g).attr(l).add(a.group).shadow(!h&&b.shadow)})},clipNeg:function(){var a=
this.options,b=this.chart,c=b.renderer,d=a.negativeColor||a.negativeFillColor,e,f=this.graph,g=this.area,h=this.posClip,i=this.negClip;e=b.chartWidth;var j=b.chartHeight,k=r(e,j),l=this.yAxis;if(d&&(f||g)){d=t(l.toPixels(a.threshold||0,!0));a={x:0,y:0,width:k,height:d};k={x:0,y:d,width:k,height:k};if(b.inverted)a.height=k.y=b.plotWidth-d,c.isVML&&(a={x:b.plotWidth-d-b.plotLeft,y:0,width:e,height:j},k={x:d+b.plotLeft-e,y:0,width:b.plotLeft+d,height:e});l.reversed?(b=k,e=a):(b=a,e=k);h?(h.animate(b),
i.animate(e)):(this.posClip=h=c.clipRect(b),this.negClip=i=c.clipRect(e),f&&this.graphNeg&&(f.clip(h),this.graphNeg.clip(i)),g&&(g.clip(h),this.areaNeg.clip(i)))}},invertGroups:function(){function a(){var a={width:b.yAxis.len,height:b.xAxis.len};n(["group","markerGroup"],function(c){b[c]&&b[c].attr(a).invert()})}var b=this,c=b.chart;if(b.xAxis)K(c,"resize",a),K(b,"destroy",function(){$(c,"resize",a)}),a(),b.invertGroups=a},plotGroup:function(a,b,c,d,e){var f=this[a],g=!f;g&&(this[a]=f=this.chart.renderer.g(b).attr({visibility:c,
zIndex:d||0.1}).add(e));f[g?"attr":"animate"](this.getPlotBox());return f},getPlotBox:function(){return{translateX:this.xAxis?this.xAxis.left:this.chart.plotLeft,translateY:this.yAxis?this.yAxis.top:this.chart.plotTop,scaleX:1,scaleY:1}},render:function(){var a=this.chart,b,c=this.options,d=c.animation&&!!this.animate&&a.renderer.isSVG,e=this.visible?"visible":"hidden",f=c.zIndex,g=this.hasRendered,h=a.seriesGroup;b=this.plotGroup("group","series",e,f,h);this.markerGroup=this.plotGroup("markerGroup",
"markers",e,f,h);d&&this.animate(!0);this.getAttribs();b.inverted=this.isCartesian?a.inverted:!1;this.drawGraph&&(this.drawGraph(),this.clipNeg());this.drawDataLabels();this.drawPoints();this.options.enableMouseTracking!==!1&&this.drawTracker();a.inverted&&this.invertGroups();c.clip!==!1&&!this.sharedClipKey&&!g&&b.clip(a.clipRect);d?this.animate():g||this.afterAnimate();this.isDirty=this.isDirtyData=!1;this.hasRendered=!0},redraw:function(){var a=this.chart,b=this.isDirtyData,c=this.group,d=this.xAxis,
e=this.yAxis;c&&(a.inverted&&c.attr({width:a.plotWidth,height:a.plotHeight}),c.animate({translateX:o(d&&d.left,a.plotLeft),translateY:o(e&&e.top,a.plotTop)}));this.translate();this.setTooltipPoints(!0);this.render();b&&A(this,"updatedData")},setState:function(a){var b=this.options,c=this.graph,d=this.graphNeg,e=b.states,b=b.lineWidth,a=a||"";if(this.state!==a)this.state=a,e[a]&&e[a].enabled===!1||(a&&(b=e[a].lineWidth||b+1),c&&!c.dashstyle&&(a={"stroke-width":b},c.attr(a),d&&d.attr(a)))},setVisible:function(a,
b){var c=this,d=c.chart,e=c.legendItem,f,g=d.options.chart.ignoreHiddenSeries,h=c.visible;f=(c.visible=a=c.userOptions.visible=a===v?!h:a)?"show":"hide";n(["group","dataLabelsGroup","markerGroup","tracker"],function(a){if(c[a])c[a][f]()});if(d.hoverSeries===c)c.onMouseOut();e&&d.legend.colorizeItem(c,a);c.isDirty=!0;c.options.stacking&&n(d.series,function(a){if(a.options.stacking&&a.visible)a.isDirty=!0});n(c.linkedSeries,function(b){b.setVisible(a,!1)});if(g)d.isDirtyBox=!0;b!==!1&&d.redraw();A(c,
f)},show:function(){this.setVisible(!0)},hide:function(){this.setVisible(!1)},select:function(a){this.selected=a=a===v?!this.selected:a;if(this.checkbox)this.checkbox.checked=a;A(this,a?"select":"unselect")},drawTracker:function(){var a=this,b=a.options,c=b.trackByArea,d=[].concat(c?a.areaPath:a.graphPath),e=d.length,f=a.chart,g=f.pointer,h=f.renderer,i=f.options.tooltip.snap,j=a.tracker,k=b.cursor,l=k&&{cursor:k},k=a.singlePoints,m,p=function(){if(f.hoverSeries!==a)a.onMouseOver()};if(e&&!c)for(m=
e+1;m--;)d[m]==="M"&&d.splice(m+1,0,d[m+1]-i,d[m+2],"L"),(m&&d[m]==="M"||m===e)&&d.splice(m,0,"L",d[m-2]+i,d[m-1]);for(m=0;m<k.length;m++)e=k[m],d.push("M",e.plotX-i,e.plotY,"L",e.plotX+i,e.plotY);j?j.attr({d:d}):(a.tracker=h.path(d).attr({"stroke-linejoin":"round",visibility:a.visible?"visible":"hidden",stroke:Qb,fill:c?Qb:S,"stroke-width":b.lineWidth+(c?0:2*i),zIndex:2}).add(a.group),n([a.tracker,a.markerGroup],function(a){a.addClass("highcharts-tracker").on("mouseover",p).on("mouseout",function(a){g.onTrackerMouseOut(a)}).css(l);
if(jb)a.on("touchstart",p)}))}};G=ha(Q);X.line=G;Z.area=x(Y,{threshold:0});G=ha(Q,{type:"area",getSegments:function(){var a=[],b=[],c=[],d=this.xAxis,e=this.yAxis,f=e.stacks[this.stackKey],g={},h,i,j=this.points,k=this.options.connectNulls,l,m,p;if(this.options.stacking&&!this.cropped){for(m=0;m<j.length;m++)g[j[m].x]=j[m];for(p in f)f[p].total!==null&&c.push(+p);c.sort(function(a,b){return a-b});n(c,function(a){if(!k||g[a]&&g[a].y!==null)g[a]?b.push(g[a]):(h=d.translate(a),l=f[a].percent?f[a].total?
f[a].cum*100/f[a].total:0:f[a].cum,i=e.toPixels(l,!0),b.push({y:null,plotX:h,clientX:h,plotY:i,yBottom:i,onMouseOver:oa}))});b.length&&a.push(b)}else Q.prototype.getSegments.call(this),a=this.segments;this.segments=a},getSegmentPath:function(a){var b=Q.prototype.getSegmentPath.call(this,a),c=[].concat(b),d,e=this.options;d=b.length;var f=this.yAxis.getThreshold(e.threshold),g;d===3&&c.push("L",b[1],b[2]);if(e.stacking&&!this.closedStacks)for(d=a.length-1;d>=0;d--)g=o(a[d].yBottom,f),d<a.length-1&&
e.step&&c.push(a[d+1].plotX,g),c.push(a[d].plotX,g);else this.closeSegment(c,a,f);this.areaPath=this.areaPath.concat(c);return b},closeSegment:function(a,b,c){a.push("L",b[b.length-1].plotX,c,"L",b[0].plotX,c)},drawGraph:function(){this.areaPath=[];Q.prototype.drawGraph.apply(this);var a=this,b=this.areaPath,c=this.options,d=c.negativeColor,e=c.negativeFillColor,f=[["area",this.color,c.fillColor]];(d||e)&&f.push(["areaNeg",d,e]);n(f,function(d){var e=d[0],f=a[e];f?f.animate({d:b}):a[e]=a.chart.renderer.path(b).attr({fill:o(d[2],
qa(d[1]).setOpacity(o(c.fillOpacity,0.75)).get()),zIndex:0}).add(a.group)})},drawLegendSymbol:function(a,b){b.legendSymbol=this.chart.renderer.rect(0,a.baseline-11,a.options.symbolWidth,12,2).attr({zIndex:3}).add(b.legendGroup)}});X.area=G;Z.spline=x(Y);F=ha(Q,{type:"spline",getPointSpline:function(a,b,c){var d=b.plotX,e=b.plotY,f=a[c-1],g=a[c+1],h,i,j,k;if(f&&g){a=f.plotY;j=g.plotX;var g=g.plotY,l;h=(1.5*d+f.plotX)/2.5;i=(1.5*e+a)/2.5;j=(1.5*d+j)/2.5;k=(1.5*e+g)/2.5;l=(k-i)*(j-d)/(j-h)+e-k;i+=l;
k+=l;i>a&&i>e?(i=r(a,e),k=2*e-i):i<a&&i<e&&(i=J(a,e),k=2*e-i);k>g&&k>e?(k=r(g,e),i=2*e-k):k<g&&k<e&&(k=J(g,e),i=2*e-k);b.rightContX=j;b.rightContY=k}c?(b=["C",f.rightContX||f.plotX,f.rightContY||f.plotY,h||d,i||e,d,e],f.rightContX=f.rightContY=null):b=["M",d,e];return b}});X.spline=F;Z.areaspline=x(Z.area);la=G.prototype;F=ha(F,{type:"areaspline",closedStacks:!0,getSegmentPath:la.getSegmentPath,closeSegment:la.closeSegment,drawGraph:la.drawGraph,drawLegendSymbol:la.drawLegendSymbol});X.areaspline=
F;Z.column=x(Y,{borderColor:"#FFFFFF",borderWidth:1,borderRadius:0,groupPadding:0.2,marker:null,pointPadding:0.1,minPointLength:0,cropThreshold:50,pointRange:null,states:{hover:{brightness:0.1,shadow:!1},select:{color:"#C0C0C0",borderColor:"#000000",shadow:!1}},dataLabels:{align:null,verticalAlign:null,y:null},stickyTracking:!1,threshold:0});F=ha(Q,{type:"column",pointAttrToOptions:{stroke:"borderColor","stroke-width":"borderWidth",fill:"color",r:"borderRadius"},cropShoulder:0,trackerGroups:["group",
"dataLabelsGroup"],negStacks:!0,init:function(){Q.prototype.init.apply(this,arguments);var a=this,b=a.chart;b.hasRendered&&n(b.series,function(b){if(b.type===a.type)b.isDirty=!0})},getColumnMetrics:function(){var a=this,b=a.options,c=a.xAxis,d=a.yAxis,e=c.reversed,f,g={},h,i=0;b.grouping===!1?i=1:n(a.chart.series,function(b){var c=b.options,e=b.yAxis;if(b.type===a.type&&b.visible&&d.len===e.len&&d.pos===e.pos)c.stacking?(f=b.stackKey,g[f]===v&&(g[f]=i++),h=g[f]):c.grouping!==!1&&(h=i++),b.columnIndex=
h});var c=J(M(c.transA)*(c.ordinalSlope||b.pointRange||c.closestPointRange||1),c.len),j=c*b.groupPadding,k=(c-2*j)/i,l=b.pointWidth,b=u(l)?(k-l)/2:k*b.pointPadding,l=o(l,k-2*b);return a.columnMetrics={width:l,offset:b+(j+((e?i-(a.columnIndex||0):a.columnIndex)||0)*k-c/2)*(e?-1:1)}},translate:function(){var a=this.chart,b=this.options,c=b.borderWidth,d=this.yAxis,e=this.translatedThreshold=d.getThreshold(b.threshold),f=o(b.minPointLength,5),b=this.getColumnMetrics(),g=b.width,h=this.barW=wa(r(g,1+
2*c)),i=this.pointXOffset=b.offset,j=-(c%2?0.5:0),k=c%2?0.5:1;a.renderer.isVML&&a.inverted&&(k+=1);Q.prototype.translate.apply(this);n(this.points,function(a){var b=o(a.yBottom,e),c=J(r(-999-b,a.plotY),d.len+999+b),n=a.plotX+i,u=h,s=J(c,b),v,c=r(c,b)-s;M(c)<f&&f&&(c=f,s=t(M(s-e)>f?b-f:e-(d.translate(a.y,0,1,0,1)<=e?f:0)));a.barX=n;a.pointWidth=g;b=M(n)<0.5;u=t(n+u)+j;n=t(n)+j;u-=n;v=M(s)<0.5;c=t(s+c)+k;s=t(s)+k;c-=s;b&&(n+=1,u-=1);v&&(s-=1,c+=1);a.shapeType="rect";a.shapeArgs={x:n,y:s,width:u,height:c}})},
getSymbol:oa,drawLegendSymbol:G.prototype.drawLegendSymbol,drawGraph:oa,drawPoints:function(){var a=this,b=a.options,c=a.chart.renderer,d;n(a.points,function(e){var f=e.plotY,g=e.graphic;if(f!==v&&!isNaN(f)&&e.y!==null)d=e.shapeArgs,g?(Wa(g),g.animate(x(d))):e.graphic=c[e.shapeType](d).attr(e.pointAttr[e.selected?"select":""]).add(a.group).shadow(b.shadow,null,b.stacking&&!b.borderRadius);else if(g)e.graphic=g.destroy()})},drawTracker:function(){var a=this,b=a.chart,c=b.pointer,d=a.options.cursor,
e=d&&{cursor:d},f=function(c){var d=c.target,e;if(b.hoverSeries!==a)a.onMouseOver();for(;d&&!e;)e=d.point,d=d.parentNode;if(e!==v&&e!==b.hoverPoint)e.onMouseOver(c)};n(a.points,function(a){if(a.graphic)a.graphic.element.point=a;if(a.dataLabel)a.dataLabel.element.point=a});if(!a._hasTracking)n(a.trackerGroups,function(b){if(a[b]&&(a[b].addClass("highcharts-tracker").on("mouseover",f).on("mouseout",function(a){c.onTrackerMouseOut(a)}).css(e),jb))a[b].on("touchstart",f)}),a._hasTracking=!0},alignDataLabel:function(a,
b,c,d,e){var f=this.chart,g=f.inverted,h=a.dlBox||a.shapeArgs,i=a.below||a.plotY>o(this.translatedThreshold,f.plotSizeY),j=o(c.inside,!!this.options.stacking);if(h&&(d=x(h),g&&(d={x:f.plotWidth-d.y-d.height,y:f.plotHeight-d.x-d.width,width:d.height,height:d.width}),!j))g?(d.x+=i?0:d.width,d.width=0):(d.y+=i?d.height:0,d.height=0);c.align=o(c.align,!g||j?"center":i?"right":"left");c.verticalAlign=o(c.verticalAlign,g||j?"middle":i?"top":"bottom");Q.prototype.alignDataLabel.call(this,a,b,c,d,e)},animate:function(a){var b=
this.yAxis,c=this.options,d=this.chart.inverted,e={};if(W)a?(e.scaleY=0.001,a=J(b.pos+b.len,r(b.pos,b.toPixels(c.threshold))),d?e.translateX=a-b.len:e.translateY=a,this.group.attr(e)):(e.scaleY=1,e[d?"translateX":"translateY"]=b.pos,this.group.animate(e,this.options.animation),this.animate=null)},remove:function(){var a=this,b=a.chart;b.hasRendered&&n(b.series,function(b){if(b.type===a.type)b.isDirty=!0});Q.prototype.remove.apply(a,arguments)}});X.column=F;Z.bar=x(Z.column);la=ha(F,{type:"bar",inverted:!0});
X.bar=la;Z.scatter=x(Y,{lineWidth:0,tooltip:{headerFormat:\'<span style="font-size: 10px; color:{series.color}">{series.name}</span><br/>\',pointFormat:"x: <b>{point.x}</b><br/>y: <b>{point.y}</b><br/>",followPointer:!0},stickyTracking:!1});la=ha(Q,{type:"scatter",sorted:!1,requireSorting:!1,noSharedTooltip:!0,trackerGroups:["markerGroup"],takeOrdinalPosition:!1,drawTracker:F.prototype.drawTracker,setTooltipPoints:oa});X.scatter=la;Z.pie=x(Y,{borderColor:"#FFFFFF",borderWidth:1,center:[null,null],clip:!1,
colorByPoint:!0,dataLabels:{distance:30,enabled:!0,formatter:function(){return this.point.name}},ignoreHiddenPoint:!0,legendType:"point",marker:null,size:null,showInLegend:!1,slicedOffset:10,states:{hover:{brightness:0.1,shadow:!1}},stickyTracking:!1,tooltip:{followPointer:!0}});Y={type:"pie",isCartesian:!1,pointClass:ha(Pa,{init:function(){Pa.prototype.init.apply(this,arguments);var a=this,b;if(a.y<0)a.y=null;s(a,{visible:a.visible!==!1,name:o(a.name,"Slice")});b=function(b){a.slice(b.type==="select")};
K(a,"select",b);K(a,"unselect",b);return a},setVisible:function(a){var b=this,c=b.series,d=c.chart,e;b.visible=b.options.visible=a=a===v?!b.visible:a;c.options.data[pa(b,c.data)]=b.options;e=a?"show":"hide";n(["graphic","dataLabel","connector","shadowGroup"],function(a){if(b[a])b[a][e]()});b.legendItem&&d.legend.colorizeItem(b,a);if(!c.isDirty&&c.options.ignoreHiddenPoint)c.isDirty=!0,d.redraw()},slice:function(a,b,c){var d=this.series;La(c,d.chart);o(b,!0);this.sliced=this.options.sliced=a=u(a)?
a:!this.sliced;d.options.data[pa(this,d.data)]=this.options;a=a?this.slicedTranslation:{translateX:0,translateY:0};this.graphic.animate(a);this.shadowGroup&&this.shadowGroup.animate(a)}}),requireSorting:!1,noSharedTooltip:!0,trackerGroups:["group","dataLabelsGroup"],pointAttrToOptions:{stroke:"borderColor","stroke-width":"borderWidth",fill:"color"},getColor:oa,animate:function(a){var b=this,c=b.points,d=b.startAngleRad;if(!a)n(c,function(a){var c=a.graphic,a=a.shapeArgs;c&&(c.attr({r:b.center[3]/
2,start:d,end:d}),c.animate({r:a.r,start:a.start,end:a.end},b.options.animation))}),b.animate=null},setData:function(a,b){Q.prototype.setData.call(this,a,!1);this.processData();this.generatePoints();o(b,!0)&&this.chart.redraw()},generatePoints:function(){var a,b=0,c,d,e,f=this.options.ignoreHiddenPoint;Q.prototype.generatePoints.call(this);c=this.points;d=c.length;for(a=0;a<d;a++)e=c[a],b+=f&&!e.visible?0:e.y;this.total=b;for(a=0;a<d;a++)e=c[a],e.percentage=b>0?e.y/b*100:0,e.total=b},getCenter:function(){var a=
this.options,b=this.chart,c=2*(a.slicedOffset||0),d,e=b.plotWidth-2*c,f=b.plotHeight-2*c,b=a.center,a=[o(b[0],"50%"),o(b[1],"50%"),a.size||"100%",a.innerSize||0],g=J(e,f),h;return Na(a,function(a,b){h=/%$/.test(a);d=b<2||b===2&&h;return(h?[e,f,g,g][b]*y(a)/100:a)+(d?c:0)})},translate:function(a){this.generatePoints();var b=0,c=this.options,d=c.slicedOffset,e=d+c.borderWidth,f,g,h,i=c.startAngle||0,j=this.startAngleRad=xa/180*(i-90),i=(this.endAngleRad=xa/180*((c.endAngle||i+360)-90))-j,k=this.points,
l=c.dataLabels.distance,c=c.ignoreHiddenPoint,m,n=k.length,o;if(!a)this.center=a=this.getCenter();this.getX=function(b,c){h=R.asin((b-a[1])/(a[2]/2+l));return a[0]+(c?-1:1)*V(h)*(a[2]/2+l)};for(m=0;m<n;m++){o=k[m];f=j+b*i;if(!c||o.visible)b+=o.percentage/100;g=j+b*i;o.shapeType="arc";o.shapeArgs={x:a[0],y:a[1],r:a[2]/2,innerR:a[3]/2,start:t(f*1E3)/1E3,end:t(g*1E3)/1E3};h=(g+f)/2;h>0.75*i&&(h-=2*xa);o.slicedTranslation={translateX:t(V(h)*d),translateY:t(ba(h)*d)};f=V(h)*a[2]/2;g=ba(h)*a[2]/2;o.tooltipPos=
[a[0]+f*0.7,a[1]+g*0.7];o.half=h<-xa/2||h>xa/2?1:0;o.angle=h;e=J(e,l/2);o.labelPos=[a[0]+f+V(h)*l,a[1]+g+ba(h)*l,a[0]+f+V(h)*e,a[1]+g+ba(h)*e,a[0]+f,a[1]+g,l<0?"center":o.half?"right":"left",h]}},setTooltipPoints:oa,drawGraph:null,drawPoints:function(){var a=this,b=a.chart.renderer,c,d,e=a.options.shadow,f,g;if(e&&!a.shadowGroup)a.shadowGroup=b.g("shadow").add(a.group);n(a.points,function(h){d=h.graphic;g=h.shapeArgs;f=h.shadowGroup;if(e&&!f)f=h.shadowGroup=b.g("shadow").add(a.shadowGroup);c=h.sliced?
h.slicedTranslation:{translateX:0,translateY:0};f&&f.attr(c);d?d.animate(s(g,c)):h.graphic=d=b.arc(g).setRadialReference(a.center).attr(h.pointAttr[h.selected?"select":""]).attr({"stroke-linejoin":"round"}).attr(c).add(a.group).shadow(e,f);h.visible===!1&&h.setVisible(!1)})},sortByAngle:function(a,b){a.sort(function(a,d){return a.angle!==void 0&&(d.angle-a.angle)*b})},drawDataLabels:function(){var a=this,b=a.data,c,d=a.chart,e=a.options.dataLabels,f=o(e.connectorPadding,10),g=o(e.connectorWidth,1),
h=d.plotWidth,d=d.plotHeight,i,j,k=o(e.softConnector,!0),l=e.distance,m=a.center,p=m[2]/2,q=m[1],u=l>0,s,v,w,x,y=[[],[]],z,A,E,H,C,D=[0,0,0,0],J=function(a,b){return b.y-a.y};if(a.visible&&(e.enabled||a._hasPointLabels)){Q.prototype.drawDataLabels.apply(a);n(b,function(a){a.dataLabel&&y[a.half].push(a)});for(H=0;!x&&b[H];)x=b[H]&&b[H].dataLabel&&(b[H].dataLabel.getBBox().height||21),H++;for(H=2;H--;){var b=[],I=[],G=y[H],K=G.length,F;a.sortByAngle(G,H-0.5);if(l>0){for(C=q-p-l;C<=q+p+l;C+=x)b.push(C);
v=b.length;if(K>v){c=[].concat(G);c.sort(J);for(C=K;C--;)c[C].rank=C;for(C=K;C--;)G[C].rank>=v&&G.splice(C,1);K=G.length}for(C=0;C<K;C++){c=G[C];w=c.labelPos;c=9999;var N,L;for(L=0;L<v;L++)N=M(b[L]-w[1]),N<c&&(c=N,F=L);if(F<C&&b[C]!==null)F=C;else for(v<K-C+F&&b[C]!==null&&(F=v-K+C);b[F]===null;)F++;I.push({i:F,y:b[F]});b[F]=null}I.sort(J)}for(C=0;C<K;C++){c=G[C];w=c.labelPos;s=c.dataLabel;E=c.visible===!1?"hidden":"visible";c=w[1];if(l>0){if(v=I.pop(),F=v.i,A=v.y,c>A&&b[F+1]!==null||c<A&&b[F-1]!==
null)A=c}else A=c;z=e.justify?m[0]+(H?-1:1)*(p+l):a.getX(F===0||F===b.length-1?c:A,H);s._attr={visibility:E,align:w[6]};s._pos={x:z+e.x+({left:f,right:-f}[w[6]]||0),y:A+e.y-10};s.connX=z;s.connY=A;if(this.options.size===null)v=s.width,z-v<f?D[3]=r(t(v-z+f),D[3]):z+v>h-f&&(D[1]=r(t(z+v-h+f),D[1])),A-x/2<0?D[0]=r(t(-A+x/2),D[0]):A+x/2>d&&(D[2]=r(t(A+x/2-d),D[2]))}}if(ua(D)===0||this.verifyDataLabelOverflow(D))this.placeDataLabels(),u&&g&&n(this.points,function(b){i=b.connector;w=b.labelPos;if((s=b.dataLabel)&&
s._pos)E=s._attr.visibility,z=s.connX,A=s.connY,j=k?["M",z+(w[6]==="left"?5:-5),A,"C",z,A,2*w[2]-w[4],2*w[3]-w[5],w[2],w[3],"L",w[4],w[5]]:["M",z+(w[6]==="left"?5:-5),A,"L",w[2],w[3],"L",w[4],w[5]],i?(i.animate({d:j}),i.attr("visibility",E)):b.connector=i=a.chart.renderer.path(j).attr({"stroke-width":g,stroke:e.connectorColor||b.color||"#606060",visibility:E}).add(a.group);else if(i)b.connector=i.destroy()})}},verifyDataLabelOverflow:function(a){var b=this.center,c=this.options,d=c.center,e=c=c.minSize||
80,f;d[0]!==null?e=r(b[2]-r(a[1],a[3]),c):(e=r(b[2]-a[1]-a[3],c),b[0]+=(a[3]-a[1])/2);d[1]!==null?e=r(J(e,b[2]-r(a[0],a[2])),c):(e=r(J(e,b[2]-a[0]-a[2]),c),b[1]+=(a[0]-a[2])/2);e<b[2]?(b[2]=e,this.translate(b),n(this.points,function(a){if(a.dataLabel)a.dataLabel._pos=null}),this.drawDataLabels()):f=!0;return f},placeDataLabels:function(){n(this.points,function(a){var a=a.dataLabel,b;if(a)(b=a._pos)?(a.attr(a._attr),a[a.moved?"animate":"attr"](b),a.moved=!0):a&&a.attr({y:-999})})},alignDataLabel:oa,
drawTracker:F.prototype.drawTracker,drawLegendSymbol:G.prototype.drawLegendSymbol,getSymbol:oa};Y=ha(Q,Y);X.pie=Y;s(Highcharts,{Axis:eb,Chart:yb,Color:qa,Legend:fb,Pointer:xb,Point:Pa,Tick:Ma,Tooltip:wb,Renderer:Va,Series:Q,SVGElement:va,SVGRenderer:za,arrayMin:Ja,arrayMax:ua,charts:Ga,dateFormat:Ya,format:Ca,pathAnim:Ab,getOptions:function(){return L},hasBidiBug:Ub,isTouchDevice:Ob,numberFormat:Aa,seriesTypes:X,setOptions:function(a){L=x(L,a);Lb();return L},addEvent:K,removeEvent:$,createElement:U,
discardElement:Ta,css:I,each:n,extend:s,map:Na,merge:x,pick:o,splat:ja,extendClass:ha,pInt:y,wrap:mb,svg:W,canvas:ca,vml:!W&&!ca,product:"Highcharts",version:"3.0.7"})})();';
    
    protected static $jquery = '(function(e,t){var n,r,i=typeof t,o=e.location,a=e.document,s=a.documentElement,l=e.jQuery,u=e.$,c={},p=[],f="1.10.2",d=p.concat,h=p.push,g=p.slice,m=p.indexOf,y=c.toString,v=c.hasOwnProperty,b=f.trim,x=function(e,t){return new x.fn.init(e,t,r)},w=/[+-]?(?:\\d*\\.|)\\d+(?:[eE][+-]?\\d+|)/.source,T=/\\S+/g,C=/^[\\s\\uFEFF\\xA0]+|[\\s\\uFEFF\\xA0]+$/g,N=/^(?:\\s*(<[\\w\\W]+>)[^>]*|#([\\w-]*))$/,k=/^<(\\w+)\\s*\\/?>(?:<\\/\\1>|)$/,E=/^[\\],:{}\\s]*$/,S=/(?:^|:|,)(?:\\s*\\[)+/g,A=/\\\\(?:["\\\\\\/bfnrt]|u[\\da-fA-F]{4})/g,j=/"[^"\\\\\\r\\n]*"|true|false|null|-?(?:\\d+\\.|)\\d+(?:[eE][+-]?\\d+|)/g,D=/^-ms-/,L=/-([\\da-z])/gi,H=function(e,t){return t.toUpperCase()},q=function(e){(a.addEventListener||"load"===e.type||"complete"===a.readyState)&&(_(),x.ready())},_=function(){a.addEventListener?(a.removeEventListener("DOMContentLoaded",q,!1),e.removeEventListener("load",q,!1)):(a.detachEvent("onreadystatechange",q),e.detachEvent("onload",q))};x.fn=x.prototype={jquery:f,constructor:x,init:function(e,n,r){var i,o;if(!e)return this;if("string"==typeof e){if(i="<"===e.charAt(0)&&">"===e.charAt(e.length-1)&&e.length>=3?[null,e,null]:N.exec(e),!i||!i[1]&&n)return!n||n.jquery?(n||r).find(e):this.constructor(n).find(e);if(i[1]){if(n=n instanceof x?n[0]:n,x.merge(this,x.parseHTML(i[1],n&&n.nodeType?n.ownerDocument||n:a,!0)),k.test(i[1])&&x.isPlainObject(n))for(i in n)x.isFunction(this[i])?this[i](n[i]):this.attr(i,n[i]);return this}if(o=a.getElementById(i[2]),o&&o.parentNode){if(o.id!==i[2])return r.find(e);this.length=1,this[0]=o}return this.context=a,this.selector=e,this}return e.nodeType?(this.context=this[0]=e,this.length=1,this):x.isFunction(e)?r.ready(e):(e.selector!==t&&(this.selector=e.selector,this.context=e.context),x.makeArray(e,this))},selector:"",length:0,toArray:function(){return g.call(this)},get:function(e){return null==e?this.toArray():0>e?this[this.length+e]:this[e]},pushStack:function(e){var t=x.merge(this.constructor(),e);return t.prevObject=this,t.context=this.context,t},each:function(e,t){return x.each(this,e,t)},ready:function(e){return x.ready.promise().done(e),this},slice:function(){return this.pushStack(g.apply(this,arguments))},first:function(){return this.eq(0)},last:function(){return this.eq(-1)},eq:function(e){var t=this.length,n=+e+(0>e?t:0);return this.pushStack(n>=0&&t>n?[this[n]]:[])},map:function(e){return this.pushStack(x.map(this,function(t,n){return e.call(t,n,t)}))},end:function(){return this.prevObject||this.constructor(null)},push:h,sort:[].sort,splice:[].splice},x.fn.init.prototype=x.fn,x.extend=x.fn.extend=function(){var e,n,r,i,o,a,s=arguments[0]||{},l=1,u=arguments.length,c=!1;for("boolean"==typeof s&&(c=s,s=arguments[1]||{},l=2),"object"==typeof s||x.isFunction(s)||(s={}),u===l&&(s=this,--l);u>l;l++)if(null!=(o=arguments[l]))for(i in o)e=s[i],r=o[i],s!==r&&(c&&r&&(x.isPlainObject(r)||(n=x.isArray(r)))?(n?(n=!1,a=e&&x.isArray(e)?e:[]):a=e&&x.isPlainObject(e)?e:{},s[i]=x.extend(c,a,r)):r!==t&&(s[i]=r));return s},x.extend({expando:"jQuery"+(f+Math.random()).replace(/\\D/g,""),noConflict:function(t){return e.$===x&&(e.$=u),t&&e.jQuery===x&&(e.jQuery=l),x},isReady:!1,readyWait:1,holdReady:function(e){e?x.readyWait++:x.ready(!0)},ready:function(e){if(e===!0?!--x.readyWait:!x.isReady){if(!a.body)return setTimeout(x.ready);x.isReady=!0,e!==!0&&--x.readyWait>0||(n.resolveWith(a,[x]),x.fn.trigger&&x(a).trigger("ready").off("ready"))}},isFunction:function(e){return"function"===x.type(e)},isArray:Array.isArray||function(e){return"array"===x.type(e)},isWindow:function(e){return null!=e&&e==e.window},isNumeric:function(e){return!isNaN(parseFloat(e))&&isFinite(e)},type:function(e){return null==e?e+"":"object"==typeof e||"function"==typeof e?c[y.call(e)]||"object":typeof e},isPlainObject:function(e){var n;if(!e||"object"!==x.type(e)||e.nodeType||x.isWindow(e))return!1;try{if(e.constructor&&!v.call(e,"constructor")&&!v.call(e.constructor.prototype,"isPrototypeOf"))return!1}catch(r){return!1}if(x.support.ownLast)for(n in e)return v.call(e,n);for(n in e);return n===t||v.call(e,n)},isEmptyObject:function(e){var t;for(t in e)return!1;return!0},error:function(e){throw Error(e)},parseHTML:function(e,t,n){if(!e||"string"!=typeof e)return null;"boolean"==typeof t&&(n=t,t=!1),t=t||a;var r=k.exec(e),i=!n&&[];return r?[t.createElement(r[1])]:(r=x.buildFragment([e],t,i),i&&x(i).remove(),x.merge([],r.childNodes))},parseJSON:function(n){return e.JSON&&e.JSON.parse?e.JSON.parse(n):null===n?n:"string"==typeof n&&(n=x.trim(n),n&&E.test(n.replace(A,"@").replace(j,"]").replace(S,"")))?Function("return "+n)():(x.error("Invalid JSON: "+n),t)},parseXML:function(n){var r,i;if(!n||"string"!=typeof n)return null;try{e.DOMParser?(i=new DOMParser,r=i.parseFromString(n,"text/xml")):(r=new ActiveXObject("Microsoft.XMLDOM"),r.async="false",r.loadXML(n))}catch(o){r=t}return r&&r.documentElement&&!r.getElementsByTagName("parsererror").length||x.error("Invalid XML: "+n),r},noop:function(){},globalEval:function(t){t&&x.trim(t)&&(e.execScript||function(t){e.eval.call(e,t)})(t)},camelCase:function(e){return e.replace(D,"ms-").replace(L,H)},nodeName:function(e,t){return e.nodeName&&e.nodeName.toLowerCase()===t.toLowerCase()},each:function(e,t,n){var r,i=0,o=e.length,a=M(e);if(n){if(a){for(;o>i;i++)if(r=t.apply(e[i],n),r===!1)break}else for(i in e)if(r=t.apply(e[i],n),r===!1)break}else if(a){for(;o>i;i++)if(r=t.call(e[i],i,e[i]),r===!1)break}else for(i in e)if(r=t.call(e[i],i,e[i]),r===!1)break;return e},trim:b&&!b.call("\\ufeff\\u00a0")?function(e){return null==e?"":b.call(e)}:function(e){return null==e?"":(e+"").replace(C,"")},makeArray:function(e,t){var n=t||[];return null!=e&&(M(Object(e))?x.merge(n,"string"==typeof e?[e]:e):h.call(n,e)),n},inArray:function(e,t,n){var r;if(t){if(m)return m.call(t,e,n);for(r=t.length,n=n?0>n?Math.max(0,r+n):n:0;r>n;n++)if(n in t&&t[n]===e)return n}return-1},merge:function(e,n){var r=n.length,i=e.length,o=0;if("number"==typeof r)for(;r>o;o++)e[i++]=n[o];else while(n[o]!==t)e[i++]=n[o++];return e.length=i,e},grep:function(e,t,n){var r,i=[],o=0,a=e.length;for(n=!!n;a>o;o++)r=!!t(e[o],o),n!==r&&i.push(e[o]);return i},map:function(e,t,n){var r,i=0,o=e.length,a=M(e),s=[];if(a)for(;o>i;i++)r=t(e[i],i,n),null!=r&&(s[s.length]=r);else for(i in e)r=t(e[i],i,n),null!=r&&(s[s.length]=r);return d.apply([],s)},guid:1,proxy:function(e,n){var r,i,o;return"string"==typeof n&&(o=e[n],n=e,e=o),x.isFunction(e)?(r=g.call(arguments,2),i=function(){return e.apply(n||this,r.concat(g.call(arguments)))},i.guid=e.guid=e.guid||x.guid++,i):t},access:function(e,n,r,i,o,a,s){var l=0,u=e.length,c=null==r;if("object"===x.type(r)){o=!0;for(l in r)x.access(e,n,l,r[l],!0,a,s)}else if(i!==t&&(o=!0,x.isFunction(i)||(s=!0),c&&(s?(n.call(e,i),n=null):(c=n,n=function(e,t,n){return c.call(x(e),n)})),n))for(;u>l;l++)n(e[l],r,s?i:i.call(e[l],l,n(e[l],r)));return o?e:c?n.call(e):u?n(e[0],r):a},now:function(){return(new Date).getTime()},swap:function(e,t,n,r){var i,o,a={};for(o in t)a[o]=e.style[o],e.style[o]=t[o];i=n.apply(e,r||[]);for(o in t)e.style[o]=a[o];return i}}),x.ready.promise=function(t){if(!n)if(n=x.Deferred(),"complete"===a.readyState)setTimeout(x.ready);else if(a.addEventListener)a.addEventListener("DOMContentLoaded",q,!1),e.addEventListener("load",q,!1);else{a.attachEvent("onreadystatechange",q),e.attachEvent("onload",q);var r=!1;try{r=null==e.frameElement&&a.documentElement}catch(i){}r&&r.doScroll&&function o(){if(!x.isReady){try{r.doScroll("left")}catch(e){return setTimeout(o,50)}_(),x.ready()}}()}return n.promise(t)},x.each("Boolean Number String Function Array Date RegExp Object Error".split(" "),function(e,t){c["[object "+t+"]"]=t.toLowerCase()});function M(e){var t=e.length,n=x.type(e);return x.isWindow(e)?!1:1===e.nodeType&&t?!0:"array"===n||"function"!==n&&(0===t||"number"==typeof t&&t>0&&t-1 in e)}r=x(a),function(e,t){var n,r,i,o,a,s,l,u,c,p,f,d,h,g,m,y,v,b="sizzle"+-new Date,w=e.document,T=0,C=0,N=st(),k=st(),E=st(),S=!1,A=function(e,t){return e===t?(S=!0,0):0},j=typeof t,D=1<<31,L={}.hasOwnProperty,H=[],q=H.pop,_=H.push,M=H.push,O=H.slice,F=H.indexOf||function(e){var t=0,n=this.length;for(;n>t;t++)if(this[t]===e)return t;return-1},B="checked|selected|async|autofocus|autoplay|controls|defer|disabled|hidden|ismap|loop|multiple|open|readonly|required|scoped",P="[\\\\x20\\\\t\\\\r\\\\n\\\\f]",R="(?:\\\\\\\\.|[\\\\w-]|[^\\\\x00-\\\\xa0])+",W=R.replace("w","w#"),$="\\\\["+P+"*("+R+")"+P+"*(?:([*^$|!~]?=)"+P+"*(?:([\'\\"])((?:\\\\\\\\.|[^\\\\\\\\])*?)\\\\3|("+W+")|)|)"+P+"*\\\\]",I=":("+R+")(?:\\\\((([\'\\"])((?:\\\\\\\\.|[^\\\\\\\\])*?)\\\\3|((?:\\\\\\\\.|[^\\\\\\\\()[\\\\]]|"+$.replace(3,8)+")*)|.*)\\\\)|)",z=RegExp("^"+P+"+|((?:^|[^\\\\\\\\])(?:\\\\\\\\.)*)"+P+"+$","g"),X=RegExp("^"+P+"*,"+P+"*"),U=RegExp("^"+P+"*([>+~]|"+P+")"+P+"*"),V=RegExp(P+"*[+~]"),Y=RegExp("="+P+"*([^\\\\]\'\\"]*)"+P+"*\\\\]","g"),J=RegExp(I),G=RegExp("^"+W+"$"),Q={ID:RegExp("^#("+R+")"),CLASS:RegExp("^\\\\.("+R+")"),TAG:RegExp("^("+R.replace("w","w*")+")"),ATTR:RegExp("^"+$),PSEUDO:RegExp("^"+I),CHILD:RegExp("^:(only|first|last|nth|nth-last)-(child|of-type)(?:\\\\("+P+"*(even|odd|(([+-]|)(\\\\d*)n|)"+P+"*(?:([+-]|)"+P+"*(\\\\d+)|))"+P+"*\\\\)|)","i"),bool:RegExp("^(?:"+B+")$","i"),needsContext:RegExp("^"+P+"*[>+~]|:(even|odd|eq|gt|lt|nth|first|last)(?:\\\\("+P+"*((?:-\\\\d)?\\\\d*)"+P+"*\\\\)|)(?=[^-]|$)","i")},K=/^[^{]+\\{\\s*\\[native \\w/,Z=/^(?:#([\\w-]+)|(\\w+)|\\.([\\w-]+))$/,et=/^(?:input|select|textarea|button)$/i,tt=/^h\\d$/i,nt=/\'|\\\\/g,rt=RegExp("\\\\\\\\([\\\\da-f]{1,6}"+P+"?|("+P+")|.)","ig"),it=function(e,t,n){var r="0x"+t-65536;return r!==r||n?t:0>r?String.fromCharCode(r+65536):String.fromCharCode(55296|r>>10,56320|1023&r)};try{M.apply(H=O.call(w.childNodes),w.childNodes),H[w.childNodes.length].nodeType}catch(ot){M={apply:H.length?function(e,t){_.apply(e,O.call(t))}:function(e,t){var n=e.length,r=0;while(e[n++]=t[r++]);e.length=n-1}}}function at(e,t,n,i){var o,a,s,l,u,c,d,m,y,x;if((t?t.ownerDocument||t:w)!==f&&p(t),t=t||f,n=n||[],!e||"string"!=typeof e)return n;if(1!==(l=t.nodeType)&&9!==l)return[];if(h&&!i){if(o=Z.exec(e))if(s=o[1]){if(9===l){if(a=t.getElementById(s),!a||!a.parentNode)return n;if(a.id===s)return n.push(a),n}else if(t.ownerDocument&&(a=t.ownerDocument.getElementById(s))&&v(t,a)&&a.id===s)return n.push(a),n}else{if(o[2])return M.apply(n,t.getElementsByTagName(e)),n;if((s=o[3])&&r.getElementsByClassName&&t.getElementsByClassName)return M.apply(n,t.getElementsByClassName(s)),n}if(r.qsa&&(!g||!g.test(e))){if(m=d=b,y=t,x=9===l&&e,1===l&&"object"!==t.nodeName.toLowerCase()){c=mt(e),(d=t.getAttribute("id"))?m=d.replace(nt,"\\\\$&"):t.setAttribute("id",m),m="[id=\'"+m+"\'] ",u=c.length;while(u--)c[u]=m+yt(c[u]);y=V.test(e)&&t.parentNode||t,x=c.join(",")}if(x)try{return M.apply(n,y.querySelectorAll(x)),n}catch(T){}finally{d||t.removeAttribute("id")}}}return kt(e.replace(z,"$1"),t,n,i)}function st(){var e=[];function t(n,r){return e.push(n+=" ")>o.cacheLength&&delete t[e.shift()],t[n]=r}return t}function lt(e){return e[b]=!0,e}function ut(e){var t=f.createElement("div");try{return!!e(t)}catch(n){return!1}finally{t.parentNode&&t.parentNode.removeChild(t),t=null}}function ct(e,t){var n=e.split("|"),r=e.length;while(r--)o.attrHandle[n[r]]=t}function pt(e,t){var n=t&&e,r=n&&1===e.nodeType&&1===t.nodeType&&(~t.sourceIndex||D)-(~e.sourceIndex||D);if(r)return r;if(n)while(n=n.nextSibling)if(n===t)return-1;return e?1:-1}function ft(e){return function(t){var n=t.nodeName.toLowerCase();return"input"===n&&t.type===e}}function dt(e){return function(t){var n=t.nodeName.toLowerCase();return("input"===n||"button"===n)&&t.type===e}}function ht(e){return lt(function(t){return t=+t,lt(function(n,r){var i,o=e([],n.length,t),a=o.length;while(a--)n[i=o[a]]&&(n[i]=!(r[i]=n[i]))})})}s=at.isXML=function(e){var t=e&&(e.ownerDocument||e).documentElement;return t?"HTML"!==t.nodeName:!1},r=at.support={},p=at.setDocument=function(e){var n=e?e.ownerDocument||e:w,i=n.defaultView;return n!==f&&9===n.nodeType&&n.documentElement?(f=n,d=n.documentElement,h=!s(n),i&&i.attachEvent&&i!==i.top&&i.attachEvent("onbeforeunload",function(){p()}),r.attributes=ut(function(e){return e.className="i",!e.getAttribute("className")}),r.getElementsByTagName=ut(function(e){return e.appendChild(n.createComment("")),!e.getElementsByTagName("*").length}),r.getElementsByClassName=ut(function(e){return e.innerHTML="<div class=\'a\'></div><div class=\'a i\'></div>",e.firstChild.className="i",2===e.getElementsByClassName("i").length}),r.getById=ut(function(e){return d.appendChild(e).id=b,!n.getElementsByName||!n.getElementsByName(b).length}),r.getById?(o.find.ID=function(e,t){if(typeof t.getElementById!==j&&h){var n=t.getElementById(e);return n&&n.parentNode?[n]:[]}},o.filter.ID=function(e){var t=e.replace(rt,it);return function(e){return e.getAttribute("id")===t}}):(delete o.find.ID,o.filter.ID=function(e){var t=e.replace(rt,it);return function(e){var n=typeof e.getAttributeNode!==j&&e.getAttributeNode("id");return n&&n.value===t}}),o.find.TAG=r.getElementsByTagName?function(e,n){return typeof n.getElementsByTagName!==j?n.getElementsByTagName(e):t}:function(e,t){var n,r=[],i=0,o=t.getElementsByTagName(e);if("*"===e){while(n=o[i++])1===n.nodeType&&r.push(n);return r}return o},o.find.CLASS=r.getElementsByClassName&&function(e,n){return typeof n.getElementsByClassName!==j&&h?n.getElementsByClassName(e):t},m=[],g=[],(r.qsa=K.test(n.querySelectorAll))&&(ut(function(e){e.innerHTML="<select><option selected=\'\'></option></select>",e.querySelectorAll("[selected]").length||g.push("\\\\["+P+"*(?:value|"+B+")"),e.querySelectorAll(":checked").length||g.push(":checked")}),ut(function(e){var t=n.createElement("input");t.setAttribute("type","hidden"),e.appendChild(t).setAttribute("t",""),e.querySelectorAll("[t^=\'\']").length&&g.push("[*^$]="+P+"*(?:\'\'|\\"\\")"),e.querySelectorAll(":enabled").length||g.push(":enabled",":disabled"),e.querySelectorAll("*,:x"),g.push(",.*:")})),(r.matchesSelector=K.test(y=d.webkitMatchesSelector||d.mozMatchesSelector||d.oMatchesSelector||d.msMatchesSelector))&&ut(function(e){r.disconnectedMatch=y.call(e,"div"),y.call(e,"[s!=\'\']:x"),m.push("!=",I)}),g=g.length&&RegExp(g.join("|")),m=m.length&&RegExp(m.join("|")),v=K.test(d.contains)||d.compareDocumentPosition?function(e,t){var n=9===e.nodeType?e.documentElement:e,r=t&&t.parentNode;return e===r||!(!r||1!==r.nodeType||!(n.contains?n.contains(r):e.compareDocumentPosition&&16&e.compareDocumentPosition(r)))}:function(e,t){if(t)while(t=t.parentNode)if(t===e)return!0;return!1},A=d.compareDocumentPosition?function(e,t){if(e===t)return S=!0,0;var i=t.compareDocumentPosition&&e.compareDocumentPosition&&e.compareDocumentPosition(t);return i?1&i||!r.sortDetached&&t.compareDocumentPosition(e)===i?e===n||v(w,e)?-1:t===n||v(w,t)?1:c?F.call(c,e)-F.call(c,t):0:4&i?-1:1:e.compareDocumentPosition?-1:1}:function(e,t){var r,i=0,o=e.parentNode,a=t.parentNode,s=[e],l=[t];if(e===t)return S=!0,0;if(!o||!a)return e===n?-1:t===n?1:o?-1:a?1:c?F.call(c,e)-F.call(c,t):0;if(o===a)return pt(e,t);r=e;while(r=r.parentNode)s.unshift(r);r=t;while(r=r.parentNode)l.unshift(r);while(s[i]===l[i])i++;return i?pt(s[i],l[i]):s[i]===w?-1:l[i]===w?1:0},n):f},at.matches=function(e,t){return at(e,null,null,t)},at.matchesSelector=function(e,t){if((e.ownerDocument||e)!==f&&p(e),t=t.replace(Y,"=\'$1\']"),!(!r.matchesSelector||!h||m&&m.test(t)||g&&g.test(t)))try{var n=y.call(e,t);if(n||r.disconnectedMatch||e.document&&11!==e.document.nodeType)return n}catch(i){}return at(t,f,null,[e]).length>0},at.contains=function(e,t){return(e.ownerDocument||e)!==f&&p(e),v(e,t)},at.attr=function(e,n){(e.ownerDocument||e)!==f&&p(e);var i=o.attrHandle[n.toLowerCase()],a=i&&L.call(o.attrHandle,n.toLowerCase())?i(e,n,!h):t;return a===t?r.attributes||!h?e.getAttribute(n):(a=e.getAttributeNode(n))&&a.specified?a.value:null:a},at.error=function(e){throw Error("Syntax error, unrecognized expression: "+e)},at.uniqueSort=function(e){var t,n=[],i=0,o=0;if(S=!r.detectDuplicates,c=!r.sortStable&&e.slice(0),e.sort(A),S){while(t=e[o++])t===e[o]&&(i=n.push(o));while(i--)e.splice(n[i],1)}return e},a=at.getText=function(e){var t,n="",r=0,i=e.nodeType;if(i){if(1===i||9===i||11===i){if("string"==typeof e.textContent)return e.textContent;for(e=e.firstChild;e;e=e.nextSibling)n+=a(e)}else if(3===i||4===i)return e.nodeValue}else for(;t=e[r];r++)n+=a(t);return n},o=at.selectors={cacheLength:50,createPseudo:lt,match:Q,attrHandle:{},find:{},relative:{">":{dir:"parentNode",first:!0}," ":{dir:"parentNode"},"+":{dir:"previousSibling",first:!0},"~":{dir:"previousSibling"}},preFilter:{ATTR:function(e){return e[1]=e[1].replace(rt,it),e[3]=(e[4]||e[5]||"").replace(rt,it),"~="===e[2]&&(e[3]=" "+e[3]+" "),e.slice(0,4)},CHILD:function(e){return e[1]=e[1].toLowerCase(),"nth"===e[1].slice(0,3)?(e[3]||at.error(e[0]),e[4]=+(e[4]?e[5]+(e[6]||1):2*("even"===e[3]||"odd"===e[3])),e[5]=+(e[7]+e[8]||"odd"===e[3])):e[3]&&at.error(e[0]),e},PSEUDO:function(e){var n,r=!e[5]&&e[2];return Q.CHILD.test(e[0])?null:(e[3]&&e[4]!==t?e[2]=e[4]:r&&J.test(r)&&(n=mt(r,!0))&&(n=r.indexOf(")",r.length-n)-r.length)&&(e[0]=e[0].slice(0,n),e[2]=r.slice(0,n)),e.slice(0,3))}},filter:{TAG:function(e){var t=e.replace(rt,it).toLowerCase();return"*"===e?function(){return!0}:function(e){return e.nodeName&&e.nodeName.toLowerCase()===t}},CLASS:function(e){var t=N[e+" "];return t||(t=RegExp("(^|"+P+")"+e+"("+P+"|$)"))&&N(e,function(e){return t.test("string"==typeof e.className&&e.className||typeof e.getAttribute!==j&&e.getAttribute("class")||"")})},ATTR:function(e,t,n){return function(r){var i=at.attr(r,e);return null==i?"!="===t:t?(i+="","="===t?i===n:"!="===t?i!==n:"^="===t?n&&0===i.indexOf(n):"*="===t?n&&i.indexOf(n)>-1:"$="===t?n&&i.slice(-n.length)===n:"~="===t?(" "+i+" ").indexOf(n)>-1:"|="===t?i===n||i.slice(0,n.length+1)===n+"-":!1):!0}},CHILD:function(e,t,n,r,i){var o="nth"!==e.slice(0,3),a="last"!==e.slice(-4),s="of-type"===t;return 1===r&&0===i?function(e){return!!e.parentNode}:function(t,n,l){var u,c,p,f,d,h,g=o!==a?"nextSibling":"previousSibling",m=t.parentNode,y=s&&t.nodeName.toLowerCase(),v=!l&&!s;if(m){if(o){while(g){p=t;while(p=p[g])if(s?p.nodeName.toLowerCase()===y:1===p.nodeType)return!1;h=g="only"===e&&!h&&"nextSibling"}return!0}if(h=[a?m.firstChild:m.lastChild],a&&v){c=m[b]||(m[b]={}),u=c[e]||[],d=u[0]===T&&u[1],f=u[0]===T&&u[2],p=d&&m.childNodes[d];while(p=++d&&p&&p[g]||(f=d=0)||h.pop())if(1===p.nodeType&&++f&&p===t){c[e]=[T,d,f];break}}else if(v&&(u=(t[b]||(t[b]={}))[e])&&u[0]===T)f=u[1];else while(p=++d&&p&&p[g]||(f=d=0)||h.pop())if((s?p.nodeName.toLowerCase()===y:1===p.nodeType)&&++f&&(v&&((p[b]||(p[b]={}))[e]=[T,f]),p===t))break;return f-=i,f===r||0===f%r&&f/r>=0}}},PSEUDO:function(e,t){var n,r=o.pseudos[e]||o.setFilters[e.toLowerCase()]||at.error("unsupported pseudo: "+e);return r[b]?r(t):r.length>1?(n=[e,e,"",t],o.setFilters.hasOwnProperty(e.toLowerCase())?lt(function(e,n){var i,o=r(e,t),a=o.length;while(a--)i=F.call(e,o[a]),e[i]=!(n[i]=o[a])}):function(e){return r(e,0,n)}):r}},pseudos:{not:lt(function(e){var t=[],n=[],r=l(e.replace(z,"$1"));return r[b]?lt(function(e,t,n,i){var o,a=r(e,null,i,[]),s=e.length;while(s--)(o=a[s])&&(e[s]=!(t[s]=o))}):function(e,i,o){return t[0]=e,r(t,null,o,n),!n.pop()}}),has:lt(function(e){return function(t){return at(e,t).length>0}}),contains:lt(function(e){return function(t){return(t.textContent||t.innerText||a(t)).indexOf(e)>-1}}),lang:lt(function(e){return G.test(e||"")||at.error("unsupported lang: "+e),e=e.replace(rt,it).toLowerCase(),function(t){var n;do if(n=h?t.lang:t.getAttribute("xml:lang")||t.getAttribute("lang"))return n=n.toLowerCase(),n===e||0===n.indexOf(e+"-");while((t=t.parentNode)&&1===t.nodeType);return!1}}),target:function(t){var n=e.location&&e.location.hash;return n&&n.slice(1)===t.id},root:function(e){return e===d},focus:function(e){return e===f.activeElement&&(!f.hasFocus||f.hasFocus())&&!!(e.type||e.href||~e.tabIndex)},enabled:function(e){return e.disabled===!1},disabled:function(e){return e.disabled===!0},checked:function(e){var t=e.nodeName.toLowerCase();return"input"===t&&!!e.checked||"option"===t&&!!e.selected},selected:function(e){return e.parentNode&&e.parentNode.selectedIndex,e.selected===!0},empty:function(e){for(e=e.firstChild;e;e=e.nextSibling)if(e.nodeName>"@"||3===e.nodeType||4===e.nodeType)return!1;return!0},parent:function(e){return!o.pseudos.empty(e)},header:function(e){return tt.test(e.nodeName)},input:function(e){return et.test(e.nodeName)},button:function(e){var t=e.nodeName.toLowerCase();return"input"===t&&"button"===e.type||"button"===t},text:function(e){var t;return"input"===e.nodeName.toLowerCase()&&"text"===e.type&&(null==(t=e.getAttribute("type"))||t.toLowerCase()===e.type)},first:ht(function(){return[0]}),last:ht(function(e,t){return[t-1]}),eq:ht(function(e,t,n){return[0>n?n+t:n]}),even:ht(function(e,t){var n=0;for(;t>n;n+=2)e.push(n);return e}),odd:ht(function(e,t){var n=1;for(;t>n;n+=2)e.push(n);return e}),lt:ht(function(e,t,n){var r=0>n?n+t:n;for(;--r>=0;)e.push(r);return e}),gt:ht(function(e,t,n){var r=0>n?n+t:n;for(;t>++r;)e.push(r);return e})}},o.pseudos.nth=o.pseudos.eq;for(n in{radio:!0,checkbox:!0,file:!0,password:!0,image:!0})o.pseudos[n]=ft(n);for(n in{submit:!0,reset:!0})o.pseudos[n]=dt(n);function gt(){}gt.prototype=o.filters=o.pseudos,o.setFilters=new gt;function mt(e,t){var n,r,i,a,s,l,u,c=k[e+" "];if(c)return t?0:c.slice(0);s=e,l=[],u=o.preFilter;while(s){(!n||(r=X.exec(s)))&&(r&&(s=s.slice(r[0].length)||s),l.push(i=[])),n=!1,(r=U.exec(s))&&(n=r.shift(),i.push({value:n,type:r[0].replace(z," ")}),s=s.slice(n.length));for(a in o.filter)!(r=Q[a].exec(s))||u[a]&&!(r=u[a](r))||(n=r.shift(),i.push({value:n,type:a,matches:r}),s=s.slice(n.length));if(!n)break}return t?s.length:s?at.error(e):k(e,l).slice(0)}function yt(e){var t=0,n=e.length,r="";for(;n>t;t++)r+=e[t].value;return r}function vt(e,t,n){var r=t.dir,o=n&&"parentNode"===r,a=C++;return t.first?function(t,n,i){while(t=t[r])if(1===t.nodeType||o)return e(t,n,i)}:function(t,n,s){var l,u,c,p=T+" "+a;if(s){while(t=t[r])if((1===t.nodeType||o)&&e(t,n,s))return!0}else while(t=t[r])if(1===t.nodeType||o)if(c=t[b]||(t[b]={}),(u=c[r])&&u[0]===p){if((l=u[1])===!0||l===i)return l===!0}else if(u=c[r]=[p],u[1]=e(t,n,s)||i,u[1]===!0)return!0}}function bt(e){return e.length>1?function(t,n,r){var i=e.length;while(i--)if(!e[i](t,n,r))return!1;return!0}:e[0]}function xt(e,t,n,r,i){var o,a=[],s=0,l=e.length,u=null!=t;for(;l>s;s++)(o=e[s])&&(!n||n(o,r,i))&&(a.push(o),u&&t.push(s));return a}function wt(e,t,n,r,i,o){return r&&!r[b]&&(r=wt(r)),i&&!i[b]&&(i=wt(i,o)),lt(function(o,a,s,l){var u,c,p,f=[],d=[],h=a.length,g=o||Nt(t||"*",s.nodeType?[s]:s,[]),m=!e||!o&&t?g:xt(g,f,e,s,l),y=n?i||(o?e:h||r)?[]:a:m;if(n&&n(m,y,s,l),r){u=xt(y,d),r(u,[],s,l),c=u.length;while(c--)(p=u[c])&&(y[d[c]]=!(m[d[c]]=p))}if(o){if(i||e){if(i){u=[],c=y.length;while(c--)(p=y[c])&&u.push(m[c]=p);i(null,y=[],u,l)}c=y.length;while(c--)(p=y[c])&&(u=i?F.call(o,p):f[c])>-1&&(o[u]=!(a[u]=p))}}else y=xt(y===a?y.splice(h,y.length):y),i?i(null,a,y,l):M.apply(a,y)})}function Tt(e){var t,n,r,i=e.length,a=o.relative[e[0].type],s=a||o.relative[" "],l=a?1:0,c=vt(function(e){return e===t},s,!0),p=vt(function(e){return F.call(t,e)>-1},s,!0),f=[function(e,n,r){return!a&&(r||n!==u)||((t=n).nodeType?c(e,n,r):p(e,n,r))}];for(;i>l;l++)if(n=o.relative[e[l].type])f=[vt(bt(f),n)];else{if(n=o.filter[e[l].type].apply(null,e[l].matches),n[b]){for(r=++l;i>r;r++)if(o.relative[e[r].type])break;return wt(l>1&&bt(f),l>1&&yt(e.slice(0,l-1).concat({value:" "===e[l-2].type?"*":""})).replace(z,"$1"),n,r>l&&Tt(e.slice(l,r)),i>r&&Tt(e=e.slice(r)),i>r&&yt(e))}f.push(n)}return bt(f)}function Ct(e,t){var n=0,r=t.length>0,a=e.length>0,s=function(s,l,c,p,d){var h,g,m,y=[],v=0,b="0",x=s&&[],w=null!=d,C=u,N=s||a&&o.find.TAG("*",d&&l.parentNode||l),k=T+=null==C?1:Math.random()||.1;for(w&&(u=l!==f&&l,i=n);null!=(h=N[b]);b++){if(a&&h){g=0;while(m=e[g++])if(m(h,l,c)){p.push(h);break}w&&(T=k,i=++n)}r&&((h=!m&&h)&&v--,s&&x.push(h))}if(v+=b,r&&b!==v){g=0;while(m=t[g++])m(x,y,l,c);if(s){if(v>0)while(b--)x[b]||y[b]||(y[b]=q.call(p));y=xt(y)}M.apply(p,y),w&&!s&&y.length>0&&v+t.length>1&&at.uniqueSort(p)}return w&&(T=k,u=C),x};return r?lt(s):s}l=at.compile=function(e,t){var n,r=[],i=[],o=E[e+" "];if(!o){t||(t=mt(e)),n=t.length;while(n--)o=Tt(t[n]),o[b]?r.push(o):i.push(o);o=E(e,Ct(i,r))}return o};function Nt(e,t,n){var r=0,i=t.length;for(;i>r;r++)at(e,t[r],n);return n}function kt(e,t,n,i){var a,s,u,c,p,f=mt(e);if(!i&&1===f.length){if(s=f[0]=f[0].slice(0),s.length>2&&"ID"===(u=s[0]).type&&r.getById&&9===t.nodeType&&h&&o.relative[s[1].type]){if(t=(o.find.ID(u.matches[0].replace(rt,it),t)||[])[0],!t)return n;e=e.slice(s.shift().value.length)}a=Q.needsContext.test(e)?0:s.length;while(a--){if(u=s[a],o.relative[c=u.type])break;if((p=o.find[c])&&(i=p(u.matches[0].replace(rt,it),V.test(s[0].type)&&t.parentNode||t))){if(s.splice(a,1),e=i.length&&yt(s),!e)return M.apply(n,i),n;break}}}return l(e,f)(i,t,!h,n,V.test(e)),n}r.sortStable=b.split("").sort(A).join("")===b,r.detectDuplicates=S,p(),r.sortDetached=ut(function(e){return 1&e.compareDocumentPosition(f.createElement("div"))}),ut(function(e){return e.innerHTML="<a href=\'#\'></a>","#"===e.firstChild.getAttribute("href")})||ct("type|href|height|width",function(e,n,r){return r?t:e.getAttribute(n,"type"===n.toLowerCase()?1:2)}),r.attributes&&ut(function(e){return e.innerHTML="<input/>",e.firstChild.setAttribute("value",""),""===e.firstChild.getAttribute("value")})||ct("value",function(e,n,r){return r||"input"!==e.nodeName.toLowerCase()?t:e.defaultValue}),ut(function(e){return null==e.getAttribute("disabled")})||ct(B,function(e,n,r){var i;return r?t:(i=e.getAttributeNode(n))&&i.specified?i.value:e[n]===!0?n.toLowerCase():null}),x.find=at,x.expr=at.selectors,x.expr[":"]=x.expr.pseudos,x.unique=at.uniqueSort,x.text=at.getText,x.isXMLDoc=at.isXML,x.contains=at.contains}(e);var O={};function F(e){var t=O[e]={};return x.each(e.match(T)||[],function(e,n){t[n]=!0}),t}x.Callbacks=function(e){e="string"==typeof e?O[e]||F(e):x.extend({},e);var n,r,i,o,a,s,l=[],u=!e.once&&[],c=function(t){for(r=e.memory&&t,i=!0,a=s||0,s=0,o=l.length,n=!0;l&&o>a;a++)if(l[a].apply(t[0],t[1])===!1&&e.stopOnFalse){r=!1;break}n=!1,l&&(u?u.length&&c(u.shift()):r?l=[]:p.disable())},p={add:function(){if(l){var t=l.length;(function i(t){x.each(t,function(t,n){var r=x.type(n);"function"===r?e.unique&&p.has(n)||l.push(n):n&&n.length&&"string"!==r&&i(n)})})(arguments),n?o=l.length:r&&(s=t,c(r))}return this},remove:function(){return l&&x.each(arguments,function(e,t){var r;while((r=x.inArray(t,l,r))>-1)l.splice(r,1),n&&(o>=r&&o--,a>=r&&a--)}),this},has:function(e){return e?x.inArray(e,l)>-1:!(!l||!l.length)},empty:function(){return l=[],o=0,this},disable:function(){return l=u=r=t,this},disabled:function(){return!l},lock:function(){return u=t,r||p.disable(),this},locked:function(){return!u},fireWith:function(e,t){return!l||i&&!u||(t=t||[],t=[e,t.slice?t.slice():t],n?u.push(t):c(t)),this},fire:function(){return p.fireWith(this,arguments),this},fired:function(){return!!i}};return p},x.extend({Deferred:function(e){var t=[["resolve","done",x.Callbacks("once memory"),"resolved"],["reject","fail",x.Callbacks("once memory"),"rejected"],["notify","progress",x.Callbacks("memory")]],n="pending",r={state:function(){return n},always:function(){return i.done(arguments).fail(arguments),this},then:function(){var e=arguments;return x.Deferred(function(n){x.each(t,function(t,o){var a=o[0],s=x.isFunction(e[t])&&e[t];i[o[1]](function(){var e=s&&s.apply(this,arguments);e&&x.isFunction(e.promise)?e.promise().done(n.resolve).fail(n.reject).progress(n.notify):n[a+"With"](this===r?n.promise():this,s?[e]:arguments)})}),e=null}).promise()},promise:function(e){return null!=e?x.extend(e,r):r}},i={};return r.pipe=r.then,x.each(t,function(e,o){var a=o[2],s=o[3];r[o[1]]=a.add,s&&a.add(function(){n=s},t[1^e][2].disable,t[2][2].lock),i[o[0]]=function(){return i[o[0]+"With"](this===i?r:this,arguments),this},i[o[0]+"With"]=a.fireWith}),r.promise(i),e&&e.call(i,i),i},when:function(e){var t=0,n=g.call(arguments),r=n.length,i=1!==r||e&&x.isFunction(e.promise)?r:0,o=1===i?e:x.Deferred(),a=function(e,t,n){return function(r){t[e]=this,n[e]=arguments.length>1?g.call(arguments):r,n===s?o.notifyWith(t,n):--i||o.resolveWith(t,n)}},s,l,u;if(r>1)for(s=Array(r),l=Array(r),u=Array(r);r>t;t++)n[t]&&x.isFunction(n[t].promise)?n[t].promise().done(a(t,u,n)).fail(o.reject).progress(a(t,l,s)):--i;return i||o.resolveWith(u,n),o.promise()}}),x.support=function(t){var n,r,o,s,l,u,c,p,f,d=a.createElement("div");if(d.setAttribute("className","t"),d.innerHTML="  <link/><table></table><a href=\'/a\'>a</a><input type=\'checkbox\'/>",n=d.getElementsByTagName("*")||[],r=d.getElementsByTagName("a")[0],!r||!r.style||!n.length)return t;s=a.createElement("select"),u=s.appendChild(a.createElement("option")),o=d.getElementsByTagName("input")[0],r.style.cssText="top:1px;float:left;opacity:.5",t.getSetAttribute="t"!==d.className,t.leadingWhitespace=3===d.firstChild.nodeType,t.tbody=!d.getElementsByTagName("tbody").length,t.htmlSerialize=!!d.getElementsByTagName("link").length,t.style=/top/.test(r.getAttribute("style")),t.hrefNormalized="/a"===r.getAttribute("href"),t.opacity=/^0.5/.test(r.style.opacity),t.cssFloat=!!r.style.cssFloat,t.checkOn=!!o.value,t.optSelected=u.selected,t.enctype=!!a.createElement("form").enctype,t.html5Clone="<:nav></:nav>"!==a.createElement("nav").cloneNode(!0).outerHTML,t.inlineBlockNeedsLayout=!1,t.shrinkWrapBlocks=!1,t.pixelPosition=!1,t.deleteExpando=!0,t.noCloneEvent=!0,t.reliableMarginRight=!0,t.boxSizingReliable=!0,o.checked=!0,t.noCloneChecked=o.cloneNode(!0).checked,s.disabled=!0,t.optDisabled=!u.disabled;try{delete d.test}catch(h){t.deleteExpando=!1}o=a.createElement("input"),o.setAttribute("value",""),t.input=""===o.getAttribute("value"),o.value="t",o.setAttribute("type","radio"),t.radioValue="t"===o.value,o.setAttribute("checked","t"),o.setAttribute("name","t"),l=a.createDocumentFragment(),l.appendChild(o),t.appendChecked=o.checked,t.checkClone=l.cloneNode(!0).cloneNode(!0).lastChild.checked,d.attachEvent&&(d.attachEvent("onclick",function(){t.noCloneEvent=!1}),d.cloneNode(!0).click());for(f in{submit:!0,change:!0,focusin:!0})d.setAttribute(c="on"+f,"t"),t[f+"Bubbles"]=c in e||d.attributes[c].expando===!1;d.style.backgroundClip="content-box",d.cloneNode(!0).style.backgroundClip="",t.clearCloneStyle="content-box"===d.style.backgroundClip;for(f in x(t))break;return t.ownLast="0"!==f,x(function(){var n,r,o,s="padding:0;margin:0;border:0;display:block;box-sizing:content-box;-moz-box-sizing:content-box;-webkit-box-sizing:content-box;",l=a.getElementsByTagName("body")[0];l&&(n=a.createElement("div"),n.style.cssText="border:0;width:0;height:0;position:absolute;top:0;left:-9999px;margin-top:1px",l.appendChild(n).appendChild(d),d.innerHTML="<table><tr><td></td><td>t</td></tr></table>",o=d.getElementsByTagName("td"),o[0].style.cssText="padding:0;margin:0;border:0;display:none",p=0===o[0].offsetHeight,o[0].style.display="",o[1].style.display="none",t.reliableHiddenOffsets=p&&0===o[0].offsetHeight,d.innerHTML="",d.style.cssText="box-sizing:border-box;-moz-box-sizing:border-box;-webkit-box-sizing:border-box;padding:1px;border:1px;display:block;width:4px;margin-top:1%;position:absolute;top:1%;",x.swap(l,null!=l.style.zoom?{zoom:1}:{},function(){t.boxSizing=4===d.offsetWidth}),e.getComputedStyle&&(t.pixelPosition="1%"!==(e.getComputedStyle(d,null)||{}).top,t.boxSizingReliable="4px"===(e.getComputedStyle(d,null)||{width:"4px"}).width,r=d.appendChild(a.createElement("div")),r.style.cssText=d.style.cssText=s,r.style.marginRight=r.style.width="0",d.style.width="1px",t.reliableMarginRight=!parseFloat((e.getComputedStyle(r,null)||{}).marginRight)),typeof d.style.zoom!==i&&(d.innerHTML="",d.style.cssText=s+"width:1px;padding:1px;display:inline;zoom:1",t.inlineBlockNeedsLayout=3===d.offsetWidth,d.style.display="block",d.innerHTML="<div></div>",d.firstChild.style.width="5px",t.shrinkWrapBlocks=3!==d.offsetWidth,t.inlineBlockNeedsLayout&&(l.style.zoom=1)),l.removeChild(n),n=d=o=r=null)}),n=s=l=u=r=o=null,t
}({});var B=/(?:\\{[\\s\\S]*\\}|\\[[\\s\\S]*\\])$/,P=/([A-Z])/g;function R(e,n,r,i){if(x.acceptData(e)){var o,a,s=x.expando,l=e.nodeType,u=l?x.cache:e,c=l?e[s]:e[s]&&s;if(c&&u[c]&&(i||u[c].data)||r!==t||"string"!=typeof n)return c||(c=l?e[s]=p.pop()||x.guid++:s),u[c]||(u[c]=l?{}:{toJSON:x.noop}),("object"==typeof n||"function"==typeof n)&&(i?u[c]=x.extend(u[c],n):u[c].data=x.extend(u[c].data,n)),a=u[c],i||(a.data||(a.data={}),a=a.data),r!==t&&(a[x.camelCase(n)]=r),"string"==typeof n?(o=a[n],null==o&&(o=a[x.camelCase(n)])):o=a,o}}function W(e,t,n){if(x.acceptData(e)){var r,i,o=e.nodeType,a=o?x.cache:e,s=o?e[x.expando]:x.expando;if(a[s]){if(t&&(r=n?a[s]:a[s].data)){x.isArray(t)?t=t.concat(x.map(t,x.camelCase)):t in r?t=[t]:(t=x.camelCase(t),t=t in r?[t]:t.split(" ")),i=t.length;while(i--)delete r[t[i]];if(n?!I(r):!x.isEmptyObject(r))return}(n||(delete a[s].data,I(a[s])))&&(o?x.cleanData([e],!0):x.support.deleteExpando||a!=a.window?delete a[s]:a[s]=null)}}}x.extend({cache:{},noData:{applet:!0,embed:!0,object:"clsid:D27CDB6E-AE6D-11cf-96B8-444553540000"},hasData:function(e){return e=e.nodeType?x.cache[e[x.expando]]:e[x.expando],!!e&&!I(e)},data:function(e,t,n){return R(e,t,n)},removeData:function(e,t){return W(e,t)},_data:function(e,t,n){return R(e,t,n,!0)},_removeData:function(e,t){return W(e,t,!0)},acceptData:function(e){if(e.nodeType&&1!==e.nodeType&&9!==e.nodeType)return!1;var t=e.nodeName&&x.noData[e.nodeName.toLowerCase()];return!t||t!==!0&&e.getAttribute("classid")===t}}),x.fn.extend({data:function(e,n){var r,i,o=null,a=0,s=this[0];if(e===t){if(this.length&&(o=x.data(s),1===s.nodeType&&!x._data(s,"parsedAttrs"))){for(r=s.attributes;r.length>a;a++)i=r[a].name,0===i.indexOf("data-")&&(i=x.camelCase(i.slice(5)),$(s,i,o[i]));x._data(s,"parsedAttrs",!0)}return o}return"object"==typeof e?this.each(function(){x.data(this,e)}):arguments.length>1?this.each(function(){x.data(this,e,n)}):s?$(s,e,x.data(s,e)):null},removeData:function(e){return this.each(function(){x.removeData(this,e)})}});function $(e,n,r){if(r===t&&1===e.nodeType){var i="data-"+n.replace(P,"-$1").toLowerCase();if(r=e.getAttribute(i),"string"==typeof r){try{r="true"===r?!0:"false"===r?!1:"null"===r?null:+r+""===r?+r:B.test(r)?x.parseJSON(r):r}catch(o){}x.data(e,n,r)}else r=t}return r}function I(e){var t;for(t in e)if(("data"!==t||!x.isEmptyObject(e[t]))&&"toJSON"!==t)return!1;return!0}x.extend({queue:function(e,n,r){var i;return e?(n=(n||"fx")+"queue",i=x._data(e,n),r&&(!i||x.isArray(r)?i=x._data(e,n,x.makeArray(r)):i.push(r)),i||[]):t},dequeue:function(e,t){t=t||"fx";var n=x.queue(e,t),r=n.length,i=n.shift(),o=x._queueHooks(e,t),a=function(){x.dequeue(e,t)};"inprogress"===i&&(i=n.shift(),r--),i&&("fx"===t&&n.unshift("inprogress"),delete o.stop,i.call(e,a,o)),!r&&o&&o.empty.fire()},_queueHooks:function(e,t){var n=t+"queueHooks";return x._data(e,n)||x._data(e,n,{empty:x.Callbacks("once memory").add(function(){x._removeData(e,t+"queue"),x._removeData(e,n)})})}}),x.fn.extend({queue:function(e,n){var r=2;return"string"!=typeof e&&(n=e,e="fx",r--),r>arguments.length?x.queue(this[0],e):n===t?this:this.each(function(){var t=x.queue(this,e,n);x._queueHooks(this,e),"fx"===e&&"inprogress"!==t[0]&&x.dequeue(this,e)})},dequeue:function(e){return this.each(function(){x.dequeue(this,e)})},delay:function(e,t){return e=x.fx?x.fx.speeds[e]||e:e,t=t||"fx",this.queue(t,function(t,n){var r=setTimeout(t,e);n.stop=function(){clearTimeout(r)}})},clearQueue:function(e){return this.queue(e||"fx",[])},promise:function(e,n){var r,i=1,o=x.Deferred(),a=this,s=this.length,l=function(){--i||o.resolveWith(a,[a])};"string"!=typeof e&&(n=e,e=t),e=e||"fx";while(s--)r=x._data(a[s],e+"queueHooks"),r&&r.empty&&(i++,r.empty.add(l));return l(),o.promise(n)}});var z,X,U=/[\\t\\r\\n\\f]/g,V=/\\r/g,Y=/^(?:input|select|textarea|button|object)$/i,J=/^(?:a|area)$/i,G=/^(?:checked|selected)$/i,Q=x.support.getSetAttribute,K=x.support.input;x.fn.extend({attr:function(e,t){return x.access(this,x.attr,e,t,arguments.length>1)},removeAttr:function(e){return this.each(function(){x.removeAttr(this,e)})},prop:function(e,t){return x.access(this,x.prop,e,t,arguments.length>1)},removeProp:function(e){return e=x.propFix[e]||e,this.each(function(){try{this[e]=t,delete this[e]}catch(n){}})},addClass:function(e){var t,n,r,i,o,a=0,s=this.length,l="string"==typeof e&&e;if(x.isFunction(e))return this.each(function(t){x(this).addClass(e.call(this,t,this.className))});if(l)for(t=(e||"").match(T)||[];s>a;a++)if(n=this[a],r=1===n.nodeType&&(n.className?(" "+n.className+" ").replace(U," "):" ")){o=0;while(i=t[o++])0>r.indexOf(" "+i+" ")&&(r+=i+" ");n.className=x.trim(r)}return this},removeClass:function(e){var t,n,r,i,o,a=0,s=this.length,l=0===arguments.length||"string"==typeof e&&e;if(x.isFunction(e))return this.each(function(t){x(this).removeClass(e.call(this,t,this.className))});if(l)for(t=(e||"").match(T)||[];s>a;a++)if(n=this[a],r=1===n.nodeType&&(n.className?(" "+n.className+" ").replace(U," "):"")){o=0;while(i=t[o++])while(r.indexOf(" "+i+" ")>=0)r=r.replace(" "+i+" "," ");n.className=e?x.trim(r):""}return this},toggleClass:function(e,t){var n=typeof e;return"boolean"==typeof t&&"string"===n?t?this.addClass(e):this.removeClass(e):x.isFunction(e)?this.each(function(n){x(this).toggleClass(e.call(this,n,this.className,t),t)}):this.each(function(){if("string"===n){var t,r=0,o=x(this),a=e.match(T)||[];while(t=a[r++])o.hasClass(t)?o.removeClass(t):o.addClass(t)}else(n===i||"boolean"===n)&&(this.className&&x._data(this,"__className__",this.className),this.className=this.className||e===!1?"":x._data(this,"__className__")||"")})},hasClass:function(e){var t=" "+e+" ",n=0,r=this.length;for(;r>n;n++)if(1===this[n].nodeType&&(" "+this[n].className+" ").replace(U," ").indexOf(t)>=0)return!0;return!1},val:function(e){var n,r,i,o=this[0];{if(arguments.length)return i=x.isFunction(e),this.each(function(n){var o;1===this.nodeType&&(o=i?e.call(this,n,x(this).val()):e,null==o?o="":"number"==typeof o?o+="":x.isArray(o)&&(o=x.map(o,function(e){return null==e?"":e+""})),r=x.valHooks[this.type]||x.valHooks[this.nodeName.toLowerCase()],r&&"set"in r&&r.set(this,o,"value")!==t||(this.value=o))});if(o)return r=x.valHooks[o.type]||x.valHooks[o.nodeName.toLowerCase()],r&&"get"in r&&(n=r.get(o,"value"))!==t?n:(n=o.value,"string"==typeof n?n.replace(V,""):null==n?"":n)}}}),x.extend({valHooks:{option:{get:function(e){var t=x.find.attr(e,"value");return null!=t?t:e.text}},select:{get:function(e){var t,n,r=e.options,i=e.selectedIndex,o="select-one"===e.type||0>i,a=o?null:[],s=o?i+1:r.length,l=0>i?s:o?i:0;for(;s>l;l++)if(n=r[l],!(!n.selected&&l!==i||(x.support.optDisabled?n.disabled:null!==n.getAttribute("disabled"))||n.parentNode.disabled&&x.nodeName(n.parentNode,"optgroup"))){if(t=x(n).val(),o)return t;a.push(t)}return a},set:function(e,t){var n,r,i=e.options,o=x.makeArray(t),a=i.length;while(a--)r=i[a],(r.selected=x.inArray(x(r).val(),o)>=0)&&(n=!0);return n||(e.selectedIndex=-1),o}}},attr:function(e,n,r){var o,a,s=e.nodeType;if(e&&3!==s&&8!==s&&2!==s)return typeof e.getAttribute===i?x.prop(e,n,r):(1===s&&x.isXMLDoc(e)||(n=n.toLowerCase(),o=x.attrHooks[n]||(x.expr.match.bool.test(n)?X:z)),r===t?o&&"get"in o&&null!==(a=o.get(e,n))?a:(a=x.find.attr(e,n),null==a?t:a):null!==r?o&&"set"in o&&(a=o.set(e,r,n))!==t?a:(e.setAttribute(n,r+""),r):(x.removeAttr(e,n),t))},removeAttr:function(e,t){var n,r,i=0,o=t&&t.match(T);if(o&&1===e.nodeType)while(n=o[i++])r=x.propFix[n]||n,x.expr.match.bool.test(n)?K&&Q||!G.test(n)?e[r]=!1:e[x.camelCase("default-"+n)]=e[r]=!1:x.attr(e,n,""),e.removeAttribute(Q?n:r)},attrHooks:{type:{set:function(e,t){if(!x.support.radioValue&&"radio"===t&&x.nodeName(e,"input")){var n=e.value;return e.setAttribute("type",t),n&&(e.value=n),t}}}},propFix:{"for":"htmlFor","class":"className"},prop:function(e,n,r){var i,o,a,s=e.nodeType;if(e&&3!==s&&8!==s&&2!==s)return a=1!==s||!x.isXMLDoc(e),a&&(n=x.propFix[n]||n,o=x.propHooks[n]),r!==t?o&&"set"in o&&(i=o.set(e,r,n))!==t?i:e[n]=r:o&&"get"in o&&null!==(i=o.get(e,n))?i:e[n]},propHooks:{tabIndex:{get:function(e){var t=x.find.attr(e,"tabindex");return t?parseInt(t,10):Y.test(e.nodeName)||J.test(e.nodeName)&&e.href?0:-1}}}}),X={set:function(e,t,n){return t===!1?x.removeAttr(e,n):K&&Q||!G.test(n)?e.setAttribute(!Q&&x.propFix[n]||n,n):e[x.camelCase("default-"+n)]=e[n]=!0,n}},x.each(x.expr.match.bool.source.match(/\\w+/g),function(e,n){var r=x.expr.attrHandle[n]||x.find.attr;x.expr.attrHandle[n]=K&&Q||!G.test(n)?function(e,n,i){var o=x.expr.attrHandle[n],a=i?t:(x.expr.attrHandle[n]=t)!=r(e,n,i)?n.toLowerCase():null;return x.expr.attrHandle[n]=o,a}:function(e,n,r){return r?t:e[x.camelCase("default-"+n)]?n.toLowerCase():null}}),K&&Q||(x.attrHooks.value={set:function(e,n,r){return x.nodeName(e,"input")?(e.defaultValue=n,t):z&&z.set(e,n,r)}}),Q||(z={set:function(e,n,r){var i=e.getAttributeNode(r);return i||e.setAttributeNode(i=e.ownerDocument.createAttribute(r)),i.value=n+="","value"===r||n===e.getAttribute(r)?n:t}},x.expr.attrHandle.id=x.expr.attrHandle.name=x.expr.attrHandle.coords=function(e,n,r){var i;return r?t:(i=e.getAttributeNode(n))&&""!==i.value?i.value:null},x.valHooks.button={get:function(e,n){var r=e.getAttributeNode(n);return r&&r.specified?r.value:t},set:z.set},x.attrHooks.contenteditable={set:function(e,t,n){z.set(e,""===t?!1:t,n)}},x.each(["width","height"],function(e,n){x.attrHooks[n]={set:function(e,r){return""===r?(e.setAttribute(n,"auto"),r):t}}})),x.support.hrefNormalized||x.each(["href","src"],function(e,t){x.propHooks[t]={get:function(e){return e.getAttribute(t,4)}}}),x.support.style||(x.attrHooks.style={get:function(e){return e.style.cssText||t},set:function(e,t){return e.style.cssText=t+""}}),x.support.optSelected||(x.propHooks.selected={get:function(e){var t=e.parentNode;return t&&(t.selectedIndex,t.parentNode&&t.parentNode.selectedIndex),null}}),x.each(["tabIndex","readOnly","maxLength","cellSpacing","cellPadding","rowSpan","colSpan","useMap","frameBorder","contentEditable"],function(){x.propFix[this.toLowerCase()]=this}),x.support.enctype||(x.propFix.enctype="encoding"),x.each(["radio","checkbox"],function(){x.valHooks[this]={set:function(e,n){return x.isArray(n)?e.checked=x.inArray(x(e).val(),n)>=0:t}},x.support.checkOn||(x.valHooks[this].get=function(e){return null===e.getAttribute("value")?"on":e.value})});var Z=/^(?:input|select|textarea)$/i,et=/^key/,tt=/^(?:mouse|contextmenu)|click/,nt=/^(?:focusinfocus|focusoutblur)$/,rt=/^([^.]*)(?:\\.(.+)|)$/;function it(){return!0}function ot(){return!1}function at(){try{return a.activeElement}catch(e){}}x.event={global:{},add:function(e,n,r,o,a){var s,l,u,c,p,f,d,h,g,m,y,v=x._data(e);if(v){r.handler&&(c=r,r=c.handler,a=c.selector),r.guid||(r.guid=x.guid++),(l=v.events)||(l=v.events={}),(f=v.handle)||(f=v.handle=function(e){return typeof x===i||e&&x.event.triggered===e.type?t:x.event.dispatch.apply(f.elem,arguments)},f.elem=e),n=(n||"").match(T)||[""],u=n.length;while(u--)s=rt.exec(n[u])||[],g=y=s[1],m=(s[2]||"").split(".").sort(),g&&(p=x.event.special[g]||{},g=(a?p.delegateType:p.bindType)||g,p=x.event.special[g]||{},d=x.extend({type:g,origType:y,data:o,handler:r,guid:r.guid,selector:a,needsContext:a&&x.expr.match.needsContext.test(a),namespace:m.join(".")},c),(h=l[g])||(h=l[g]=[],h.delegateCount=0,p.setup&&p.setup.call(e,o,m,f)!==!1||(e.addEventListener?e.addEventListener(g,f,!1):e.attachEvent&&e.attachEvent("on"+g,f))),p.add&&(p.add.call(e,d),d.handler.guid||(d.handler.guid=r.guid)),a?h.splice(h.delegateCount++,0,d):h.push(d),x.event.global[g]=!0);e=null}},remove:function(e,t,n,r,i){var o,a,s,l,u,c,p,f,d,h,g,m=x.hasData(e)&&x._data(e);if(m&&(c=m.events)){t=(t||"").match(T)||[""],u=t.length;while(u--)if(s=rt.exec(t[u])||[],d=g=s[1],h=(s[2]||"").split(".").sort(),d){p=x.event.special[d]||{},d=(r?p.delegateType:p.bindType)||d,f=c[d]||[],s=s[2]&&RegExp("(^|\\\\.)"+h.join("\\\\.(?:.*\\\\.|)")+"(\\\\.|$)"),l=o=f.length;while(o--)a=f[o],!i&&g!==a.origType||n&&n.guid!==a.guid||s&&!s.test(a.namespace)||r&&r!==a.selector&&("**"!==r||!a.selector)||(f.splice(o,1),a.selector&&f.delegateCount--,p.remove&&p.remove.call(e,a));l&&!f.length&&(p.teardown&&p.teardown.call(e,h,m.handle)!==!1||x.removeEvent(e,d,m.handle),delete c[d])}else for(d in c)x.event.remove(e,d+t[u],n,r,!0);x.isEmptyObject(c)&&(delete m.handle,x._removeData(e,"events"))}},trigger:function(n,r,i,o){var s,l,u,c,p,f,d,h=[i||a],g=v.call(n,"type")?n.type:n,m=v.call(n,"namespace")?n.namespace.split("."):[];if(u=f=i=i||a,3!==i.nodeType&&8!==i.nodeType&&!nt.test(g+x.event.triggered)&&(g.indexOf(".")>=0&&(m=g.split("."),g=m.shift(),m.sort()),l=0>g.indexOf(":")&&"on"+g,n=n[x.expando]?n:new x.Event(g,"object"==typeof n&&n),n.isTrigger=o?2:3,n.namespace=m.join("."),n.namespace_re=n.namespace?RegExp("(^|\\\\.)"+m.join("\\\\.(?:.*\\\\.|)")+"(\\\\.|$)"):null,n.result=t,n.target||(n.target=i),r=null==r?[n]:x.makeArray(r,[n]),p=x.event.special[g]||{},o||!p.trigger||p.trigger.apply(i,r)!==!1)){if(!o&&!p.noBubble&&!x.isWindow(i)){for(c=p.delegateType||g,nt.test(c+g)||(u=u.parentNode);u;u=u.parentNode)h.push(u),f=u;f===(i.ownerDocument||a)&&h.push(f.defaultView||f.parentWindow||e)}d=0;while((u=h[d++])&&!n.isPropagationStopped())n.type=d>1?c:p.bindType||g,s=(x._data(u,"events")||{})[n.type]&&x._data(u,"handle"),s&&s.apply(u,r),s=l&&u[l],s&&x.acceptData(u)&&s.apply&&s.apply(u,r)===!1&&n.preventDefault();if(n.type=g,!o&&!n.isDefaultPrevented()&&(!p._default||p._default.apply(h.pop(),r)===!1)&&x.acceptData(i)&&l&&i[g]&&!x.isWindow(i)){f=i[l],f&&(i[l]=null),x.event.triggered=g;try{i[g]()}catch(y){}x.event.triggered=t,f&&(i[l]=f)}return n.result}},dispatch:function(e){e=x.event.fix(e);var n,r,i,o,a,s=[],l=g.call(arguments),u=(x._data(this,"events")||{})[e.type]||[],c=x.event.special[e.type]||{};if(l[0]=e,e.delegateTarget=this,!c.preDispatch||c.preDispatch.call(this,e)!==!1){s=x.event.handlers.call(this,e,u),n=0;while((o=s[n++])&&!e.isPropagationStopped()){e.currentTarget=o.elem,a=0;while((i=o.handlers[a++])&&!e.isImmediatePropagationStopped())(!e.namespace_re||e.namespace_re.test(i.namespace))&&(e.handleObj=i,e.data=i.data,r=((x.event.special[i.origType]||{}).handle||i.handler).apply(o.elem,l),r!==t&&(e.result=r)===!1&&(e.preventDefault(),e.stopPropagation()))}return c.postDispatch&&c.postDispatch.call(this,e),e.result}},handlers:function(e,n){var r,i,o,a,s=[],l=n.delegateCount,u=e.target;if(l&&u.nodeType&&(!e.button||"click"!==e.type))for(;u!=this;u=u.parentNode||this)if(1===u.nodeType&&(u.disabled!==!0||"click"!==e.type)){for(o=[],a=0;l>a;a++)i=n[a],r=i.selector+" ",o[r]===t&&(o[r]=i.needsContext?x(r,this).index(u)>=0:x.find(r,this,null,[u]).length),o[r]&&o.push(i);o.length&&s.push({elem:u,handlers:o})}return n.length>l&&s.push({elem:this,handlers:n.slice(l)}),s},fix:function(e){if(e[x.expando])return e;var t,n,r,i=e.type,o=e,s=this.fixHooks[i];s||(this.fixHooks[i]=s=tt.test(i)?this.mouseHooks:et.test(i)?this.keyHooks:{}),r=s.props?this.props.concat(s.props):this.props,e=new x.Event(o),t=r.length;while(t--)n=r[t],e[n]=o[n];return e.target||(e.target=o.srcElement||a),3===e.target.nodeType&&(e.target=e.target.parentNode),e.metaKey=!!e.metaKey,s.filter?s.filter(e,o):e},props:"altKey bubbles cancelable ctrlKey currentTarget eventPhase metaKey relatedTarget shiftKey target timeStamp view which".split(" "),fixHooks:{},keyHooks:{props:"char charCode key keyCode".split(" "),filter:function(e,t){return null==e.which&&(e.which=null!=t.charCode?t.charCode:t.keyCode),e}},mouseHooks:{props:"button buttons clientX clientY fromElement offsetX offsetY pageX pageY screenX screenY toElement".split(" "),filter:function(e,n){var r,i,o,s=n.button,l=n.fromElement;return null==e.pageX&&null!=n.clientX&&(i=e.target.ownerDocument||a,o=i.documentElement,r=i.body,e.pageX=n.clientX+(o&&o.scrollLeft||r&&r.scrollLeft||0)-(o&&o.clientLeft||r&&r.clientLeft||0),e.pageY=n.clientY+(o&&o.scrollTop||r&&r.scrollTop||0)-(o&&o.clientTop||r&&r.clientTop||0)),!e.relatedTarget&&l&&(e.relatedTarget=l===e.target?n.toElement:l),e.which||s===t||(e.which=1&s?1:2&s?3:4&s?2:0),e}},special:{load:{noBubble:!0},focus:{trigger:function(){if(this!==at()&&this.focus)try{return this.focus(),!1}catch(e){}},delegateType:"focusin"},blur:{trigger:function(){return this===at()&&this.blur?(this.blur(),!1):t},delegateType:"focusout"},click:{trigger:function(){return x.nodeName(this,"input")&&"checkbox"===this.type&&this.click?(this.click(),!1):t},_default:function(e){return x.nodeName(e.target,"a")}},beforeunload:{postDispatch:function(e){e.result!==t&&(e.originalEvent.returnValue=e.result)}}},simulate:function(e,t,n,r){var i=x.extend(new x.Event,n,{type:e,isSimulated:!0,originalEvent:{}});r?x.event.trigger(i,null,t):x.event.dispatch.call(t,i),i.isDefaultPrevented()&&n.preventDefault()}},x.removeEvent=a.removeEventListener?function(e,t,n){e.removeEventListener&&e.removeEventListener(t,n,!1)}:function(e,t,n){var r="on"+t;e.detachEvent&&(typeof e[r]===i&&(e[r]=null),e.detachEvent(r,n))},x.Event=function(e,n){return this instanceof x.Event?(e&&e.type?(this.originalEvent=e,this.type=e.type,this.isDefaultPrevented=e.defaultPrevented||e.returnValue===!1||e.getPreventDefault&&e.getPreventDefault()?it:ot):this.type=e,n&&x.extend(this,n),this.timeStamp=e&&e.timeStamp||x.now(),this[x.expando]=!0,t):new x.Event(e,n)},x.Event.prototype={isDefaultPrevented:ot,isPropagationStopped:ot,isImmediatePropagationStopped:ot,preventDefault:function(){var e=this.originalEvent;this.isDefaultPrevented=it,e&&(e.preventDefault?e.preventDefault():e.returnValue=!1)},stopPropagation:function(){var e=this.originalEvent;this.isPropagationStopped=it,e&&(e.stopPropagation&&e.stopPropagation(),e.cancelBubble=!0)},stopImmediatePropagation:function(){this.isImmediatePropagationStopped=it,this.stopPropagation()}},x.each({mouseenter:"mouseover",mouseleave:"mouseout"},function(e,t){x.event.special[e]={delegateType:t,bindType:t,handle:function(e){var n,r=this,i=e.relatedTarget,o=e.handleObj;return(!i||i!==r&&!x.contains(r,i))&&(e.type=o.origType,n=o.handler.apply(this,arguments),e.type=t),n}}}),x.support.submitBubbles||(x.event.special.submit={setup:function(){return x.nodeName(this,"form")?!1:(x.event.add(this,"click._submit keypress._submit",function(e){var n=e.target,r=x.nodeName(n,"input")||x.nodeName(n,"button")?n.form:t;r&&!x._data(r,"submitBubbles")&&(x.event.add(r,"submit._submit",function(e){e._submit_bubble=!0}),x._data(r,"submitBubbles",!0))}),t)},postDispatch:function(e){e._submit_bubble&&(delete e._submit_bubble,this.parentNode&&!e.isTrigger&&x.event.simulate("submit",this.parentNode,e,!0))},teardown:function(){return x.nodeName(this,"form")?!1:(x.event.remove(this,"._submit"),t)}}),x.support.changeBubbles||(x.event.special.change={setup:function(){return Z.test(this.nodeName)?(("checkbox"===this.type||"radio"===this.type)&&(x.event.add(this,"propertychange._change",function(e){"checked"===e.originalEvent.propertyName&&(this._just_changed=!0)}),x.event.add(this,"click._change",function(e){this._just_changed&&!e.isTrigger&&(this._just_changed=!1),x.event.simulate("change",this,e,!0)})),!1):(x.event.add(this,"beforeactivate._change",function(e){var t=e.target;Z.test(t.nodeName)&&!x._data(t,"changeBubbles")&&(x.event.add(t,"change._change",function(e){!this.parentNode||e.isSimulated||e.isTrigger||x.event.simulate("change",this.parentNode,e,!0)}),x._data(t,"changeBubbles",!0))}),t)},handle:function(e){var n=e.target;return this!==n||e.isSimulated||e.isTrigger||"radio"!==n.type&&"checkbox"!==n.type?e.handleObj.handler.apply(this,arguments):t},teardown:function(){return x.event.remove(this,"._change"),!Z.test(this.nodeName)}}),x.support.focusinBubbles||x.each({focus:"focusin",blur:"focusout"},function(e,t){var n=0,r=function(e){x.event.simulate(t,e.target,x.event.fix(e),!0)};x.event.special[t]={setup:function(){0===n++&&a.addEventListener(e,r,!0)},teardown:function(){0===--n&&a.removeEventListener(e,r,!0)}}}),x.fn.extend({on:function(e,n,r,i,o){var a,s;if("object"==typeof e){"string"!=typeof n&&(r=r||n,n=t);for(a in e)this.on(a,n,r,e[a],o);return this}if(null==r&&null==i?(i=n,r=n=t):null==i&&("string"==typeof n?(i=r,r=t):(i=r,r=n,n=t)),i===!1)i=ot;else if(!i)return this;return 1===o&&(s=i,i=function(e){return x().off(e),s.apply(this,arguments)},i.guid=s.guid||(s.guid=x.guid++)),this.each(function(){x.event.add(this,e,i,r,n)})},one:function(e,t,n,r){return this.on(e,t,n,r,1)},off:function(e,n,r){var i,o;if(e&&e.preventDefault&&e.handleObj)return i=e.handleObj,x(e.delegateTarget).off(i.namespace?i.origType+"."+i.namespace:i.origType,i.selector,i.handler),this;if("object"==typeof e){for(o in e)this.off(o,n,e[o]);return this}return(n===!1||"function"==typeof n)&&(r=n,n=t),r===!1&&(r=ot),this.each(function(){x.event.remove(this,e,r,n)})},trigger:function(e,t){return this.each(function(){x.event.trigger(e,t,this)})},triggerHandler:function(e,n){var r=this[0];return r?x.event.trigger(e,n,r,!0):t}});var st=/^.[^:#\\[\\.,]*$/,lt=/^(?:parents|prev(?:Until|All))/,ut=x.expr.match.needsContext,ct={children:!0,contents:!0,next:!0,prev:!0};x.fn.extend({find:function(e){var t,n=[],r=this,i=r.length;if("string"!=typeof e)return this.pushStack(x(e).filter(function(){for(t=0;i>t;t++)if(x.contains(r[t],this))return!0}));for(t=0;i>t;t++)x.find(e,r[t],n);return n=this.pushStack(i>1?x.unique(n):n),n.selector=this.selector?this.selector+" "+e:e,n},has:function(e){var t,n=x(e,this),r=n.length;return this.filter(function(){for(t=0;r>t;t++)if(x.contains(this,n[t]))return!0})},not:function(e){return this.pushStack(ft(this,e||[],!0))},filter:function(e){return this.pushStack(ft(this,e||[],!1))},is:function(e){return!!ft(this,"string"==typeof e&&ut.test(e)?x(e):e||[],!1).length},closest:function(e,t){var n,r=0,i=this.length,o=[],a=ut.test(e)||"string"!=typeof e?x(e,t||this.context):0;for(;i>r;r++)for(n=this[r];n&&n!==t;n=n.parentNode)if(11>n.nodeType&&(a?a.index(n)>-1:1===n.nodeType&&x.find.matchesSelector(n,e))){n=o.push(n);break}return this.pushStack(o.length>1?x.unique(o):o)},index:function(e){return e?"string"==typeof e?x.inArray(this[0],x(e)):x.inArray(e.jquery?e[0]:e,this):this[0]&&this[0].parentNode?this.first().prevAll().length:-1},add:function(e,t){var n="string"==typeof e?x(e,t):x.makeArray(e&&e.nodeType?[e]:e),r=x.merge(this.get(),n);return this.pushStack(x.unique(r))},addBack:function(e){return this.add(null==e?this.prevObject:this.prevObject.filter(e))}});function pt(e,t){do e=e[t];while(e&&1!==e.nodeType);return e}x.each({parent:function(e){var t=e.parentNode;return t&&11!==t.nodeType?t:null},parents:function(e){return x.dir(e,"parentNode")},parentsUntil:function(e,t,n){return x.dir(e,"parentNode",n)},next:function(e){return pt(e,"nextSibling")},prev:function(e){return pt(e,"previousSibling")},nextAll:function(e){return x.dir(e,"nextSibling")},prevAll:function(e){return x.dir(e,"previousSibling")},nextUntil:function(e,t,n){return x.dir(e,"nextSibling",n)},prevUntil:function(e,t,n){return x.dir(e,"previousSibling",n)},siblings:function(e){return x.sibling((e.parentNode||{}).firstChild,e)},children:function(e){return x.sibling(e.firstChild)},contents:function(e){return x.nodeName(e,"iframe")?e.contentDocument||e.contentWindow.document:x.merge([],e.childNodes)}},function(e,t){x.fn[e]=function(n,r){var i=x.map(this,t,n);return"Until"!==e.slice(-5)&&(r=n),r&&"string"==typeof r&&(i=x.filter(r,i)),this.length>1&&(ct[e]||(i=x.unique(i)),lt.test(e)&&(i=i.reverse())),this.pushStack(i)}}),x.extend({filter:function(e,t,n){var r=t[0];return n&&(e=":not("+e+")"),1===t.length&&1===r.nodeType?x.find.matchesSelector(r,e)?[r]:[]:x.find.matches(e,x.grep(t,function(e){return 1===e.nodeType}))},dir:function(e,n,r){var i=[],o=e[n];while(o&&9!==o.nodeType&&(r===t||1!==o.nodeType||!x(o).is(r)))1===o.nodeType&&i.push(o),o=o[n];return i},sibling:function(e,t){var n=[];for(;e;e=e.nextSibling)1===e.nodeType&&e!==t&&n.push(e);return n}});function ft(e,t,n){if(x.isFunction(t))return x.grep(e,function(e,r){return!!t.call(e,r,e)!==n});if(t.nodeType)return x.grep(e,function(e){return e===t!==n});if("string"==typeof t){if(st.test(t))return x.filter(t,e,n);t=x.filter(t,e)}return x.grep(e,function(e){return x.inArray(e,t)>=0!==n})}function dt(e){var t=ht.split("|"),n=e.createDocumentFragment();if(n.createElement)while(t.length)n.createElement(t.pop());return n}var ht="abbr|article|aside|audio|bdi|canvas|data|datalist|details|figcaption|figure|footer|header|hgroup|mark|meter|nav|output|progress|section|summary|time|video",gt=/ jQuery\\d+="(?:null|\\d+)"/g,mt=RegExp("<(?:"+ht+")[\\\\s/>]","i"),yt=/^\\s+/,vt=/<(?!area|br|col|embed|hr|img|input|link|meta|param)(([\\w:]+)[^>]*)\\/>/gi,bt=/<([\\w:]+)/,xt=/<tbody/i,wt=/<|&#?\\w+;/,Tt=/<(?:script|style|link)/i,Ct=/^(?:checkbox|radio)$/i,Nt=/checked\\s*(?:[^=]|=\\s*.checked.)/i,kt=/^$|\\/(?:java|ecma)script/i,Et=/^true\\/(.*)/,St=/^\\s*<!(?:\\[CDATA\\[|--)|(?:\\]\\]|--)>\\s*$/g,At={option:[1,"<select multiple=\'multiple\'>","</select>"],legend:[1,"<fieldset>","</fieldset>"],area:[1,"<map>","</map>"],param:[1,"<object>","</object>"],thead:[1,"<table>","</table>"],tr:[2,"<table><tbody>","</tbody></table>"],col:[2,"<table><tbody></tbody><colgroup>","</colgroup></table>"],td:[3,"<table><tbody><tr>","</tr></tbody></table>"],_default:x.support.htmlSerialize?[0,"",""]:[1,"X<div>","</div>"]},jt=dt(a),Dt=jt.appendChild(a.createElement("div"));At.optgroup=At.option,At.tbody=At.tfoot=At.colgroup=At.caption=At.thead,At.th=At.td,x.fn.extend({text:function(e){return x.access(this,function(e){return e===t?x.text(this):this.empty().append((this[0]&&this[0].ownerDocument||a).createTextNode(e))},null,e,arguments.length)},append:function(){return this.domManip(arguments,function(e){if(1===this.nodeType||11===this.nodeType||9===this.nodeType){var t=Lt(this,e);t.appendChild(e)}})},prepend:function(){return this.domManip(arguments,function(e){if(1===this.nodeType||11===this.nodeType||9===this.nodeType){var t=Lt(this,e);t.insertBefore(e,t.firstChild)}})},before:function(){return this.domManip(arguments,function(e){this.parentNode&&this.parentNode.insertBefore(e,this)})},after:function(){return this.domManip(arguments,function(e){this.parentNode&&this.parentNode.insertBefore(e,this.nextSibling)})},remove:function(e,t){var n,r=e?x.filter(e,this):this,i=0;for(;null!=(n=r[i]);i++)t||1!==n.nodeType||x.cleanData(Ft(n)),n.parentNode&&(t&&x.contains(n.ownerDocument,n)&&_t(Ft(n,"script")),n.parentNode.removeChild(n));return this},empty:function(){var e,t=0;for(;null!=(e=this[t]);t++){1===e.nodeType&&x.cleanData(Ft(e,!1));while(e.firstChild)e.removeChild(e.firstChild);e.options&&x.nodeName(e,"select")&&(e.options.length=0)}return this},clone:function(e,t){return e=null==e?!1:e,t=null==t?e:t,this.map(function(){return x.clone(this,e,t)})},html:function(e){return x.access(this,function(e){var n=this[0]||{},r=0,i=this.length;if(e===t)return 1===n.nodeType?n.innerHTML.replace(gt,""):t;if(!("string"!=typeof e||Tt.test(e)||!x.support.htmlSerialize&&mt.test(e)||!x.support.leadingWhitespace&&yt.test(e)||At[(bt.exec(e)||["",""])[1].toLowerCase()])){e=e.replace(vt,"<$1></$2>");try{for(;i>r;r++)n=this[r]||{},1===n.nodeType&&(x.cleanData(Ft(n,!1)),n.innerHTML=e);n=0}catch(o){}}n&&this.empty().append(e)},null,e,arguments.length)},replaceWith:function(){var e=x.map(this,function(e){return[e.nextSibling,e.parentNode]}),t=0;return this.domManip(arguments,function(n){var r=e[t++],i=e[t++];i&&(r&&r.parentNode!==i&&(r=this.nextSibling),x(this).remove(),i.insertBefore(n,r))},!0),t?this:this.remove()},detach:function(e){return this.remove(e,!0)},domManip:function(e,t,n){e=d.apply([],e);var r,i,o,a,s,l,u=0,c=this.length,p=this,f=c-1,h=e[0],g=x.isFunction(h);if(g||!(1>=c||"string"!=typeof h||x.support.checkClone)&&Nt.test(h))return this.each(function(r){var i=p.eq(r);g&&(e[0]=h.call(this,r,i.html())),i.domManip(e,t,n)});if(c&&(l=x.buildFragment(e,this[0].ownerDocument,!1,!n&&this),r=l.firstChild,1===l.childNodes.length&&(l=r),r)){for(a=x.map(Ft(l,"script"),Ht),o=a.length;c>u;u++)i=l,u!==f&&(i=x.clone(i,!0,!0),o&&x.merge(a,Ft(i,"script"))),t.call(this[u],i,u);if(o)for(s=a[a.length-1].ownerDocument,x.map(a,qt),u=0;o>u;u++)i=a[u],kt.test(i.type||"")&&!x._data(i,"globalEval")&&x.contains(s,i)&&(i.src?x._evalUrl(i.src):x.globalEval((i.text||i.textContent||i.innerHTML||"").replace(St,"")));l=r=null}return this}});function Lt(e,t){return x.nodeName(e,"table")&&x.nodeName(1===t.nodeType?t:t.firstChild,"tr")?e.getElementsByTagName("tbody")[0]||e.appendChild(e.ownerDocument.createElement("tbody")):e}function Ht(e){return e.type=(null!==x.find.attr(e,"type"))+"/"+e.type,e}function qt(e){var t=Et.exec(e.type);return t?e.type=t[1]:e.removeAttribute("type"),e}function _t(e,t){var n,r=0;for(;null!=(n=e[r]);r++)x._data(n,"globalEval",!t||x._data(t[r],"globalEval"))}function Mt(e,t){if(1===t.nodeType&&x.hasData(e)){var n,r,i,o=x._data(e),a=x._data(t,o),s=o.events;if(s){delete a.handle,a.events={};for(n in s)for(r=0,i=s[n].length;i>r;r++)x.event.add(t,n,s[n][r])}a.data&&(a.data=x.extend({},a.data))}}function Ot(e,t){var n,r,i;if(1===t.nodeType){if(n=t.nodeName.toLowerCase(),!x.support.noCloneEvent&&t[x.expando]){i=x._data(t);for(r in i.events)x.removeEvent(t,r,i.handle);t.removeAttribute(x.expando)}"script"===n&&t.text!==e.text?(Ht(t).text=e.text,qt(t)):"object"===n?(t.parentNode&&(t.outerHTML=e.outerHTML),x.support.html5Clone&&e.innerHTML&&!x.trim(t.innerHTML)&&(t.innerHTML=e.innerHTML)):"input"===n&&Ct.test(e.type)?(t.defaultChecked=t.checked=e.checked,t.value!==e.value&&(t.value=e.value)):"option"===n?t.defaultSelected=t.selected=e.defaultSelected:("input"===n||"textarea"===n)&&(t.defaultValue=e.defaultValue)}}x.each({appendTo:"append",prependTo:"prepend",insertBefore:"before",insertAfter:"after",replaceAll:"replaceWith"},function(e,t){x.fn[e]=function(e){var n,r=0,i=[],o=x(e),a=o.length-1;for(;a>=r;r++)n=r===a?this:this.clone(!0),x(o[r])[t](n),h.apply(i,n.get());return this.pushStack(i)}});function Ft(e,n){var r,o,a=0,s=typeof e.getElementsByTagName!==i?e.getElementsByTagName(n||"*"):typeof e.querySelectorAll!==i?e.querySelectorAll(n||"*"):t;if(!s)for(s=[],r=e.childNodes||e;null!=(o=r[a]);a++)!n||x.nodeName(o,n)?s.push(o):x.merge(s,Ft(o,n));return n===t||n&&x.nodeName(e,n)?x.merge([e],s):s}function Bt(e){Ct.test(e.type)&&(e.defaultChecked=e.checked)}x.extend({clone:function(e,t,n){var r,i,o,a,s,l=x.contains(e.ownerDocument,e);if(x.support.html5Clone||x.isXMLDoc(e)||!mt.test("<"+e.nodeName+">")?o=e.cloneNode(!0):(Dt.innerHTML=e.outerHTML,Dt.removeChild(o=Dt.firstChild)),!(x.support.noCloneEvent&&x.support.noCloneChecked||1!==e.nodeType&&11!==e.nodeType||x.isXMLDoc(e)))for(r=Ft(o),s=Ft(e),a=0;null!=(i=s[a]);++a)r[a]&&Ot(i,r[a]);if(t)if(n)for(s=s||Ft(e),r=r||Ft(o),a=0;null!=(i=s[a]);a++)Mt(i,r[a]);else Mt(e,o);return r=Ft(o,"script"),r.length>0&&_t(r,!l&&Ft(e,"script")),r=s=i=null,o},buildFragment:function(e,t,n,r){var i,o,a,s,l,u,c,p=e.length,f=dt(t),d=[],h=0;for(;p>h;h++)if(o=e[h],o||0===o)if("object"===x.type(o))x.merge(d,o.nodeType?[o]:o);else if(wt.test(o)){s=s||f.appendChild(t.createElement("div")),l=(bt.exec(o)||["",""])[1].toLowerCase(),c=At[l]||At._default,s.innerHTML=c[1]+o.replace(vt,"<$1></$2>")+c[2],i=c[0];while(i--)s=s.lastChild;if(!x.support.leadingWhitespace&&yt.test(o)&&d.push(t.createTextNode(yt.exec(o)[0])),!x.support.tbody){o="table"!==l||xt.test(o)?"<table>"!==c[1]||xt.test(o)?0:s:s.firstChild,i=o&&o.childNodes.length;while(i--)x.nodeName(u=o.childNodes[i],"tbody")&&!u.childNodes.length&&o.removeChild(u)}x.merge(d,s.childNodes),s.textContent="";while(s.firstChild)s.removeChild(s.firstChild);s=f.lastChild}else d.push(t.createTextNode(o));s&&f.removeChild(s),x.support.appendChecked||x.grep(Ft(d,"input"),Bt),h=0;while(o=d[h++])if((!r||-1===x.inArray(o,r))&&(a=x.contains(o.ownerDocument,o),s=Ft(f.appendChild(o),"script"),a&&_t(s),n)){i=0;while(o=s[i++])kt.test(o.type||"")&&n.push(o)}return s=null,f},cleanData:function(e,t){var n,r,o,a,s=0,l=x.expando,u=x.cache,c=x.support.deleteExpando,f=x.event.special;for(;null!=(n=e[s]);s++)if((t||x.acceptData(n))&&(o=n[l],a=o&&u[o])){if(a.events)for(r in a.events)f[r]?x.event.remove(n,r):x.removeEvent(n,r,a.handle);
u[o]&&(delete u[o],c?delete n[l]:typeof n.removeAttribute!==i?n.removeAttribute(l):n[l]=null,p.push(o))}},_evalUrl:function(e){return x.ajax({url:e,type:"GET",dataType:"script",async:!1,global:!1,"throws":!0})}}),x.fn.extend({wrapAll:function(e){if(x.isFunction(e))return this.each(function(t){x(this).wrapAll(e.call(this,t))});if(this[0]){var t=x(e,this[0].ownerDocument).eq(0).clone(!0);this[0].parentNode&&t.insertBefore(this[0]),t.map(function(){var e=this;while(e.firstChild&&1===e.firstChild.nodeType)e=e.firstChild;return e}).append(this)}return this},wrapInner:function(e){return x.isFunction(e)?this.each(function(t){x(this).wrapInner(e.call(this,t))}):this.each(function(){var t=x(this),n=t.contents();n.length?n.wrapAll(e):t.append(e)})},wrap:function(e){var t=x.isFunction(e);return this.each(function(n){x(this).wrapAll(t?e.call(this,n):e)})},unwrap:function(){return this.parent().each(function(){x.nodeName(this,"body")||x(this).replaceWith(this.childNodes)}).end()}});var Pt,Rt,Wt,$t=/alpha\\([^)]*\\)/i,It=/opacity\\s*=\\s*([^)]*)/,zt=/^(top|right|bottom|left)$/,Xt=/^(none|table(?!-c[ea]).+)/,Ut=/^margin/,Vt=RegExp("^("+w+")(.*)$","i"),Yt=RegExp("^("+w+")(?!px)[a-z%]+$","i"),Jt=RegExp("^([+-])=("+w+")","i"),Gt={BODY:"block"},Qt={position:"absolute",visibility:"hidden",display:"block"},Kt={letterSpacing:0,fontWeight:400},Zt=["Top","Right","Bottom","Left"],en=["Webkit","O","Moz","ms"];function tn(e,t){if(t in e)return t;var n=t.charAt(0).toUpperCase()+t.slice(1),r=t,i=en.length;while(i--)if(t=en[i]+n,t in e)return t;return r}function nn(e,t){return e=t||e,"none"===x.css(e,"display")||!x.contains(e.ownerDocument,e)}function rn(e,t){var n,r,i,o=[],a=0,s=e.length;for(;s>a;a++)r=e[a],r.style&&(o[a]=x._data(r,"olddisplay"),n=r.style.display,t?(o[a]||"none"!==n||(r.style.display=""),""===r.style.display&&nn(r)&&(o[a]=x._data(r,"olddisplay",ln(r.nodeName)))):o[a]||(i=nn(r),(n&&"none"!==n||!i)&&x._data(r,"olddisplay",i?n:x.css(r,"display"))));for(a=0;s>a;a++)r=e[a],r.style&&(t&&"none"!==r.style.display&&""!==r.style.display||(r.style.display=t?o[a]||"":"none"));return e}x.fn.extend({css:function(e,n){return x.access(this,function(e,n,r){var i,o,a={},s=0;if(x.isArray(n)){for(o=Rt(e),i=n.length;i>s;s++)a[n[s]]=x.css(e,n[s],!1,o);return a}return r!==t?x.style(e,n,r):x.css(e,n)},e,n,arguments.length>1)},show:function(){return rn(this,!0)},hide:function(){return rn(this)},toggle:function(e){return"boolean"==typeof e?e?this.show():this.hide():this.each(function(){nn(this)?x(this).show():x(this).hide()})}}),x.extend({cssHooks:{opacity:{get:function(e,t){if(t){var n=Wt(e,"opacity");return""===n?"1":n}}}},cssNumber:{columnCount:!0,fillOpacity:!0,fontWeight:!0,lineHeight:!0,opacity:!0,order:!0,orphans:!0,widows:!0,zIndex:!0,zoom:!0},cssProps:{"float":x.support.cssFloat?"cssFloat":"styleFloat"},style:function(e,n,r,i){if(e&&3!==e.nodeType&&8!==e.nodeType&&e.style){var o,a,s,l=x.camelCase(n),u=e.style;if(n=x.cssProps[l]||(x.cssProps[l]=tn(u,l)),s=x.cssHooks[n]||x.cssHooks[l],r===t)return s&&"get"in s&&(o=s.get(e,!1,i))!==t?o:u[n];if(a=typeof r,"string"===a&&(o=Jt.exec(r))&&(r=(o[1]+1)*o[2]+parseFloat(x.css(e,n)),a="number"),!(null==r||"number"===a&&isNaN(r)||("number"!==a||x.cssNumber[l]||(r+="px"),x.support.clearCloneStyle||""!==r||0!==n.indexOf("background")||(u[n]="inherit"),s&&"set"in s&&(r=s.set(e,r,i))===t)))try{u[n]=r}catch(c){}}},css:function(e,n,r,i){var o,a,s,l=x.camelCase(n);return n=x.cssProps[l]||(x.cssProps[l]=tn(e.style,l)),s=x.cssHooks[n]||x.cssHooks[l],s&&"get"in s&&(a=s.get(e,!0,r)),a===t&&(a=Wt(e,n,i)),"normal"===a&&n in Kt&&(a=Kt[n]),""===r||r?(o=parseFloat(a),r===!0||x.isNumeric(o)?o||0:a):a}}),e.getComputedStyle?(Rt=function(t){return e.getComputedStyle(t,null)},Wt=function(e,n,r){var i,o,a,s=r||Rt(e),l=s?s.getPropertyValue(n)||s[n]:t,u=e.style;return s&&(""!==l||x.contains(e.ownerDocument,e)||(l=x.style(e,n)),Yt.test(l)&&Ut.test(n)&&(i=u.width,o=u.minWidth,a=u.maxWidth,u.minWidth=u.maxWidth=u.width=l,l=s.width,u.width=i,u.minWidth=o,u.maxWidth=a)),l}):a.documentElement.currentStyle&&(Rt=function(e){return e.currentStyle},Wt=function(e,n,r){var i,o,a,s=r||Rt(e),l=s?s[n]:t,u=e.style;return null==l&&u&&u[n]&&(l=u[n]),Yt.test(l)&&!zt.test(n)&&(i=u.left,o=e.runtimeStyle,a=o&&o.left,a&&(o.left=e.currentStyle.left),u.left="fontSize"===n?"1em":l,l=u.pixelLeft+"px",u.left=i,a&&(o.left=a)),""===l?"auto":l});function on(e,t,n){var r=Vt.exec(t);return r?Math.max(0,r[1]-(n||0))+(r[2]||"px"):t}function an(e,t,n,r,i){var o=n===(r?"border":"content")?4:"width"===t?1:0,a=0;for(;4>o;o+=2)"margin"===n&&(a+=x.css(e,n+Zt[o],!0,i)),r?("content"===n&&(a-=x.css(e,"padding"+Zt[o],!0,i)),"margin"!==n&&(a-=x.css(e,"border"+Zt[o]+"Width",!0,i))):(a+=x.css(e,"padding"+Zt[o],!0,i),"padding"!==n&&(a+=x.css(e,"border"+Zt[o]+"Width",!0,i)));return a}function sn(e,t,n){var r=!0,i="width"===t?e.offsetWidth:e.offsetHeight,o=Rt(e),a=x.support.boxSizing&&"border-box"===x.css(e,"boxSizing",!1,o);if(0>=i||null==i){if(i=Wt(e,t,o),(0>i||null==i)&&(i=e.style[t]),Yt.test(i))return i;r=a&&(x.support.boxSizingReliable||i===e.style[t]),i=parseFloat(i)||0}return i+an(e,t,n||(a?"border":"content"),r,o)+"px"}function ln(e){var t=a,n=Gt[e];return n||(n=un(e,t),"none"!==n&&n||(Pt=(Pt||x("<iframe frameborder=\'0\' width=\'0\' height=\'0\'/>").css("cssText","display:block !important")).appendTo(t.documentElement),t=(Pt[0].contentWindow||Pt[0].contentDocument).document,t.write("<!doctype html><html><body>"),t.close(),n=un(e,t),Pt.detach()),Gt[e]=n),n}function un(e,t){var n=x(t.createElement(e)).appendTo(t.body),r=x.css(n[0],"display");return n.remove(),r}x.each(["height","width"],function(e,n){x.cssHooks[n]={get:function(e,r,i){return r?0===e.offsetWidth&&Xt.test(x.css(e,"display"))?x.swap(e,Qt,function(){return sn(e,n,i)}):sn(e,n,i):t},set:function(e,t,r){var i=r&&Rt(e);return on(e,t,r?an(e,n,r,x.support.boxSizing&&"border-box"===x.css(e,"boxSizing",!1,i),i):0)}}}),x.support.opacity||(x.cssHooks.opacity={get:function(e,t){return It.test((t&&e.currentStyle?e.currentStyle.filter:e.style.filter)||"")?.01*parseFloat(RegExp.$1)+"":t?"1":""},set:function(e,t){var n=e.style,r=e.currentStyle,i=x.isNumeric(t)?"alpha(opacity="+100*t+")":"",o=r&&r.filter||n.filter||"";n.zoom=1,(t>=1||""===t)&&""===x.trim(o.replace($t,""))&&n.removeAttribute&&(n.removeAttribute("filter"),""===t||r&&!r.filter)||(n.filter=$t.test(o)?o.replace($t,i):o+" "+i)}}),x(function(){x.support.reliableMarginRight||(x.cssHooks.marginRight={get:function(e,n){return n?x.swap(e,{display:"inline-block"},Wt,[e,"marginRight"]):t}}),!x.support.pixelPosition&&x.fn.position&&x.each(["top","left"],function(e,n){x.cssHooks[n]={get:function(e,r){return r?(r=Wt(e,n),Yt.test(r)?x(e).position()[n]+"px":r):t}}})}),x.expr&&x.expr.filters&&(x.expr.filters.hidden=function(e){return 0>=e.offsetWidth&&0>=e.offsetHeight||!x.support.reliableHiddenOffsets&&"none"===(e.style&&e.style.display||x.css(e,"display"))},x.expr.filters.visible=function(e){return!x.expr.filters.hidden(e)}),x.each({margin:"",padding:"",border:"Width"},function(e,t){x.cssHooks[e+t]={expand:function(n){var r=0,i={},o="string"==typeof n?n.split(" "):[n];for(;4>r;r++)i[e+Zt[r]+t]=o[r]||o[r-2]||o[0];return i}},Ut.test(e)||(x.cssHooks[e+t].set=on)});var cn=/%20/g,pn=/\\[\\]$/,fn=/\\r?\\n/g,dn=/^(?:submit|button|image|reset|file)$/i,hn=/^(?:input|select|textarea|keygen)/i;x.fn.extend({serialize:function(){return x.param(this.serializeArray())},serializeArray:function(){return this.map(function(){var e=x.prop(this,"elements");return e?x.makeArray(e):this}).filter(function(){var e=this.type;return this.name&&!x(this).is(":disabled")&&hn.test(this.nodeName)&&!dn.test(e)&&(this.checked||!Ct.test(e))}).map(function(e,t){var n=x(this).val();return null==n?null:x.isArray(n)?x.map(n,function(e){return{name:t.name,value:e.replace(fn,"\\r\\n")}}):{name:t.name,value:n.replace(fn,"\\r\\n")}}).get()}}),x.param=function(e,n){var r,i=[],o=function(e,t){t=x.isFunction(t)?t():null==t?"":t,i[i.length]=encodeURIComponent(e)+"="+encodeURIComponent(t)};if(n===t&&(n=x.ajaxSettings&&x.ajaxSettings.traditional),x.isArray(e)||e.jquery&&!x.isPlainObject(e))x.each(e,function(){o(this.name,this.value)});else for(r in e)gn(r,e[r],n,o);return i.join("&").replace(cn,"+")};function gn(e,t,n,r){var i;if(x.isArray(t))x.each(t,function(t,i){n||pn.test(e)?r(e,i):gn(e+"["+("object"==typeof i?t:"")+"]",i,n,r)});else if(n||"object"!==x.type(t))r(e,t);else for(i in t)gn(e+"["+i+"]",t[i],n,r)}x.each("blur focus focusin focusout load resize scroll unload click dblclick mousedown mouseup mousemove mouseover mouseout mouseenter mouseleave change select submit keydown keypress keyup error contextmenu".split(" "),function(e,t){x.fn[t]=function(e,n){return arguments.length>0?this.on(t,null,e,n):this.trigger(t)}}),x.fn.extend({hover:function(e,t){return this.mouseenter(e).mouseleave(t||e)},bind:function(e,t,n){return this.on(e,null,t,n)},unbind:function(e,t){return this.off(e,null,t)},delegate:function(e,t,n,r){return this.on(t,e,n,r)},undelegate:function(e,t,n){return 1===arguments.length?this.off(e,"**"):this.off(t,e||"**",n)}});var mn,yn,vn=x.now(),bn=/\\?/,xn=/#.*$/,wn=/([?&])_=[^&]*/,Tn=/^(.*?):[ \\t]*([^\\r\\n]*)\\r?$/gm,Cn=/^(?:about|app|app-storage|.+-extension|file|res|widget):$/,Nn=/^(?:GET|HEAD)$/,kn=/^\\/\\//,En=/^([\\w.+-]+:)(?:\\/\\/([^\\/?#:]*)(?::(\\d+)|)|)/,Sn=x.fn.load,An={},jn={},Dn="*/".concat("*");try{yn=o.href}catch(Ln){yn=a.createElement("a"),yn.href="",yn=yn.href}mn=En.exec(yn.toLowerCase())||[];function Hn(e){return function(t,n){"string"!=typeof t&&(n=t,t="*");var r,i=0,o=t.toLowerCase().match(T)||[];if(x.isFunction(n))while(r=o[i++])"+"===r[0]?(r=r.slice(1)||"*",(e[r]=e[r]||[]).unshift(n)):(e[r]=e[r]||[]).push(n)}}function qn(e,n,r,i){var o={},a=e===jn;function s(l){var u;return o[l]=!0,x.each(e[l]||[],function(e,l){var c=l(n,r,i);return"string"!=typeof c||a||o[c]?a?!(u=c):t:(n.dataTypes.unshift(c),s(c),!1)}),u}return s(n.dataTypes[0])||!o["*"]&&s("*")}function _n(e,n){var r,i,o=x.ajaxSettings.flatOptions||{};for(i in n)n[i]!==t&&((o[i]?e:r||(r={}))[i]=n[i]);return r&&x.extend(!0,e,r),e}x.fn.load=function(e,n,r){if("string"!=typeof e&&Sn)return Sn.apply(this,arguments);var i,o,a,s=this,l=e.indexOf(" ");return l>=0&&(i=e.slice(l,e.length),e=e.slice(0,l)),x.isFunction(n)?(r=n,n=t):n&&"object"==typeof n&&(a="POST"),s.length>0&&x.ajax({url:e,type:a,dataType:"html",data:n}).done(function(e){o=arguments,s.html(i?x("<div>").append(x.parseHTML(e)).find(i):e)}).complete(r&&function(e,t){s.each(r,o||[e.responseText,t,e])}),this},x.each(["ajaxStart","ajaxStop","ajaxComplete","ajaxError","ajaxSuccess","ajaxSend"],function(e,t){x.fn[t]=function(e){return this.on(t,e)}}),x.extend({active:0,lastModified:{},etag:{},ajaxSettings:{url:yn,type:"GET",isLocal:Cn.test(mn[1]),global:!0,processData:!0,async:!0,contentType:"application/x-www-form-urlencoded; charset=UTF-8",accepts:{"*":Dn,text:"text/plain",html:"text/html",xml:"application/xml, text/xml",json:"application/json, text/javascript"},contents:{xml:/xml/,html:/html/,json:/json/},responseFields:{xml:"responseXML",text:"responseText",json:"responseJSON"},converters:{"* text":String,"text html":!0,"text json":x.parseJSON,"text xml":x.parseXML},flatOptions:{url:!0,context:!0}},ajaxSetup:function(e,t){return t?_n(_n(e,x.ajaxSettings),t):_n(x.ajaxSettings,e)},ajaxPrefilter:Hn(An),ajaxTransport:Hn(jn),ajax:function(e,n){"object"==typeof e&&(n=e,e=t),n=n||{};var r,i,o,a,s,l,u,c,p=x.ajaxSetup({},n),f=p.context||p,d=p.context&&(f.nodeType||f.jquery)?x(f):x.event,h=x.Deferred(),g=x.Callbacks("once memory"),m=p.statusCode||{},y={},v={},b=0,w="canceled",C={readyState:0,getResponseHeader:function(e){var t;if(2===b){if(!c){c={};while(t=Tn.exec(a))c[t[1].toLowerCase()]=t[2]}t=c[e.toLowerCase()]}return null==t?null:t},getAllResponseHeaders:function(){return 2===b?a:null},setRequestHeader:function(e,t){var n=e.toLowerCase();return b||(e=v[n]=v[n]||e,y[e]=t),this},overrideMimeType:function(e){return b||(p.mimeType=e),this},statusCode:function(e){var t;if(e)if(2>b)for(t in e)m[t]=[m[t],e[t]];else C.always(e[C.status]);return this},abort:function(e){var t=e||w;return u&&u.abort(t),k(0,t),this}};if(h.promise(C).complete=g.add,C.success=C.done,C.error=C.fail,p.url=((e||p.url||yn)+"").replace(xn,"").replace(kn,mn[1]+"//"),p.type=n.method||n.type||p.method||p.type,p.dataTypes=x.trim(p.dataType||"*").toLowerCase().match(T)||[""],null==p.crossDomain&&(r=En.exec(p.url.toLowerCase()),p.crossDomain=!(!r||r[1]===mn[1]&&r[2]===mn[2]&&(r[3]||("http:"===r[1]?"80":"443"))===(mn[3]||("http:"===mn[1]?"80":"443")))),p.data&&p.processData&&"string"!=typeof p.data&&(p.data=x.param(p.data,p.traditional)),qn(An,p,n,C),2===b)return C;l=p.global,l&&0===x.active++&&x.event.trigger("ajaxStart"),p.type=p.type.toUpperCase(),p.hasContent=!Nn.test(p.type),o=p.url,p.hasContent||(p.data&&(o=p.url+=(bn.test(o)?"&":"?")+p.data,delete p.data),p.cache===!1&&(p.url=wn.test(o)?o.replace(wn,"$1_="+vn++):o+(bn.test(o)?"&":"?")+"_="+vn++)),p.ifModified&&(x.lastModified[o]&&C.setRequestHeader("If-Modified-Since",x.lastModified[o]),x.etag[o]&&C.setRequestHeader("If-None-Match",x.etag[o])),(p.data&&p.hasContent&&p.contentType!==!1||n.contentType)&&C.setRequestHeader("Content-Type",p.contentType),C.setRequestHeader("Accept",p.dataTypes[0]&&p.accepts[p.dataTypes[0]]?p.accepts[p.dataTypes[0]]+("*"!==p.dataTypes[0]?", "+Dn+"; q=0.01":""):p.accepts["*"]);for(i in p.headers)C.setRequestHeader(i,p.headers[i]);if(p.beforeSend&&(p.beforeSend.call(f,C,p)===!1||2===b))return C.abort();w="abort";for(i in{success:1,error:1,complete:1})C[i](p[i]);if(u=qn(jn,p,n,C)){C.readyState=1,l&&d.trigger("ajaxSend",[C,p]),p.async&&p.timeout>0&&(s=setTimeout(function(){C.abort("timeout")},p.timeout));try{b=1,u.send(y,k)}catch(N){if(!(2>b))throw N;k(-1,N)}}else k(-1,"No Transport");function k(e,n,r,i){var c,y,v,w,T,N=n;2!==b&&(b=2,s&&clearTimeout(s),u=t,a=i||"",C.readyState=e>0?4:0,c=e>=200&&300>e||304===e,r&&(w=Mn(p,C,r)),w=On(p,w,C,c),c?(p.ifModified&&(T=C.getResponseHeader("Last-Modified"),T&&(x.lastModified[o]=T),T=C.getResponseHeader("etag"),T&&(x.etag[o]=T)),204===e||"HEAD"===p.type?N="nocontent":304===e?N="notmodified":(N=w.state,y=w.data,v=w.error,c=!v)):(v=N,(e||!N)&&(N="error",0>e&&(e=0))),C.status=e,C.statusText=(n||N)+"",c?h.resolveWith(f,[y,N,C]):h.rejectWith(f,[C,N,v]),C.statusCode(m),m=t,l&&d.trigger(c?"ajaxSuccess":"ajaxError",[C,p,c?y:v]),g.fireWith(f,[C,N]),l&&(d.trigger("ajaxComplete",[C,p]),--x.active||x.event.trigger("ajaxStop")))}return C},getJSON:function(e,t,n){return x.get(e,t,n,"json")},getScript:function(e,n){return x.get(e,t,n,"script")}}),x.each(["get","post"],function(e,n){x[n]=function(e,r,i,o){return x.isFunction(r)&&(o=o||i,i=r,r=t),x.ajax({url:e,type:n,dataType:o,data:r,success:i})}});function Mn(e,n,r){var i,o,a,s,l=e.contents,u=e.dataTypes;while("*"===u[0])u.shift(),o===t&&(o=e.mimeType||n.getResponseHeader("Content-Type"));if(o)for(s in l)if(l[s]&&l[s].test(o)){u.unshift(s);break}if(u[0]in r)a=u[0];else{for(s in r){if(!u[0]||e.converters[s+" "+u[0]]){a=s;break}i||(i=s)}a=a||i}return a?(a!==u[0]&&u.unshift(a),r[a]):t}function On(e,t,n,r){var i,o,a,s,l,u={},c=e.dataTypes.slice();if(c[1])for(a in e.converters)u[a.toLowerCase()]=e.converters[a];o=c.shift();while(o)if(e.responseFields[o]&&(n[e.responseFields[o]]=t),!l&&r&&e.dataFilter&&(t=e.dataFilter(t,e.dataType)),l=o,o=c.shift())if("*"===o)o=l;else if("*"!==l&&l!==o){if(a=u[l+" "+o]||u["* "+o],!a)for(i in u)if(s=i.split(" "),s[1]===o&&(a=u[l+" "+s[0]]||u["* "+s[0]])){a===!0?a=u[i]:u[i]!==!0&&(o=s[0],c.unshift(s[1]));break}if(a!==!0)if(a&&e["throws"])t=a(t);else try{t=a(t)}catch(p){return{state:"parsererror",error:a?p:"No conversion from "+l+" to "+o}}}return{state:"success",data:t}}x.ajaxSetup({accepts:{script:"text/javascript, application/javascript, application/ecmascript, application/x-ecmascript"},contents:{script:/(?:java|ecma)script/},converters:{"text script":function(e){return x.globalEval(e),e}}}),x.ajaxPrefilter("script",function(e){e.cache===t&&(e.cache=!1),e.crossDomain&&(e.type="GET",e.global=!1)}),x.ajaxTransport("script",function(e){if(e.crossDomain){var n,r=a.head||x("head")[0]||a.documentElement;return{send:function(t,i){n=a.createElement("script"),n.async=!0,e.scriptCharset&&(n.charset=e.scriptCharset),n.src=e.url,n.onload=n.onreadystatechange=function(e,t){(t||!n.readyState||/loaded|complete/.test(n.readyState))&&(n.onload=n.onreadystatechange=null,n.parentNode&&n.parentNode.removeChild(n),n=null,t||i(200,"success"))},r.insertBefore(n,r.firstChild)},abort:function(){n&&n.onload(t,!0)}}}});var Fn=[],Bn=/(=)\\?(?=&|$)|\\?\\?/;x.ajaxSetup({jsonp:"callback",jsonpCallback:function(){var e=Fn.pop()||x.expando+"_"+vn++;return this[e]=!0,e}}),x.ajaxPrefilter("json jsonp",function(n,r,i){var o,a,s,l=n.jsonp!==!1&&(Bn.test(n.url)?"url":"string"==typeof n.data&&!(n.contentType||"").indexOf("application/x-www-form-urlencoded")&&Bn.test(n.data)&&"data");return l||"jsonp"===n.dataTypes[0]?(o=n.jsonpCallback=x.isFunction(n.jsonpCallback)?n.jsonpCallback():n.jsonpCallback,l?n[l]=n[l].replace(Bn,"$1"+o):n.jsonp!==!1&&(n.url+=(bn.test(n.url)?"&":"?")+n.jsonp+"="+o),n.converters["script json"]=function(){return s||x.error(o+" was not called"),s[0]},n.dataTypes[0]="json",a=e[o],e[o]=function(){s=arguments},i.always(function(){e[o]=a,n[o]&&(n.jsonpCallback=r.jsonpCallback,Fn.push(o)),s&&x.isFunction(a)&&a(s[0]),s=a=t}),"script"):t});var Pn,Rn,Wn=0,$n=e.ActiveXObject&&function(){var e;for(e in Pn)Pn[e](t,!0)};function In(){try{return new e.XMLHttpRequest}catch(t){}}function zn(){try{return new e.ActiveXObject("Microsoft.XMLHTTP")}catch(t){}}x.ajaxSettings.xhr=e.ActiveXObject?function(){return!this.isLocal&&In()||zn()}:In,Rn=x.ajaxSettings.xhr(),x.support.cors=!!Rn&&"withCredentials"in Rn,Rn=x.support.ajax=!!Rn,Rn&&x.ajaxTransport(function(n){if(!n.crossDomain||x.support.cors){var r;return{send:function(i,o){var a,s,l=n.xhr();if(n.username?l.open(n.type,n.url,n.async,n.username,n.password):l.open(n.type,n.url,n.async),n.xhrFields)for(s in n.xhrFields)l[s]=n.xhrFields[s];n.mimeType&&l.overrideMimeType&&l.overrideMimeType(n.mimeType),n.crossDomain||i["X-Requested-With"]||(i["X-Requested-With"]="XMLHttpRequest");try{for(s in i)l.setRequestHeader(s,i[s])}catch(u){}l.send(n.hasContent&&n.data||null),r=function(e,i){var s,u,c,p;try{if(r&&(i||4===l.readyState))if(r=t,a&&(l.onreadystatechange=x.noop,$n&&delete Pn[a]),i)4!==l.readyState&&l.abort();else{p={},s=l.status,u=l.getAllResponseHeaders(),"string"==typeof l.responseText&&(p.text=l.responseText);try{c=l.statusText}catch(f){c=""}s||!n.isLocal||n.crossDomain?1223===s&&(s=204):s=p.text?200:404}}catch(d){i||o(-1,d)}p&&o(s,c,p,u)},n.async?4===l.readyState?setTimeout(r):(a=++Wn,$n&&(Pn||(Pn={},x(e).unload($n)),Pn[a]=r),l.onreadystatechange=r):r()},abort:function(){r&&r(t,!0)}}}});var Xn,Un,Vn=/^(?:toggle|show|hide)$/,Yn=RegExp("^(?:([+-])=|)("+w+")([a-z%]*)$","i"),Jn=/queueHooks$/,Gn=[nr],Qn={"*":[function(e,t){var n=this.createTween(e,t),r=n.cur(),i=Yn.exec(t),o=i&&i[3]||(x.cssNumber[e]?"":"px"),a=(x.cssNumber[e]||"px"!==o&&+r)&&Yn.exec(x.css(n.elem,e)),s=1,l=20;if(a&&a[3]!==o){o=o||a[3],i=i||[],a=+r||1;do s=s||".5",a/=s,x.style(n.elem,e,a+o);while(s!==(s=n.cur()/r)&&1!==s&&--l)}return i&&(a=n.start=+a||+r||0,n.unit=o,n.end=i[1]?a+(i[1]+1)*i[2]:+i[2]),n}]};function Kn(){return setTimeout(function(){Xn=t}),Xn=x.now()}function Zn(e,t,n){var r,i=(Qn[t]||[]).concat(Qn["*"]),o=0,a=i.length;for(;a>o;o++)if(r=i[o].call(n,t,e))return r}function er(e,t,n){var r,i,o=0,a=Gn.length,s=x.Deferred().always(function(){delete l.elem}),l=function(){if(i)return!1;var t=Xn||Kn(),n=Math.max(0,u.startTime+u.duration-t),r=n/u.duration||0,o=1-r,a=0,l=u.tweens.length;for(;l>a;a++)u.tweens[a].run(o);return s.notifyWith(e,[u,o,n]),1>o&&l?n:(s.resolveWith(e,[u]),!1)},u=s.promise({elem:e,props:x.extend({},t),opts:x.extend(!0,{specialEasing:{}},n),originalProperties:t,originalOptions:n,startTime:Xn||Kn(),duration:n.duration,tweens:[],createTween:function(t,n){var r=x.Tween(e,u.opts,t,n,u.opts.specialEasing[t]||u.opts.easing);return u.tweens.push(r),r},stop:function(t){var n=0,r=t?u.tweens.length:0;if(i)return this;for(i=!0;r>n;n++)u.tweens[n].run(1);return t?s.resolveWith(e,[u,t]):s.rejectWith(e,[u,t]),this}}),c=u.props;for(tr(c,u.opts.specialEasing);a>o;o++)if(r=Gn[o].call(u,e,c,u.opts))return r;return x.map(c,Zn,u),x.isFunction(u.opts.start)&&u.opts.start.call(e,u),x.fx.timer(x.extend(l,{elem:e,anim:u,queue:u.opts.queue})),u.progress(u.opts.progress).done(u.opts.done,u.opts.complete).fail(u.opts.fail).always(u.opts.always)}function tr(e,t){var n,r,i,o,a;for(n in e)if(r=x.camelCase(n),i=t[r],o=e[n],x.isArray(o)&&(i=o[1],o=e[n]=o[0]),n!==r&&(e[r]=o,delete e[n]),a=x.cssHooks[r],a&&"expand"in a){o=a.expand(o),delete e[r];for(n in o)n in e||(e[n]=o[n],t[n]=i)}else t[r]=i}x.Animation=x.extend(er,{tweener:function(e,t){x.isFunction(e)?(t=e,e=["*"]):e=e.split(" ");var n,r=0,i=e.length;for(;i>r;r++)n=e[r],Qn[n]=Qn[n]||[],Qn[n].unshift(t)},prefilter:function(e,t){t?Gn.unshift(e):Gn.push(e)}});function nr(e,t,n){var r,i,o,a,s,l,u=this,c={},p=e.style,f=e.nodeType&&nn(e),d=x._data(e,"fxshow");n.queue||(s=x._queueHooks(e,"fx"),null==s.unqueued&&(s.unqueued=0,l=s.empty.fire,s.empty.fire=function(){s.unqueued||l()}),s.unqueued++,u.always(function(){u.always(function(){s.unqueued--,x.queue(e,"fx").length||s.empty.fire()})})),1===e.nodeType&&("height"in t||"width"in t)&&(n.overflow=[p.overflow,p.overflowX,p.overflowY],"inline"===x.css(e,"display")&&"none"===x.css(e,"float")&&(x.support.inlineBlockNeedsLayout&&"inline"!==ln(e.nodeName)?p.zoom=1:p.display="inline-block")),n.overflow&&(p.overflow="hidden",x.support.shrinkWrapBlocks||u.always(function(){p.overflow=n.overflow[0],p.overflowX=n.overflow[1],p.overflowY=n.overflow[2]}));for(r in t)if(i=t[r],Vn.exec(i)){if(delete t[r],o=o||"toggle"===i,i===(f?"hide":"show"))continue;c[r]=d&&d[r]||x.style(e,r)}if(!x.isEmptyObject(c)){d?"hidden"in d&&(f=d.hidden):d=x._data(e,"fxshow",{}),o&&(d.hidden=!f),f?x(e).show():u.done(function(){x(e).hide()}),u.done(function(){var t;x._removeData(e,"fxshow");for(t in c)x.style(e,t,c[t])});for(r in c)a=Zn(f?d[r]:0,r,u),r in d||(d[r]=a.start,f&&(a.end=a.start,a.start="width"===r||"height"===r?1:0))}}function rr(e,t,n,r,i){return new rr.prototype.init(e,t,n,r,i)}x.Tween=rr,rr.prototype={constructor:rr,init:function(e,t,n,r,i,o){this.elem=e,this.prop=n,this.easing=i||"swing",this.options=t,this.start=this.now=this.cur(),this.end=r,this.unit=o||(x.cssNumber[n]?"":"px")},cur:function(){var e=rr.propHooks[this.prop];return e&&e.get?e.get(this):rr.propHooks._default.get(this)},run:function(e){var t,n=rr.propHooks[this.prop];return this.pos=t=this.options.duration?x.easing[this.easing](e,this.options.duration*e,0,1,this.options.duration):e,this.now=(this.end-this.start)*t+this.start,this.options.step&&this.options.step.call(this.elem,this.now,this),n&&n.set?n.set(this):rr.propHooks._default.set(this),this}},rr.prototype.init.prototype=rr.prototype,rr.propHooks={_default:{get:function(e){var t;return null==e.elem[e.prop]||e.elem.style&&null!=e.elem.style[e.prop]?(t=x.css(e.elem,e.prop,""),t&&"auto"!==t?t:0):e.elem[e.prop]},set:function(e){x.fx.step[e.prop]?x.fx.step[e.prop](e):e.elem.style&&(null!=e.elem.style[x.cssProps[e.prop]]||x.cssHooks[e.prop])?x.style(e.elem,e.prop,e.now+e.unit):e.elem[e.prop]=e.now}}},rr.propHooks.scrollTop=rr.propHooks.scrollLeft={set:function(e){e.elem.nodeType&&e.elem.parentNode&&(e.elem[e.prop]=e.now)}},x.each(["toggle","show","hide"],function(e,t){var n=x.fn[t];x.fn[t]=function(e,r,i){return null==e||"boolean"==typeof e?n.apply(this,arguments):this.animate(ir(t,!0),e,r,i)}}),x.fn.extend({fadeTo:function(e,t,n,r){return this.filter(nn).css("opacity",0).show().end().animate({opacity:t},e,n,r)},animate:function(e,t,n,r){var i=x.isEmptyObject(e),o=x.speed(t,n,r),a=function(){var t=er(this,x.extend({},e),o);(i||x._data(this,"finish"))&&t.stop(!0)};return a.finish=a,i||o.queue===!1?this.each(a):this.queue(o.queue,a)},stop:function(e,n,r){var i=function(e){var t=e.stop;delete e.stop,t(r)};return"string"!=typeof e&&(r=n,n=e,e=t),n&&e!==!1&&this.queue(e||"fx",[]),this.each(function(){var t=!0,n=null!=e&&e+"queueHooks",o=x.timers,a=x._data(this);if(n)a[n]&&a[n].stop&&i(a[n]);else for(n in a)a[n]&&a[n].stop&&Jn.test(n)&&i(a[n]);for(n=o.length;n--;)o[n].elem!==this||null!=e&&o[n].queue!==e||(o[n].anim.stop(r),t=!1,o.splice(n,1));(t||!r)&&x.dequeue(this,e)})},finish:function(e){return e!==!1&&(e=e||"fx"),this.each(function(){var t,n=x._data(this),r=n[e+"queue"],i=n[e+"queueHooks"],o=x.timers,a=r?r.length:0;for(n.finish=!0,x.queue(this,e,[]),i&&i.stop&&i.stop.call(this,!0),t=o.length;t--;)o[t].elem===this&&o[t].queue===e&&(o[t].anim.stop(!0),o.splice(t,1));for(t=0;a>t;t++)r[t]&&r[t].finish&&r[t].finish.call(this);delete n.finish})}});function ir(e,t){var n,r={height:e},i=0;for(t=t?1:0;4>i;i+=2-t)n=Zt[i],r["margin"+n]=r["padding"+n]=e;return t&&(r.opacity=r.width=e),r}x.each({slideDown:ir("show"),slideUp:ir("hide"),slideToggle:ir("toggle"),fadeIn:{opacity:"show"},fadeOut:{opacity:"hide"},fadeToggle:{opacity:"toggle"}},function(e,t){x.fn[e]=function(e,n,r){return this.animate(t,e,n,r)}}),x.speed=function(e,t,n){var r=e&&"object"==typeof e?x.extend({},e):{complete:n||!n&&t||x.isFunction(e)&&e,duration:e,easing:n&&t||t&&!x.isFunction(t)&&t};return r.duration=x.fx.off?0:"number"==typeof r.duration?r.duration:r.duration in x.fx.speeds?x.fx.speeds[r.duration]:x.fx.speeds._default,(null==r.queue||r.queue===!0)&&(r.queue="fx"),r.old=r.complete,r.complete=function(){x.isFunction(r.old)&&r.old.call(this),r.queue&&x.dequeue(this,r.queue)},r},x.easing={linear:function(e){return e},swing:function(e){return.5-Math.cos(e*Math.PI)/2}},x.timers=[],x.fx=rr.prototype.init,x.fx.tick=function(){var e,n=x.timers,r=0;for(Xn=x.now();n.length>r;r++)e=n[r],e()||n[r]!==e||n.splice(r--,1);n.length||x.fx.stop(),Xn=t},x.fx.timer=function(e){e()&&x.timers.push(e)&&x.fx.start()},x.fx.interval=13,x.fx.start=function(){Un||(Un=setInterval(x.fx.tick,x.fx.interval))},x.fx.stop=function(){clearInterval(Un),Un=null},x.fx.speeds={slow:600,fast:200,_default:400},x.fx.step={},x.expr&&x.expr.filters&&(x.expr.filters.animated=function(e){return x.grep(x.timers,function(t){return e===t.elem}).length}),x.fn.offset=function(e){if(arguments.length)return e===t?this:this.each(function(t){x.offset.setOffset(this,e,t)});var n,r,o={top:0,left:0},a=this[0],s=a&&a.ownerDocument;if(s)return n=s.documentElement,x.contains(n,a)?(typeof a.getBoundingClientRect!==i&&(o=a.getBoundingClientRect()),r=or(s),{top:o.top+(r.pageYOffset||n.scrollTop)-(n.clientTop||0),left:o.left+(r.pageXOffset||n.scrollLeft)-(n.clientLeft||0)}):o},x.offset={setOffset:function(e,t,n){var r=x.css(e,"position");"static"===r&&(e.style.position="relative");var i=x(e),o=i.offset(),a=x.css(e,"top"),s=x.css(e,"left"),l=("absolute"===r||"fixed"===r)&&x.inArray("auto",[a,s])>-1,u={},c={},p,f;l?(c=i.position(),p=c.top,f=c.left):(p=parseFloat(a)||0,f=parseFloat(s)||0),x.isFunction(t)&&(t=t.call(e,n,o)),null!=t.top&&(u.top=t.top-o.top+p),null!=t.left&&(u.left=t.left-o.left+f),"using"in t?t.using.call(e,u):i.css(u)}},x.fn.extend({position:function(){if(this[0]){var e,t,n={top:0,left:0},r=this[0];return"fixed"===x.css(r,"position")?t=r.getBoundingClientRect():(e=this.offsetParent(),t=this.offset(),x.nodeName(e[0],"html")||(n=e.offset()),n.top+=x.css(e[0],"borderTopWidth",!0),n.left+=x.css(e[0],"borderLeftWidth",!0)),{top:t.top-n.top-x.css(r,"marginTop",!0),left:t.left-n.left-x.css(r,"marginLeft",!0)}}},offsetParent:function(){return this.map(function(){var e=this.offsetParent||s;while(e&&!x.nodeName(e,"html")&&"static"===x.css(e,"position"))e=e.offsetParent;return e||s})}}),x.each({scrollLeft:"pageXOffset",scrollTop:"pageYOffset"},function(e,n){var r=/Y/.test(n);x.fn[e]=function(i){return x.access(this,function(e,i,o){var a=or(e);return o===t?a?n in a?a[n]:a.document.documentElement[i]:e[i]:(a?a.scrollTo(r?x(a).scrollLeft():o,r?o:x(a).scrollTop()):e[i]=o,t)},e,i,arguments.length,null)}});function or(e){return x.isWindow(e)?e:9===e.nodeType?e.defaultView||e.parentWindow:!1}x.each({Height:"height",Width:"width"},function(e,n){x.each({padding:"inner"+e,content:n,"":"outer"+e},function(r,i){x.fn[i]=function(i,o){var a=arguments.length&&(r||"boolean"!=typeof i),s=r||(i===!0||o===!0?"margin":"border");return x.access(this,function(n,r,i){var o;return x.isWindow(n)?n.document.documentElement["client"+e]:9===n.nodeType?(o=n.documentElement,Math.max(n.body["scroll"+e],o["scroll"+e],n.body["offset"+e],o["offset"+e],o["client"+e])):i===t?x.css(n,r,s):x.style(n,r,i,s)},n,a?i:t,a,null)}})}),x.fn.size=function(){return this.length},x.fn.andSelf=x.fn.addBack,"object"==typeof module&&module&&"object"==typeof module.exports?module.exports=x:(e.jQuery=e.$=x,"function"==typeof define&&define.amd&&define("jquery",[],function(){return x}))})(window);';
}
