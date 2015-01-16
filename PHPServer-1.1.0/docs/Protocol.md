# 通讯协议规范

新 RPC 服务器采用自定义文本协议，并满足以下条件:

1. 可以在各种语言中简单的实现
2. 能被快速解析
3. 方便 Telnet 调试

相对于二进制协议，新 RPC 服务器的文本协议议具有较高的可读性，也容易被实现，
减少在客户端实现中出现BUG，并且解析性可以做到与二进制协议接近。


## 网络层

1. 客户端与服务器端用 TCP 协议建立链接
2. 每行命令和数据用一个 \n (LF) 来表示结束


## 请求数据

一个完整的请求由 RPC 命令和 RPC 数据组成

组成结构:

    <number of bytes of command> LF
    <command> LF
    <number of bytes of data> LF
    <data> LF

例子:

    3
    RPC
    108
    {"version": "2.0", "class": "JMClient_Package_ConcreteClass", "method": "concreteMethod", "params": ["arg1", "arg2"]}

下面是以上例子在网络数据流中的样子:

    '3\nRPC\n108\n{"version": "2.0", "class": "JMClient_Package_ConcreteClass", "method": "concreteMethod", "params": ["arg1", "arg2"]}\n'

(备注)在 Telnet 调试时可以使用 `?` 代替数据长度数值，如:

    ?
    RPC
    ?
    {"version": "2.0", "class": "JMClient_Package_ConcreteClass", "method": "concreteMethod", "params": ["arg1", "arg2"]}

这个数据组成方式同样用于返回数据，但返回数据不包含 RPC 命令部分


## 返回数据

例子:

    16
    {"result": "OK"}

下面是以上例子在网络数据流中的样子:

    '16\n{"result": "OK"}\n'

