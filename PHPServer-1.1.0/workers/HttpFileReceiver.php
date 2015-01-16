<?php

/**
 * 
 * FileReceiver http 协议
 * @author liangl
 * 
 */
class HttpFileReceiver extends PHPServerWorker
{
	/**
	 * 获得业务文件（用于处理上传的数据）
	 * @see PHPServerWorker::onServe()
	 */
	public function onServe()
	{
		$this->dealUploadFile = PHPServerConfig::get('workers.'.$this->serviceName.'.bootstrap');
	}
	
	/**
     * $_FILES = ['file_name':'xxx', 'file_data':'xxx'],['file_name':....], ....]
	 */
	public function dealInput($http_buffer)
	{
		// ====http 头还没接收全，继续等待数据====
		if(!strpos($http_buffer, "\r\n\r\n"))
		{
			return 1;
		}
		// ====http头全部就收到了，解析http头====
		list($http_header, $http_body) = explode("\r\n\r\n", $http_buffer, 2);
		$http_header_array = explode("\r\n",$http_header);
		list($http_method, $http_request_uri, $http_protocol_version) = explode(' ', $http_header_array[0]);
		if('POST' !== $http_method)
		{
			$this->sendToClient("HTTP/1.1 404 Not Found\r\nServer: PHPServer\r\n\r\n<html><body><center style=\"font-size:18;font-weight:bold\">404 not found</center></body></html>");
			return $this->closeClient($this->currentDealFd);
		}
		unset($http_header_array[0]);
		
		foreach($http_header_array as $item)
		{
			list($key, $value) = explode(': ', $item, 2);
			switch($key)
			{
				// Content-Length
				case 'Content-Length':
					$http_content_length = $value;
					// body没收全
					if(strlen($http_body) < $http_content_length)
					{
						return 1;
					}
					break;
				// boundary
				case 'Content-Type':
					if(!preg_match("/boundary=(\S+)/", $value, $match))
					{
						$this->sendToClient("HTTP/1.1 400 bad request\r\nServer: PHPServer\r\n\r\n<html><body><center style=\"font-size:18;font-weight:bold\">400 bad request (code:1)</center></body></html>");
						return $this->closeClient($this->currentDealFd);
					}
					$http_post_boundary = '--'.$match[1];
					break;
				// $_COOKIE
				case 'Cookie':
					parse_str(str_replace('; ', '&', $value), $_COOKIE);
					break;
			}
		}
		
		// 400 bad request
		if(!isset($http_post_boundary) || !isset($http_content_length))
		{
			$this->sendToClient("HTTP/1.1 400 bad request\r\nServer: PHPServer\r\n\r\n<html><body><center style=\"font-size:18;font-weight:bold\">400 bad request (code:2)</center></body></html>");
			$this->closeClient($this->currentDealFd);
		}
		
		$_FILES = array();
		
		// $_GET
		parse_str(parse_url($http_request_uri, PHP_URL_QUERY), $_GET);
		
		// 去掉最后一个boundary--\r\n
		$http_body = substr($http_body, 0, $http_content_length - (strlen($http_post_boundary) + 4));
		
		// boundary data
		$boundary_data_array = explode($http_post_boundary."\r\n", $http_body);
		if($boundary_data_array[0] === '')
		{
			unset($boundary_data_array[0]);
		}
		foreach($boundary_data_array as $boundary_data_buffer)
		{
			list($boundary_header_buffer, $boundary_value) = explode("\r\n\r\n", $boundary_data_buffer, 2);
			// 去掉末尾\r\n
			$boundary_value = substr($boundary_value, 0, -2);
			foreach (explode("\r\n", $boundary_header_buffer) as $item)
			{
				list($header_key, $header_value) = explode(": ", $item);
				switch ($header_key)
				{
					case "Content-Disposition":
						// 是文件
						if(preg_match('/name=".*?"; filename="(.*?)"$/', $header_value, $match))
						{
							$_FILES[] = array(
								'file_name' => $match[1],
								'file_data' => $boundary_value,
							);
							continue;
						}
						// 是post field
						else
						{
							// 收集post
							if(preg_match('/name="(.*?)"$/', $header_value, $match))
							{
								$_POST[$match[1]] = $boundary_value;
							}
						}
						break;
				}
			}
		}
		return 0;
	}
	
	/**
	 * 处理上传数据
	 * @see PHPServerWorker::dealProcess()
	 */
    public function dealProcess($http_buffer)
    {
    	ob_start();
    	try
    	{
    		// 加载业务文件，处理上传
    		include $this->dealUploadFile;
    	}
    	catch(Exception $e)
    	{
    		$contents = ob_get_clean();
    		$this->sendToClient("HTTP/1.1 500 Internal Server Error\r\nServer: PHPServer\r\n\r\n<html><body>500 Internal Server Error</body></html>");
    		$this->closeClient($this->currentDealFd);
    		$this->notice($e);
    		return;
    	}
    	$contents = ob_get_clean();
    	$this->sendToClient("HTTP/1.1 200 OK\r\nServer: PHPServer\r\n\r\n$contents");
    	$this->closeClient($this->currentDealFd);
    }
    
}
