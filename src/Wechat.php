<?php namespace Zhimei\LaravelWechat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Request;

class Wechat extends WechatLib {

	/**
     * 静态实例
     *
     * @var \Zhimei\LaravelWechat\Wechat
     */
    static private $_instance;

	static private $_token = 'Wechat_PHP_Token';

	public $listeners = [];

    protected $option = null;
    const MCH_URL = "https://api.mch.weixin.qq.com";
    const UNIFIEDORDER_URL = "/pay/unifiedorder";

    /**
     * 获取实例
     *
     * @param array|null $options
     */
    public function __construct()
    {
        $options = Cache::rememberForever('Wechat_Public_Config', function()
        {
            try {
                $conf = \App\Models\WechatPublicConfig::first();
                if($conf){
                    return ['appid'=>$conf->app_id, 'appsecret'=>$conf->app_secret, 'encodingAesKey'=>$conf->encodingaeskey,
                        'mchid'=>$conf->mchid, 'mchkey'=>$conf->mchkey];
                }else
                    new \Exception();
            }catch (\Exception $e){
                return Config::get('wechat.default', []);
            }
        });

        $options['token']        = self::$_token;
        $options['debug']        = false;
        $options['logcallback'] = false;
        $this->option = $options;
		parent::__construct($options);

    }


	public function getAccessToken($appid='',$appsecret='',$token='') {
		return $this->checkAuth($appid,$appsecret,$token);
	}

    /**
     * 创建二维码ticket
     * @param int $scene_id 自定义追踪id
     * @param int $type 0:临时二维码；1:永久二维码(此时expire参数无效)；2 :永久二维码（字符串参数值）(此时expire参数无效)
     * @param int $expire 临时二维码有效期，最大为1800秒
     * @return array('ticket'=>'qrcode字串','expire_seconds'=>1800,'url'=>'二维码图片解析后的地址')
     */
    public function getQRCode($scene_id,$type=0,$expire=1800){
        if (!$this->checkAuth()) return false;
        $action_name = 'QR_LIMIT_SCENE';
        $scene_id_key = 'scene_id';
        if($type==1){
            $action_name = 'QR_SCENE';
        }elseif($type==2){
            $action_name = 'QR_LIMIT_STR_SCENE';
            $scene_id_key = 'scene_str';
        }
        $data = array(
            'action_name'=>$action_name,
            'expire_seconds'=>$expire,
            'action_info'=>array('scene'=>array($scene_id_key=>$scene_id))
        );
        if ($type == 1) {
            unset($data['expire_seconds']);
        }
        $result = $this->httpPost(self::API_URL_PREFIX.self::QRCODE_CREATE_URL.'access_token='.$this->getAccessToken(),self::json_encode($data));
        if ($result)
        {
            $json = json_decode($result,true);
            if (!$json || !empty($json['errcode'])) {
                $this->errCode = $json['errcode'];
                $this->errMsg = $json['errmsg'];
                return false;
            }
            return $json;
        }
        return false;
    }

	/**
     * 	网页授权, 通过curl向微信提交code，以获取openid
     *  微信官方后台需设置授权回调页面域名
     */
    function getOpenid()
    {
        $sessionKey = 'Openid_'.$this->option['appid'];
        $openid = Request::input('openid');
        if($openid) {
            session([$sessionKey => $openid]);
            return $openid;
        }
        $openid = Session::get($sessionKey, function() { return false; });
        if($openid)
            return $openid;
        if(!isWeixinBrowser())
            return -1;

        $data = $this->getOpenidAndAccessTokenFromAuth();
        if(isset($data['openid'])) {
            session([$sessionKey => $data['openid']]);
            return $data['openid'];
        }
        return -1;
    }
    /*
     * 网页授权获取用户基本信息
     */
    function getAtuhUserInfo(){

        $data = $this->getOpenidAndAccessTokenFromAuth('snsapi_userinfo');
        if(!empty($data)) {
            $url = self::API_BASE_URL_PREFIX.self::OAUTH_USERINFO_URL.'access_token='.$data['access_token'].'&openid='.$data['openid'].'&lang=zh_CN';
            $content = $this->httpGet($url);
            $content = json_decode($content, true);
            return $content;
        }
        return [];
    }
    /**
     * 	作用：生成可以获得openid的url
     */
    private function getOpenidAndAccessTokenFromAuth($scope='snsapi_base')
    {
        if(Request::input( 'code' )){
            $param ['appid'] = $this->option['appid'];
            $param ['secret'] = $this->option['appsecret'];
            $param ['code'] = Request::input( 'code' );
            $param ['grant_type'] = 'authorization_code';

            $url = self::API_BASE_URL_PREFIX . self::OAUTH_TOKEN_URL . http_build_query ( $param );
            $content = $this->httpGet ( $url );
            $content = json_decode ( $content, true );
            return $content;
        }elseif(Request::input('state')){
            return [];
        }else{
            $param ['appid'] = $this->option['appid'];
            $param ['redirect_uri'] = Request::fullUrl();
            $param ['response_type'] = 'code';
            $param ['scope'] = $scope;
            $param ['state'] = 123;
            $url = self::OAUTH_PREFIX . self::OAUTH_AUTHORIZE_URL . http_build_query ( $param ) . '#wechat_redirect';
            header("Location:".$url);
            exit;
        }

    }

