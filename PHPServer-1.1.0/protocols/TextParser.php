<?php
/**
 * 文本协议解析器.
 *
 * @author Xiangheng Li <xianghengl@jumei.com>
 */

/**
 * 文本协议解析器.
 */
class TextParser
{

    /**
     * 数据流.
     */
    private $buffer;

    /**
     * 解析偏移位置.
     */
    private $offset;

    /**
     * Bla bla bal ...
     *
     * @param string $buffer 数据流.
     */
    public function __construct($buffer)
    {
        $this->buffer = $buffer;
        $this->offset = 0;
    }

    /**
     * 解析后续数据长度.
     *
     * @return integer|string
     */
    public function getLength()
    {
        if ($this->offset < strlen($this->buffer)) {
            $position = strpos($this->buffer, "\n", $this->offset);
            $length = substr($this->buffer, $this->offset, $position - $this->offset);
            $this->offset = $position + 1;
            return trim($length);
        }
    }

    /**
     * 解析数据体.
     *
     * @param integer|string $length 数据长度, 为 '?' 时读取数据直到出现 "\n".
     *
     * @return string
     */
    public function getData($length)
    {
        if ($this->offset < strlen($this->buffer)) {
            if ($length === '?') {
                $position = strpos($this->buffer, "\n", $this->offset);
            } else {
                $position = $this->offset + $length;
            }
            $data = substr($this->buffer, $this->offset, $position - $this->offset);
            $this->offset = $position + 1;
            return $data;
        }
    }

}
