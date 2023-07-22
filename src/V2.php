<?php

namespace Nece\Brawl\Payment\Weixin;

use GuzzleHttp\RequestOptions;
use Nece\Brawl\BrawlException;
use Nece\Brawl\Payment\NotifyEvent;
use Nece\Brawl\Payment\NotifyResponse;
use Nece\Brawl\Payment\ParameterAbstract;
use Nece\Brawl\Payment\PaymentException;
use Nece\Brawl\Payment\Result\PaidNotify;
use Nece\Brawl\Payment\Result\Refund;
use Nece\Brawl\Payment\Result\RefundedNotify;
use Nece\Brawl\ResultAbstract;
use WeChatPay\Crypto\AesEcb;
use WeChatPay\Crypto\Hash;
use WeChatPay\Formatter;
use WeChatPay\Transformer;

class V2 extends WeixinPayAbstract
{
    private $base_url = 'https://api.mch.weixin.qq.com';

    /**
     * 预支付
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @return array
     */
    public function prepay(ParameterAbstract $params): array
    {
        return $this->unifiedorder($params);
    }

    /**
     * 退款
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @return string
     */
    public function refund(ParameterAbstract $params): string
    {
        $data = array(
            'appid' => $params->getParamValue('appid'),
            'mch_id' => $this->mchid,
            'nonce_str' => Formatter::nonce(),
            'out_trade_no' => $params->getParamValue('out_trade_no'),
            'out_refund_no' => $params->getParamValue('out_refund_no'),
            'total_fee' => $params->getParamValue('amount.total'),
            'refund_fee' => $params->getParamValue('amount.refund'),
            'refund_fee_type' => $params->getParamValue('amount.currency'),
            'refund_desc' => $params->getParamValue('reason'),
            'transaction_id' => $params->getParamValue('transaction_id'),
            'notify_url' => $params->getParamValue('notify_url'),
        );

        if (!$data['notify_url'] && $this->refund_notify_url) {
            $data['notify_url'] = $this->refund_notify_url;
        }

        $data["sign"] = $this->md5Sign($data);
        $xml = Transformer::toXml($data);

        $uri = '/secapi/pay/refund';
        return $this->curlPost($uri, $xml, array('content-type' => 'application/xml'));
    }

    /**
     * 通知解析
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $content
     * @param array $headers
     * @param boolean $verify
     *
     * @return NotifyEvent
     */
    public function notifyDecode($content, array $headers, $verify = true): NotifyEvent
    {
        $data = Transformer::toArray($content);

        if ($verify) {

            if (!isset($data['sign_type'])) {
                $data['sign_type'] = null;
            }

            // 部分通知体无`sign_type`，部分`sign_type`默认为`MD5`，部分`sign_type`默认为`HMAC-SHA256`
            // 部分通知无`sign`字典
            $signType = $data['sign_type'] ? $data['sign_type'] : Hash::ALGO_MD5; // 如没获取到`sign_type`，假定默认为`MD5`
            $sign = isset($data['sign']) ? $data['sign'] : '';

            $calculated = Hash::sign(
                $signType,
                Formatter::queryStringLike(Formatter::ksort($data)),
                $this->secret_key
            );

            if (!Hash::equals($calculated, $sign)) {
                throw new PaymentException('无效签名');
            }
        }

        $event = new NotifyEvent();

        if (isset($data['req_info'])) {
            // 退款数据（需要解码）
            $xml = AesEcb::decrypt($data['req_info'], Hash::md5($this->secret_key));
            $tmp = Transformer::toArray($xml);

            // 兼容数据
            $tmp['return_code'] = $data['return_code'];
            $tmp['return_msg'] = $data['return_msg'];
            $tmp['appid'] = $data['appid'];
            $tmp['mchid'] = $data['mch_id'];

            $event->setEventType('REFUND.SUCCESS');
            $event->setResource(Transformer::toXml($tmp));
        } else {
            // 支付数据（无始解码）
            // return $data;

            $event->setEventType('TRANSACTION.SUCCESS');
            $event->setResource($content);
        }

        return $event;
    }

    /**
     * 返回通知应答数据
     *
     * @author gjw
     * @created 2023-05-24 17:36:47
     *
     * @return \Nece\Brawl\Payment\NotifyResponse
     */
    public function notifyResponse(): NotifyResponse
    {
        $content = Transformer::toXml(array(
            'return_code' => 'SUCCESS',
            'return_msg' => 'OK',
        ));

        $result = new NotifyResponse();
        $result->setContentType('application/xml');
        $result->setContent($content);
        return $result;
    }

