<?php namespace Zhimei\LaravelWechat\WechatLib;

class  SDKRuntimeException extends Exception {
	public function errorMessage()
	{
		return $this->getMessage();
	}

}

?>