    /*
     * JSAPI支付——H5网页端调起支付接口
     * @param string          $out_trade_no  客户订单号
     * @param string           $body  商品或支付单简要描述
     * @param callable        $total_fee 订单总金额，只能为整数
     * @param callable        $notify_url 接收微信支付异步通知回调地址
     * @param callable        $trade_type  取值如下：JSAPI，NATIVE，APP，WAP,详细说明见
     */
    public function getPayPrepayId($out_trade_no, $body, $total_fee, $notify_url, $trade_type="JSAPI"){

        $parameters["openid"] = $this->getOpenid();
        $parameters["out_trade_no"] = $out_trade_no;
        $parameters["body"] = $body;
        $parameters["total_fee"] = $total_fee;
        $parameters["notify_url"] = $notify_url;
        $parameters["trade_type"] = $trade_type;
        $parameters["appid"] = $this->option['appid'];//公众账号ID
        $parameters["mch_id"] = $this->option['mchid'];//商户号
        $parameters["spbill_create_ip"] = $_SERVER['REMOTE_ADDR'];//终端ip
        $parameters["nonce_str"] = $this->generateNonceStr();//随机字符串
        $parameters["sign"] = $this->getPaySign($parameters);;//签名
        $xml = $this->xml_encode($parameters);
        $data = $this->httpPost(self::MCH_URL.self::UNIFIEDORDER_URL ,$xml);
        $arr = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if(isset($arr['prepay_id']))
            return $arr['prepay_id'];
        return false;
    }
    public function getPayJsSign($prePayId)
    {
        $jsApiObj["appId"] = $this->option['appid'];//公众账号ID
        $jsApiObj["timeStamp"] = time();
        $jsApiObj["nonceStr"] = $this->generateNonceStr();
        $jsApiObj["package"] = "prepay_id=".$prePayId;
        $jsApiObj["signType"] = "MD5";//var_dump($jsApiObj);
        $jsApiObj["paySign"] = $this->getPaySign($jsApiObj);//签名
        return $jsApiObj;
    }
    private function getPaySign($parameters){
        ksort($parameters);
        $paramstring = "";
        foreach($parameters as $key => $value)
        {
            $paramstring .= $key . "=" . $value."&";
        }
        $paramstring .= "key=" . $this->option['mchkey']; //商户号;
        $paramstring = strtoupper(md5($paramstring));
        return $paramstring;
    }

	/**
     * 监听
     *
     * @param string          $target (event|message)
     * @param string|callable $type
     * @param callable        $callback
     *
     * @return Server
     */
	public function on($target, $type, $callback = null){
		if (is_null($callback)) {
            $callback = $type;
            $type     = '*';
        }
		if (!is_callable($callback))
			return false;
		if($target=='event')
			$this->event($type,$callback);
		elseif($target=='message')
			$this->msg($type,$callback);
	}
	/**
     * 监听事件
     *
     * @param string|callable $type
     * @param callable        $callback
     *
     * @return Server
     */
    public function event($type, $callback = null)
    {
        array_push($this->listeners, ['type'=>'event.'.$type, 'callback'=>$callback]);
    }
    /**
     * 监听消息
     *
     * @param string|callable $type
     * @param callable        $callback
     *
     * @return Server
     */
    public function msg($type, $callback = null)
    {
        array_push($this->listeners, ['type'=>'message.'.$type, 'callback'=>$callback]);
    }
	/**
     * handle服务端并返回字符串内容
     *
     * @return mixed
     */
    public function run(){
        parent::valid();
		$messgType = $this->getRev()->getRevType();
        if(empty($messgType))return;
		$event = $this->getRevEvent();
		foreach($this->listeners as $arr){
			$arrType = explode('.',$arr['type']);
			if($messgType==self::MSGTYPE_EVENT && ($event['event']==$arrType[1] || $arrType[1]=='*')){
				$arr['callback']($this);
				return true;
			}
			if($arrType[0] == 'message' && ($messgType==$arrType[1] || $arrType[1]=='*')){
                $arr['callback']($this);
				return true;
			}
		}
		echo '';
		return false;
	}

