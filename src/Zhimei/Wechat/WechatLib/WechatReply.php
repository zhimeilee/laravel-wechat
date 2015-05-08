<?php namespace Zhimei\LaravelWechat\WechatLib;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class WechatReply extends WechatPub {

    public $xmlDataFromWechat = '';
    public $arrDataFromWechat = '';

    public $msgType = ['text','image','voice','video','shortvideo','location','link','event'];
    public $eventType = ['subscribe','unsubscribe','SCAN','LOCATION','CLICK','VIEW','scancode_push','scancode_waitmsg','pic_sysphoto','pic_photo_or_album','pic_weixin','location_select'];

	public function __construct($appId, $appSecret) {
        $context = stream_context_create ( array (
            'http' => array (
                'timeout' => 30
            )
        ) ); // 超时时间，单位为秒
        $this->xmlDataFromWechat = file_get_contents ( "php://input", 0, $context );
        if($this->xmlDataFromWechat)
            $this->arrDataFromWechat = $this->xmlToArray($this->xmlDataFromWechat);
	}

    /**
     * 监听
     *
     * @param string          $target
     * @param string|callable $type
     * @param callable        $callback
     *
     * @return Wechat
     */
    public function on($target, $type, $callback = null)
    {
        if (is_null($callback)) {
            $callback = $type;
            $type     = '*';
        }
        if (!is_callable($callback)) {
            throw new Exception("$callback 不是一个可调用的函数或方法");
        }
        $listeners = $this->listeners->get("{$target}.{$type}") ? : array();
        array_push($listeners, $callback);
        $this->listeners->set("{$target}.{$type}", $listeners);
        return $this;
    }


	function wechatMenuCreation($menu){
		$url = 'https://api.weixin.qq.com/cgi-bin/menu/create?access_token='.$this->getAccessToken();
		$res = $this->postCurl($menu,$url);
		return json_decode($res);
	}

    /**
     * 	作用：通过curl向微信提交code，以获取openid
     */
    function getOpenid()
    {
        $sessionKey = 'Openid_'.$this->appId;
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
            session([$sessionKey => $openid]);
            return $openid;
        }
        return -1;
    }

    /*
     * 网页授权获取用户基本信息
     */
    function getAtuhUserInfo(){

        $data = $this->getOpenidAndAccessTokenFromAuth('snsapi_userinfo');
        if(!empty($data)) {
            $url = 'https://api.weixin.qq.com/sns/userinfo?access_token='.$data['access_token'].'&openid='.$data['openid'].'&lang=zh_CN';
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
            $param ['appid'] = $this->openid;
            $param ['secret'] = $this->appSecret;
            $param ['code'] = Request::input( 'code' );
            $param ['grant_type'] = 'authorization_code';

            $url = 'https://api.weixin.qq.com/sns/oauth2/access_token?' . http_build_query ( $param );
            $content = $this->httpGet ( $url );
            $content = json_decode ( $content, true );
            return $content;
        }elseif(Request::input('state')){
            return [];
        }else{
            $param ['appid'] = $this->openid;
            $param ['redirect_uri'] = Request::fullUrl();
            $param ['response_type'] = 'code';
            $param ['scope'] = $scope;
            $param ['state'] = 123;
            $url = 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query ( $param ) . '#wechat_redirect';
            header('Location: '.$url);
            return [];
        }

    }
    /**
     * 	作用：格式化参数，签名过程需要使用
     */
    function formatBizQueryParaMap($paraMap, $urlencode)
    {
        $buff = "";
        ksort($paraMap);
        foreach ($paraMap as $k => $v)
        {
            if($urlencode){
                $v = urlencode($v);
            }
            //$buff .= strtolower($k) . "=" . $v . "&";
            $buff .= $k . "=" . $v . "&";
        }
        $reqPar = '';
        if (strlen($buff) > 0)
        {
            $reqPar = substr($buff, 0, strlen($buff)-1);
        }
        return $reqPar;
    }

    /*
     * 获取access token
     */
	public function getAccessToken() {
		// access_token 应该全局存储与更新，以下代码以写入到文件中做示例
        $cacheKey = 'access_token_'.$this->appId;
        $access_token = Cache::get($cacheKey,function(){return false;});
		if ($access_token) {
            return $access_token;
        }else{
			// 如果是企业号用以下URL获取access_token
			// $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=$this->appId&corpsecret=$this->appSecret";
			$url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=".$this->appId."&secret=".$this->appSecret;
			$res = json_decode($this->httpGet($url));
			$access_token = $res->access_token;
			if ($access_token) {
                Cache::put($cacheKey, $access_token, Carbon::now()->addMinutes(100));
                return $access_token;
			}
		}
        return false;

	}


    function arrayToXml($arr)
    {
        $xml = "<xml>";
        foreach ($arr as $key=>$val)
        {
            if (is_numeric($val))
            {
                $xml.="<".$key.">".$val."</".$key.">";

            }
            else
                $xml.="<".$key."><![CDATA[".$val."]]></".$key.">";
        }
        $xml.="</xml>";
        return $xml;
    }

    /**
     * 	作用：将xml转为array
     */
    public function xmlToArray($xml)
    {
        //将XML转为array
        $array_data = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $array_data;
    }

    public function httpGet($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 500);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_URL, $url);

        $res = curl_exec($curl);
        curl_close($curl);

        return $res;
    }

    /**
     * 	作用：以post方式提交data到对应的接口url
     */
    public function postCurl($content,$url,$second=30)
    {
        //初始化curl
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOP_TIMEOUT, $second);
        //这里设置代理，如果有的话
        //curl_setopt($ch,CURLOPT_PROXY, '8.8.8.8');
        //curl_setopt($ch,CURLOPT_PROXYPORT, 8080);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        //运行curl
        $data = curl_exec($ch);
        curl_close($ch);
        //返回结果
        if($data)
        {
            curl_close($ch);
            return $data;
        }
        else
        {
            $error = curl_errno($ch);
            echo "curl出错，错误码:$error"."<br>";
            echo "<a href='http://curl.haxx.se/libcurl/c/libcurl-errors.html'>错误原因查询</a></br>";
            curl_close($ch);
            return false;
        }
    }

    /**
     * 	作用：使用证书，以post方式提交data到对应的接口url
     */
    function postSSLCurl($content,$url,$second=30)
    {
        $ch = curl_init();
        //超时时间
        curl_setopt($ch,CURLOPT_TIMEOUT,$second);
        //这里设置代理，如果有的话
        //curl_setopt($ch,CURLOPT_PROXY, '8.8.8.8');
        //curl_setopt($ch,CURLOPT_PROXYPORT, 8080);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
        //设置header
        curl_setopt($ch,CURLOPT_HEADER,FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
        //设置证书
        //使用证书：cert 与 key 分别属于两个.pem文件
        //默认格式为PEM，可以注释
        curl_setopt($ch,CURLOPT_SSLCERTTYPE,'PEM');
        curl_setopt($ch,CURLOPT_SSLCERT, WxPayConf_pub::SSLCERT_PATH);
        //默认格式为PEM，可以注释
        curl_setopt($ch,CURLOPT_SSLKEYTYPE,'PEM');
        curl_setopt($ch,CURLOPT_SSLKEY, WxPayConf_pub::SSLKEY_PATH);
        //post提交方式
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$content);
        $data = curl_exec($ch);
        //返回结果
        if($data){
            curl_close($ch);
            return $data;
        }
        else {
            $error = curl_errno($ch);
            echo "curl出错，错误码:$error"."<br>";
            echo "<a href='http://curl.haxx.se/libcurl/c/libcurl-errors.html'>错误原因查询</a></br>";
            curl_close($ch);
            return false;
        }
    }
}