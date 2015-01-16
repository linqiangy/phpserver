<?php

/**
 * 
 * 压测worker
 * @author liangl
 * 
 */

class EchoWorker extends PHPServerWorker
{
    public function dealInput($recv_str)
    {
        return 0; 
    }

    public function dealProcess($recv_str)
    {
        $this->sendToClient($recv_str);
    }
}