    /**
     * 解析退款返回结果
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-21
     *
     * @param string $content 消息体
     *
     * @return \Nece\Brawl\Payment\ResultAbstract
     */
    public function parseRefundResult(string $content): ResultAbstract
    {
        $data = Transformer::toArray($content);

        $result = new Refund();
        $result->setOutTradeNo($data['out_trade_no']);
        $result->setTansactionId($data['transaction_id']);
        $result->setOutRefundNo($data['out_refund_no']);
        $result->setRefundId($data['refund_id']);

        $total_fee = isset($data['total_fee']) ? $data['total_fee'] : 0;
        $cash_fee = isset($data['cash_fee']) ? $data['cash_fee'] : 0;
        $settlement_refund_fee = isset($data['settlement_refund_fee']) ? $data['settlement_refund_fee'] : 0; // 应结退款金额	settlement_refund_fee	否
        $settlement_total_fee = isset($data['settlement_total_fee']) ? $data['settlement_total_fee'] : 0; // 应结订单金额	settlement_total_fee	否
        $coupon_refund_fee = isset($data['coupon_refund_fee']) ? $data['coupon_refund_fee'] : 0; // 代金券退款总金额	coupon_refund_fee	否
        $fee_type = isset($data['fee_type']) ? $data['fee_type'] : 'CNY'; // 标价币种	fee_type	否
        // 现金支付币种	cash_fee_type	否
        // 现金退款金额	cash_refund_fee	否
        $payer_refund = $data['refund_fee'] - $coupon_refund_fee; // 款给用户的金额，不包含所有优惠券金额

        $result->setAmount($total_fee, $data['refund_fee'], $cash_fee, $payer_refund, $settlement_refund_fee, $settlement_total_fee, $coupon_refund_fee, $fee_type);

        $coupon_refund_count = isset($data['coupon_refund_count']) ? $data['coupon_refund_count'] : 0;
        if ($coupon_refund_count) {
            for ($i = 0; $i < $coupon_refund_count; $i++) {

                $coupon_refund_id = $data['coupon_refund_id_' . $i]; // 退款代金券ID	coupon_refund_id_$n	否
                $coupon_refund_fee = $data['coupon_refund_fee_' . $i]; // 单个代金券退款金额	coupon_refund_fee_$n	否
                $coupon_type = $data['coupon_type_' . $i]; // 代金券类型	coupon_type_$n	否

                $result->addPromotionDetail($coupon_refund_id, '', $coupon_type, $coupon_refund_fee, $coupon_refund_fee, array());
            }
        }

        return $result;
    }

    /**
     * 是否支付成功回调通知
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-22
     *
     * @param NotifyEvent $event
     *
     * @return bool
     */
    public function paidNotifySuccess(NotifyEvent $event): bool
    {
        return $event->getEventType() == 'TRANSACTION.SUCCESS';
    }

    /**
     * 是否退款成功回调通知
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-22
     *
     * @param NotifyEvent $event
     *
     * @return bool
     */
    public function refundedNotifySuccess(NotifyEvent $event): bool
    {
        return $event->getEventType() == 'REFUND.SUCCESS';
    }

    /**
     * 解析支付成功回调消息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-22
     *
     * @param NotifyEvent $content 消息体
     *
     * @return \Nece\Brawl\Payment\Result\PaidNotify
     */
    public function parsePaidNotifyResult(NotifyEvent $event): PaidNotify
    {
        $data = Transformer::toArray($event->getResource());

        if (!$data) {
            throw new PaymentException('微信支付V2.支付通知XML解析失败');
        }

        if ($data['return_code'] == 'FAIL') {
            throw new PaymentException('微信支付V2.支付失败：' . $data['return_msg'], $data['return_code']);
        }

        if ($data['result_code'] == 'FAIL') {
            throw new PaymentException('微信支付V2.支付失败：' . $data['err_code_des'], $data['err_code']);
        }

        $result = new PaidNotify();
        $result->setAppId($data['appid']);
        $result->setMchId($data['mch_id']);
        $result->setOutTradeNo($data['out_trade_no']);
        $result->setTransactionId($data['transaction_id']);
        $result->setTradeType($data['trade_type']);
        $result->setTradeState($data['result_code']);
        $result->setTradeStateDesc('');
        $result->setBankType($data['bank_type']);
        $result->setAttach(isset($data['attach']) ? $data['attach'] : '');
        $result->setSuccessTime($this->formatTime($data['time_end']));
        $result->setPayer($data['openid']);

        $fee_type = isset($data['fee_type']) ? $data['fee_type'] : 'CNY';
        $result->setAmount($data['total_fee'], $data['total_fee'], $fee_type, $fee_type);
        $result->setSceneInfo(isset($data['device_info']) ? $data['device_info'] : '');

        $coupon_count = isset($data['coupon_count']) ? $data['coupon_count'] : 0;
        if ($coupon_count) {
            for ($i = 0; $i < $coupon_count; $i++) {
                $coupon_id = $data['coupon_id_' . $i];
                $coupon_type = $data['coupon_type_' . $i];
                $coupon_fee = $data['coupon_fee_' . $i];

                $result->addPromotionDetail($coupon_id, $coupon_fee, array(), '', '', $coupon_type);
            }
        }

        return $result;
    }