	private function eventReply_will_delete($callback,$event){

        switch ($this->getRev()->getRevType()) {
            //文本
            case self::MSGTYPE_TEXT:
                break;
            //图像
            case self::MSGTYPE_IMAGE:
                break;
            //语音
            case self::MSGTYPE_VOICE:
                break;
            //视频
            case self::MSGTYPE_VIDEO:
                break;
            //小视频
            case 'shortvideo':
                break;
            //位置
            case self::MSGTYPE_LOCATION:
                break;
            //链接
            case self::MSGTYPE_LINK:
                break;
            //事件
            case self::MSGTYPE_EVENT:
                $event = $this->getRevEvent();
                switch ($event['event']) {
                    //关注
                    case self::EVENT_SUBSCRIBE:
                        //二维码关注
                        if(isset($event['key']) && $this->getRevTicket()){
                        //普通关注
                        }else{
                        }
                        break;
                    //扫描二维码
                    case self::EVENT_SCAN:
                        break;
                    //地理位置
                    case self::EVENT_LOCATION:
                        break;
                    //自定义菜单 - 点击菜单拉取消息时的事件推送
                    case self::EVENT_MENU_CLICK:
                        break;
                    //自定义菜单 - 点击菜单跳转链接时的事件推送
                    case self::EVENT_MENU_VIEW:
                        break;
                    //自定义菜单 - 扫码推事件的事件推送
                    case 'scancode_push':
                        break;
                    //自定义菜单 - 扫码推事件且弹出“消息接收中”提示框的事件推送
                    case 'scancode_waitmsg':
                        break;
                    //自定义菜单 - 弹出系统拍照发图的事件推送
                    case 'pic_sysphoto':
                        break;
                    //自定义菜单 - 弹出拍照或者相册发图的事件推送
                    case 'pic_photo_or_album':
                        break;
                    //自定义菜单 - 弹出微信相册发图器的事件推送
                    case 'pic_weixin':
                        break;
                    //自定义菜单 - 弹出地理位置选择器的事件推送
                    case 'location_select':
                        break;
                    //取消关注
                    case 'unsubscribe':
                        break;
                    //群发接口完成后推送的结果
                    case 'masssendjobfinish':
                        break;
                    //模板消息完成后推送的结果
                    case 'templatesendjobfinish':
                        break;
                    default:
                        $this->text("收到未知的消息，我不知道怎么处理")->reply();
                        break;
                }
                break;
            default:
                $this->text("收到未知的消息，我不知道怎么处理")->reply();
                break;
        }
    }

	public function httpGet($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_URL, $url);

        $res = curl_exec($curl);
        curl_close($curl);

        return $res;
    }
    function httpPost($url,$param,$post_file=false){
        $oCurl = curl_init();
        if(stripos($url,"https://")!==FALSE){
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
            //以下两种方式需选择一种
            //第一种方法，cert 与 key 分别属于两个.pem文件
            //默认格式为PEM，可以注释
            //curl_setopt($oCurl,CURLOPT_SSLCERTTYPE,'PEM');
            //curl_setopt($oCurl,CURLOPT_SSLCERT,getcwd().'/cert.pem');
            //默认格式为PEM，可以注释
            //curl_setopt($oCurl,CURLOPT_SSLKEYTYPE,'PEM');
            //curl_setopt($oCurl,CURLOPT_SSLKEY,getcwd().'/private.pem');
            //第二种方式，两个文件合成一个.pem文件
            //curl_setopt($oCurl,CURLOPT_SSLCERT,getcwd().'/all.pem');
        }
        if (is_string($param) || $post_file) {
            $strPOST = $param;
        } else {
            $aPOST = array();
            foreach($param as $key=>$val){
                $aPOST[] = $key."=".urlencode($val);
            }
            $strPOST =  join("&", $aPOST);
        }
        curl_setopt($oCurl, CURLOPT_TIMEOUT, 60);
        curl_setopt($oCurl, CURLOPT_URL, $url);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($oCurl, CURLOPT_POST,true);
        curl_setopt($oCurl, CURLOPT_POSTFIELDS,$strPOST);
        $sContent = curl_exec($oCurl);
        $aStatus = curl_getinfo($oCurl);
        curl_close($oCurl);
        if(intval($aStatus["http_code"])==200){
            return $sContent;
        }else{
            return false;
        }
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

    /*
     * 打开调试
     */
    public function debug($open=1){
        $this->debug = $open;
    }
    public function setLogCallback($func){
        $this->logcallback = $func;
    }

	/**
     * 日志记录，可被重载。
     * @param mixed $log 输入日志
     * @return mixed
     */
    protected function log($log){
            if ($this->debug && is_callable($this->logcallback)) {
                if (is_array($log)) $log = print_r($log,true);
                return call_user_func($this->logcallback,$log);
            }
    }

    public function getError(){
        return $this->errMsg;
    }


	/*
     * 驗證消息真實性
     */
	public function valid($return=true){
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
    static public function make()
    {
        if(! (self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;

    }

}
