版本：1.1.3
发布日期：2014-08-24
特性更新：
2014-08-24 thrift客户端text协议支持故障ip踢出，支持按照项目配置客户端，修复types.php中的类调用前没加载问题
2014-07-08 支持owl_context上下文,支持日志追踪、异常记录日志
2014-06-06 客户端支持文本Text协议 ，设置文本协议的方法，设置protocol字段为text 注意：使用text协议时无法使用客户端异步

RPC通用客户端,  
 * 支持异步调用 注意：使用text协议时无法使用客户端异步 
 * 支持故障ip自动踢出  
 * 支持故障ip自动探测及恢复  

具体使用实例
=======
  
见[PHP Service 客户端使用实例](http://wiki.int.jumei.com/index.php?title=PHP_Service_%E5%AE%A2%E6%88%B7%E7%AB%AF%E4%BD%BF%E7%94%A8%E5%AE%9E%E4%BE%8B)