    /**
     * 解析退款回调消息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-22
     *
     * @param string $content 消息体
     *
     * @return \Nece\Brawl\Payment\Result\RefundedNotify
     */
    public function parseRefundedNotifyResult(NotifyEvent $event): RefundedNotify
    {
        $data = Transformer::toArray($event->getResource());

        if (!$data) {
            throw new PaymentException('微信支付V2.退款通知XML解析失败');
        }

        if ($data['return_code'] == 'FAIL') {
            throw new PaymentException('微信支付V2.退款失败：' . $data['return_msg'], $data['return_code']);
        }

        $result = new RefundedNotify();
        $result->setMchId($data['mchid']);
        $result->setOutTradeNo($data['out_trade_no']);
        $result->setTransactionId($data['transaction_id']);
        $result->setOutRefundNo($data['out_refund_no']);
        $result->setRefundId($data['refund_id']);
        $result->setRefundStatus($data['refund_status']);
        $result->setSuccessTime(isset($data['success_time']) ? $data['success_time'] : '');
        $result->setUserReceivedAccount($data['refund_recv_accout']);

        $result->setAmount($data['total_fee'], $data['settlement_total_fee'], $data['refund_fee'], $data['settlement_refund_fee']);


        return $result;
    }

    /**
     * 统一下单
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-21
     *
     * @param ParameterAbstract $params
     *
     * @return array
     */
    private function unifiedorder(ParameterAbstract $params)
    {
        $uri = '/pay/unifiedorder';
        $data = $this->buildPrepayParamsArray($params);

        $response_content = $this->prepayRequest($uri, Transformer::toXml($data['xml']), $data['headers']);
        $this->setRawResponse($response_content);
        $result = Transformer::toArray($response_content);

        if ($result['return_code'] == 'FAIL') {
            $this->setErrorMessage($result['return_msg']);
            throw new BrawlException('APIv2统一下单请求异常：' . $result['return_msg'], $result['return_code']);
        }

        if ($result['result_code'] == 'FAIL') {
            $this->setErrorMessage($result['err_code_des']);
            throw new PaymentException('APIv2统一下单请求失败：' . $result['err_code_des'], $result['result_code']);
        } else {
            return $this->buildSignParam($data['xml']['appid'], $result['prepay_id'], $data['xml']['sign_type']);
        }
    }

    /**
     * 构建支付下单参数
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param ParameterAbstract $params
     *
     * @return array
     */
    private function buildPrepayParamsArray(ParameterAbstract $params)
    {
        $data = array(
            'trade_type' => 'JSAPI',
            'sign_type' => 'HMAC-SHA256',
            'nonce_str' => Formatter::nonce(),
            'mch_id' => $params->getParamValue('mchid'),
            // 'partner_trade_no' => $params->getParamValue('out_trade_no'),
            'out_trade_no' => $params->getParamValue('out_trade_no'),
            'total_fee' => $params->getParamValue('amount.total'),
            // 'amount' => $params->getParamValue('amount.total'),
            'appid' => $params->getParamValue('appid'),
            // 'mch_appid' => $params->getParamValue('appid'),
            'openid' => $params->getParamValue('payer.openid'),
            'notify_url' => $params->getParamValue('notify_url'),
            'body' => $params->getParamValue('description'),
            // 'desc' => $params->getParamValue('description'),
            'attach' => $params->getParamValue('attach'),
            // 'check_name'       => 'FORCE_CHECK',
            // 're_user_name'     => '王小王',
            // 'spbill_create_ip' => '192.168.0.1',
        );

        if ($this->pay_notify_url && !$data['notify_url']) {
            $data['notify_url'] = $this->pay_notify_url;
        }

        if (!$data['mch_id']) {
            $data['mch_id'] = $this->mchid;
            // $data['mchid'] = $this->mchid;
        }

        $data["sign"] = $this->sign($data, $data['sign_type']);

        $result = array(
            'headers' => array('content-type' => 'application/xml'),
            // 'decode_content' => true,
            'verify' => $this->ssl_cert ? $this->ssl_cert : false,
            'xml' => $data,
            // 'security' => true, //请求需要双向证书
            // 'debug' => true //开启调试模式
        );

        return $result;
    }

