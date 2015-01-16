<?php
namespace Config;


class PHPClient {
    public $rpc_secret_key = '769af463a39f077a0340a189e9c1ec28';
    public $monitor_log_dir = '/tmp/monitor-log';
    public $User = array(
                    'uri' => 'tcp://192.168.20.95:2201',
                    'user' => 'Optool',
                    'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        //'compressor' => 'GZ',
                    );
    public $Payment = array(
                    'uri' => 'tcp://127.0.0.1:2201',
                    'user' => 'Payment',
                    'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                    'ver' => 1.0
                        //'compressor' => 'GZ',
                    );
    public $inventoryService = array(
                    'uri' => 'tcp://192.168.20.95:5203',
                    'user' => 'test',
                    'secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        //'compressor' => 'GZ',
                    );
    /**
     * 旧的HTTP rpcserver服务配置.
     * @var array
     */
    public $Rpc = array('default' => array (
                                'Url' => 'http://ws.jumeird.com/rpc.php',
                                'User' => 'Koubei',
                                'Secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}'
                                ),
                        'koubei' => array(
                            'Url' => 'http://rpc.koubei.jumeicd.com/rpc.php',
                            'User' => 'Jumei',
                            'Secret' => '{1BA09530-F9E6-478D-9965-7EB31A59537E}',
                        )
    );

} 
