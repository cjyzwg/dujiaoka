<?php
namespace App\Http\Controllers\Pay;

use Illuminate\Http\Request;
use App\Http\Controllers\PayController;
use App\Exceptions\RuleValidationException;

class HpjalipayController extends PayController
{

    public function gateway($payway, $oid)
    {
        $this->loadGateWay( $oid, $payway);
        // 构造订单基础信息
        $data = [
            'version'   => '1.1',
            'appid'=>$this->payGateway->merchant_id,
            'title' => '在线支付 - '. $this->order->title, // 订单标题
            'total_fee' => (float)$this->order->actual_price,  // 订单金额
            'trade_order_id' => $this->order->order_sn,    // 订单号
            'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'), //回调地址
            'return_url'=> url('detail-order-sn', ['orderSN' => $this->order->order_sn]), //跳转地址
            'time'      => time(),
            'nonce_str' => str_shuffle(time())
        ];
        $hashkey = $this->payGateway->merchant_pem;
        $data['hash']     = $this->generate_xh_hash($data,$hashkey);
        /**
         * 个人支付宝/微信官方支付，支付网关：https://api.xunhupay.com
         * 微信支付宝代收款，需提现，支付网关：https://pay.wordpressopen.com
         */
        $url = $this->payGateway->merchant_key;
        $url = $url?$url:'https://api.xunhupay.com/payment/do.html';
        try {
            $response     = $this->http_post($url, json_encode($data));
            /**
             * 支付回调数据
             * @var array(
             *      order_id,//支付系统订单ID
             *      url//支付跳转地址
             *  )
             */
            $result       = $response?json_decode($response,true):null;
            if(!$result){
                throw new RuleValidationException('Internal server error',500);
            }

            $hash         = $this->generate_xh_hash($result,$hashkey);
            if(!isset( $result['hash'])|| $hash!=$result['hash']){
                throw new RuleValidationException('Invalid sign!',40029);
            }

            if($result['errcode']!=0){
                throw new RuleValidationException($result['errmsg'],$result['errcode']);
            }
            if($this->isWebApp()){
                $pay_url =$result['url'];
                return redirect()->away($pay_url);
            }else{
                $result['payname'] =$this->payGateway->pay_name;
                $result['actual_price'] = (float)$this->order->actual_price;
                $result['orderid'] = $this->order->order_sn;
                $result['qr_code'] = $result['url_qrcode'];
                return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
            }
        } catch (Exception $e) {
            return $this->err($exception->getMessage());
            //TODO:处理支付调用异常的情况
        }
    }

    public function notifyUrl(Request $request)
    {
        $data = $request->post();
        foreach ($data as $k=>$v){
            $data[$k] = stripslashes($v);
        }
        // file_put_contents(realpath(dirname(__FILE__)) . "/log.txt",json_encode($data)."\r\n",FILE_APPEND);
        if(!isset($data['hash'])||!isset($data['trade_order_id'])){
          return 'fail';
        }
        $order = $this->orderService->detailOrderSN($data['trade_order_id']);
        if (!$order) {
            return 'order error';
        }
        $payGateway = $this->payService->detail($order->pay_id);
        if (!$payGateway) {
            return 'fail';
        }
        //APP SECRET
        $appkey = $payGateway->merchant_pem;
        $hash =$this->generate_xh_hash($data,$appkey);
        if($data['hash']!=$hash){
            //签名验证失败
            return 'hash error';
        }
        //商户订单ID
        if($data['status']=='OD'){
            $this->orderProcessService->completedOrder($data['trade_order_id'], $data['total_fee'], $data['transaction_id']);
        }
        return 'success';
    }
    public static function http_post($url,$data){
        if(!function_exists('curl_init')){
            throw new Exception('php未安装curl组件',500);
        }
        
        $protocol = (! empty ( $_SERVER ['HTTPS'] ) && $_SERVER ['HTTPS'] !== 'off' || $_SERVER ['SERVER_PORT'] == 443) ? "https://" : "http://";
        $siteurl= $protocol.$_SERVER['HTTP_HOST'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_REFERER,$siteurl);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($ch);
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($httpStatusCode!=200){
            throw new Exception("invalid httpstatus:{$httpStatusCode} ,response:$response,detail_error:".$error,$httpStatusCode);
        }
         
        return $response;
    }
    public function isWebApp(){ 
        $_SERVER['ALL_HTTP'] = isset($_SERVER['ALL_HTTP']) ? $_SERVER['ALL_HTTP'] : ''; 
        $mobile_browser = '0'; 
        if(preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|iphone|ipad|ipod|android|xoom)/i', strtolower($_SERVER['HTTP_USER_AGENT']))) 
            $mobile_browser++; 
        if((isset($_SERVER['HTTP_ACCEPT'])) and (strpos(strtolower($_SERVER['HTTP_ACCEPT']),'application/vnd.wap.xhtml+xml') !== false)) 
            $mobile_browser++; 
        if(isset($_SERVER['HTTP_X_WAP_PROFILE'])) 
            $mobile_browser++; 
        if(isset($_SERVER['HTTP_PROFILE'])) 
            $mobile_browser++; 
        $mobile_ua = strtolower(substr($_SERVER['HTTP_USER_AGENT'],0,4)); 
        $mobile_agents = array( 
            'w3c ','acs-','alav','alca','amoi','audi','avan','benq','bird','blac', 
            'blaz','brew','cell','cldc','cmd-','dang','doco','eric','hipt','inno', 
            'ipaq','java','jigs','kddi','keji','leno','lg-c','lg-d','lg-g','lge-', 
            'maui','maxo','midp','mits','mmef','mobi','mot-','moto','mwbp','nec-', 
            'newt','noki','oper','palm','pana','pant','phil','play','port','prox', 
            'qwap','sage','sams','sany','sch-','sec-','send','seri','sgh-','shar', 
            'sie-','siem','smal','smar','sony','sph-','symb','t-mo','teli','tim-', 
            'tosh','tsm-','upg1','upsi','vk-v','voda','wap-','wapa','wapi','wapp', 
            'wapr','webc','winw','winw','xda','xda-'
            ); 
        if(in_array($mobile_ua, $mobile_agents)) 
            $mobile_browser++; 
        if(strpos(strtolower($_SERVER['ALL_HTTP']), 'operamini') !== false) 
            $mobile_browser++; 
        // Pre-final check to reset everything if the user is on Windows 
        if(strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'windows') !== false) 
            $mobile_browser=0; 
        // But WP7 is also Windows, with a slightly different characteristic 
        if(strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'windows phone') !== false) 
            $mobile_browser++; 
        if($mobile_browser>0) 
            return true; 
        else
            return false; 
    }
    public function generate_xh_hash(array $datas,$hashkey){
 		ksort($datas);
        reset($datas);
        $arg  = '';
        foreach ($datas as $key=>$val){
        	if($key=='hash'||is_null($val)||$val===''){continue;}
       	    if($arg){$arg.='&';}
            $arg.="$key=$val";
        }
        return md5($arg.$hashkey);
    }

}