    /**
     * @param $uri :访问的API接口地址
     * @param $data :通过POST传递的数据,xml格式
     * @return bool|string :返回数据
     */
    public function prepayRequest($uri, $data)
    {
        $url = $this->base_url . $uri;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $this->setProxy($ch);

        // 运行curl
        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new PaymentException(curl_strerror($errno), $errno);
        }

        if ($status != '200') {
            throw new PaymentException(curl_strerror($errno), $errno);
        }

        return $data;
    }

    /**
     * 发送请求
     *
     * @author gjw
     * @created 2023-05-24 17:05:51
     *
     * @param string $uri
     * @param string $data
     * @return string
     */
    protected function curlPost($uri, $data, $headers = array())
    {
        $url = $this->base_url . $uri;

        // 初始化curl
        $ch = curl_init();
        // 这里设置代理，如果有的话
        // curl_setopt($ch,CURLOPT_PROXY, '8.8.8.8');
        // curl_setopt($ch,CURLOPT_PROXYPORT, 8080);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSLCERT, $this->apiclient_cert_pem_file_path);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->apiclient_key_pem_file_path);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $this->setProxy($ch);

        // 运行curl
        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new PaymentException(curl_strerror($errno), $errno);
        }

        if ($status != '200') {
            throw new PaymentException(curl_strerror($errno), $errno);
        }

        return $data;
    }

    /**
     * 设置代理
     *
     * @Author nece001@163.com
     * @DateTime 2023-07-22
     *
     * @param \CurlHandle $ch
     *
     * @return void
     */
    private function setProxy($ch)
    {
        $proxy = '';
        if ($this->http_proxy) {
            if (false !== $pos = strpos($this->http_proxy, '://')) {
                $proxy = substr($this->http_proxy, $pos + 4);
            }
        }
        if ($this->https_proxy) {
            if (false !== $pos = strpos($this->https_proxy, '://')) {
                $proxy = substr($this->https_proxy, $pos + 4);
            }
        }

        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        // curl_setopt($ch, CURLOPT_PROXYPORT, "代理端口");
        // curl_setopt($ch, CURLOPT_PROXYUSERPWD, "代理用户:代理密码");
    }

    /**
     * 格式化时间
     *
     * @author gjw
     * @created 2023-05-26 11:23:21
     *
     * @param string $time
     * @param string $default
     * @return string
     */
    private function formatTime($time, $default = '')
    {
        if ($time) {
            $dt = strtotime($time);
            return date('Y-m-d H:i:s', $dt);
        }

        return $default;
    }

    /**
     * 生成签名
     *
     * @Author nece001@163.com
     * @DateTime 2023-07-22
     *
     * @param array $params
     * @param string $sign_type
     *
     * @return string
     */
    protected function sign(array $params, $sign_type)
    {
        $sign_type = strtoupper($sign_type);
        if ($sign_type == 'HMAC-SHA256') {
            return $this->hmacSha256Sign($params);
        } elseif ($sign_type == 'MD5') {
            return $this->md5Sign($params);
        } else {
            throw new PaymentException('不支持的签名加密方式');
        }
    }

    /**
     * 使用hmac_sha256方式签名
     * 
     * @author gjw
     * @created 2023-05-24 17:00:09
     * 
     * @param array $params 需要被签名的参数数组
     * @return array 参数数组，里面增加了一个签名字段
     */
    protected function hmacSha256Sign(array $params)
    {
        //对参数排序
        $params = Formatter::ksort($params);
        //拼接成网址查询字符串的形式
        $paramsStr = Formatter::queryStringLike($params);
        //末尾要带上key
        $paramsStr .= "&key=" . $this->secret_key;

        //开始签名
        //这个类在命名空间WeChatPay\Crypto\Hash里面，但它签名出来是不正确的，千万不要用
        // $signed = Hash::sign("HMAC-SHA256", $paramsStr, $this->secret_key);
        //用PHP自带的HMAC_SHA256算法生成签名
        $signed = hash_hmac("sha256", $paramsStr, $this->secret_key);
        //然后签名要转换成大写
        return strtoupper($signed);
    }

    /**
     * 生成签名
     *
     * @author gjw
     * @created 2023-05-24 17:00:09
     *
     * @param array $params
     * @return string
     */
    protected function md5Sign(array $params)
    {
        // 去空
        $params = array_filter($params);

        //对参数排序
        $params = Formatter::ksort($params);
        //拼接成网址查询字符串的形式
        $paramsStr = Formatter::queryStringLike($params);
        //末尾要带上key
        $paramsStr .= "&key=" . $this->secret_key;

        return strtoupper(md5($paramsStr));
    }
}
