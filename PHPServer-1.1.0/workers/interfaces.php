<?php

interface IWorker
{
    public function dealInput($recv_str);
    public function dealProcess($recv_str);
}
