<?php
/**
 * 文本协议.
 *
 * @author Xiangheng Li <xianghengl@jumei.com>
 */

/**
 * 新 RPC 框架简单文本协议.
 */
class Text implements IProtocol
{

    /**
     * 处理数据流.
     *
     * @param string $data 数据流.
     *
     * @return integer|boolean
     */
    public static function input($data)
    {
        $parser = new TextParser($data);
        if (($cmdlen = $parser->getLength()) === null) {
            return 1;
        }
        if (($cmd = $parser->getData($cmdlen)) === null) {
            return 1;
        }
        if (($datalen = $parser->getLength()) === null) {
            return 1;
        }
        if (($data = $parser->getData($datalen)) === null) {
            return 1;
        }
        if (!preg_match('/^(\d+|\?)$/', $cmdlen)) {
            return false;
        }
        if (!preg_match('/^(\d+|\?)$/', $datalen)) {
            return false;
        }
        if ($cmdlen !== '?' && $cmdlen != strlen($cmd)) {
            return 1;
        }
        if ($datalen !== '?' && $datalen != strlen($data)) {
            return 1;
        }
        return 0;
    }

    /**
     * 解码数据流.
     *
     * @param string $data 数据流.
     *
     * @return array
     */
    public static function decode($data)
    {
        $parser = new TextParser($data);

        $cmdlen = $parser->getLength();
        $cmd = $parser->getData($cmdlen);
        $datalen = $parser->getLength();
        $data = $parser->getData($datalen);

        $ctx = array(
            'command' => $cmd,
            'data' => $data,
        );

        return $ctx;
    }

    /**
     * 编码数据流.
     *
     * @param string $data 数据流.
     *
     * @return string
     */
    public static function encode($data)
    {
        return sprintf("%d\n%s\n", strlen($data), $data);
    }

}
