<?php namespace Zhimei\LaravelWechat\WechatLib;

use Zhimei\LaravelWechat\WechatLib\WechatPub;
use Illuminate\Support\Facades\Cache;

trait JSSDK extends WechatPub{
	private $appId;
	private $appSecret;

	public function __construct($appId, $appSecret) {
		$this->appId = $appId;
		$this->appSecret = $appSecret;
	}

	public function getSignPackage() {
		$jsapiTicket = $this->getJsApiTicket();

		// 注意 URL 一定要动态获取，不能 hardcode.
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
		$url = $protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];

		$timestamp = time();
		$nonceStr = $this->createNonceStr();

		// 这里参数的顺序要按照 key 值 ASCII 码升序排序
		$string = "jsapi_ticket=$jsapiTicket&noncestr=$nonceStr&timestamp=$timestamp&url=$url";

		$signature = sha1($string);

		$signPackage = array(
					"appId"     => $this->appId,
					"nonceStr"  => $nonceStr,
					"timestamp" => $timestamp,
					"url"       => $url,
					"signature" => $signature,
					"rawString" => $string
				);
		return $signPackage; 
	}

	private function createNonceStr($length = 16) {
		$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
		$str = "";
		for ($i = 0; $i < $length; $i++) {
			$str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
		}
		return $str;
	}

	private function getJsApiTicket() {
		// jsapi_ticket 应该全局存储与更新，以下代码以写入到文件中做示例
		$cacheKey = 'jsapi_ticket_'.$this->appId;
        $jsapi_ticket = Cache::get($cacheKey,function(){return false;});
		if ($jsapi_ticket) {
			return $jsapi_ticket;
		}else{
			$accessToken = $this->getAccessToken();
			// 如果是企业号用以下 URL 获取 ticket
			// $url = "https://qyapi.weixin.qq.com/cgi-bin/get_jsapi_ticket?access_token=$accessToken";
			$url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?type=jsapi&access_token=$accessToken";
			$res = json_decode($this->httpGet($url));
			$jsapi_ticket = $res->ticket;
			if ($jsapi_ticket) {
				Cache::put($cacheKey, $jsapi_ticket, Carbon::now()->addMinutes(100));
                return $jsapi_ticket;
			}
		}

		return false;
	}

}