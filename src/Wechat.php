<?php namespace Zhimei\LaravelWechat;

use Illuminate\Support\Facades\Schema;
use Zhimei\LaravelWechat\WechatLib\JSSDK;
use Zhimei\LaravelWechat\WechatLib\WechatPub;
use Illuminate\Support\Facades\Request;

class Wechat extends WechatLib {

	/**
     * 静态实例
     *
     * @var \Zhimei\LaravelWechat\Wechat
     */
    static private $_instance;

	static private $_token = 'Wechat_PHP_Token';

    /**
     * 获取实例
     *
     * @param array|null $options
     */
    public function __construct($options = 'default')
    {
        $opt = is_array($options)?$options:Config::get('wechat::wechat.'.$options);
        $this->token            = isset($opt['token'])?$opt['token']:'';
        $this->encodingAesKey   = isset($opt['encodingaeskey'])?$opt['encodingaeskey']:'';
        $this->appid            = isset($opt['appid'])?$opt['appid']:'';
        $this->appsecret        = isset($opt['appsecret'])?$opt['appsecret']:'';
        $this->debug            = isset($opt['debug'])?$opt['debug']:false;
        $this->logcallback      = isset($opt['logcallback'])?$opt['logcallback']:false;
		parent::__construct($options);
    }

	/**
     * 设置缓存，按需重载
     * @param string $cachename
     * @param mixed $value
     * @param int $expired
     * @return boolean
     */
    protected function setCache($cachename,$value,$expired){
        Cache::put($cachename,$value,$expired/60);
        return false;
    }

    /**
     * 获取缓存，按需重载
     * @param string $cachename
     * @return mixed
     */
    protected function getCache($cachename){
        return Cache::get($cachename);
        // return false;
    }

    /**
     * 清除缓存，按需重载
     * @param string $cachename
     * @return boolean
     */
    protected function removeCache($cachename){
        Cache::forget($cachename);
        return false;
    }

	/**
     * 日志记录，可被重载。
     * @param mixed $log 输入日志
     * @return mixed
     */
    protected function log($log){
            if ($this->debug && function_exists($this->logcallback)) {
                if (is_array($log)) $log = print_r($log,true);
                return call_user_func($this->logcallback,$log);
            }
    }


	/*
     * 驗證消息真實性
     */
	public function valid(){
		$echoStr = Request::input("echostr");
		$signature = Request::input("signature");
        $timestamp = Request::input("timestamp");
        $nonce = Request::input("nonce");
		$token = $this->getValidToken();
		$tmpArr = array($token, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);
		$tmpStr = implode( $tmpArr );
		$tmpStr = sha1( $tmpStr );
		
		if( $tmpStr == $signature ){
			return $echoStr;
		}else{
			return '';
		}
	}
    public  function getValidToken(){
        return self::$_token;
    }




    

	/**
     * 获取实例
     *
     * @return \Zhimei\LaravelWechat\Wechat
     */
    static public function make(array $options = null)
    {
        if(! (self::$_instance instanceof self)) {
            self::$_instance = new self($options);
        }
        return self::$_instance;

    }

}
