<?php

class Controller_Query extends Controller_OptoolBase
{

    public function action_Test()
    {
        header('Content-Type: text/plain; charset=utf-8');

        // {{ 示例代码:
        // 方法一(推荐)。如果是老的客户端(不使用handler规范),需在配置中加上小于2的版本号，如：'ver'=>1.0, 参考example中的config文件。
        $data = \PHPClient\Text::inst('User')->setClass('Info')->byUid(5100);

        // 方法二(兼容老版本)
        $userInfo = RpcClient_User_Info::instance();

        $result = $userInfo->byUid(5100);
        var_dump($result);

        $result = $userInfo->getInfoByUid(1373);
        var_dump($result);

        // }}
    }
}
