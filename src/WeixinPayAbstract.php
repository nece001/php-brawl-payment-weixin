<?php

namespace Nece\Brawl\Payment\Weixin;

use Nece\Brawl\Payment\PaymentInterface;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;

/**
 * 微信支付抽象类
 *
 * @Author nece001@163.com
 * @DateTime 2023-06-19
 */
abstract class WeixinPayAbstract implements PaymentInterface
{

    /**
     * 支付接口域名
     *
     * @var string
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     */
    private $endpoint = 'https://api.mch.weixin.qq.com';

    /**
     * 配置
     *
     * @var Payment
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     */
    protected $payment;

    /**
     * 商户号
     *
     * @var string
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     */
    protected $mchid;
    protected $serial;
    protected $secret_key;
    protected $apiclient_cert_pem;
    protected $apiclient_key_pem;
    protected $platform_cert_pem;

    protected $pay_notify_url;
    protected $refund_notify_url;
    protected $http_proxy;
    protected $https_proxy;

    private $raw_response;
    private $error_message;

    /**
     * 设置错误消息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $message
     *
     * @return void
     */
    protected function setErrorMessage($message)
    {
        $this->error_message = $message;
    }

    /**
     * 获取错误消息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->error_message;
    }

    /**
     * 设置原始响应
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $content
     *
     * @return void
     */
    protected function setRawResponse($content)
    {
        $this->raw_response = $content;
    }

    /**
     * 获取原始响应
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @return string
     */
    public function getRawRespnose()
    {
        return $this->raw_response;
    }

    /**
     * 构造
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @param Payment $payment
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;

        $this->mchid = $this->payment->getConfigValue('mchid');
        $this->serial = $this->payment->getConfigValue('serial');
        $this->secret_key = $this->payment->getConfigValue('secret_key');
        $this->pay_notify_url = $this->payment->getConfigValue('pay_notify_url');
        $this->refund_notify_url = $this->payment->getConfigValue('refund_notify_url');
        $this->http_proxy = $this->payment->getConfigValue('http_proxy');
        $this->https_proxy = $this->payment->getConfigValue('https_proxy');

        $apiclient_cert_pem = $this->payment->getConfigValue('apiclient_cert_pem');
        $apiclient_key_pem = $this->payment->getConfigValue('apiclient_key_pem');
        $platform_cert_pem = $this->payment->getConfigValue('platform_cert_pem');

        if ($apiclient_cert_pem) {
            $this->apiclient_cert_pem = file_get_contents($apiclient_cert_pem);
        }

        if ($apiclient_key_pem) {
            $this->apiclient_key_pem = file_get_contents($apiclient_key_pem);
        }

        if ($platform_cert_pem) {
            $this->platform_cert_pem = file_get_contents($platform_cert_pem);
        }
    }

    /**
     * 构建签名参数
     *
     * @author gjw
     * @created 2022-07-20 17:16:05
     *
     * @param string $app_id
     * @param string $prepay_id
     * @return array
     */
    public function buildSignParam($app_id, $prepay_id, $sign_type = 'HMAC-SHA256')
    {
        //开始对客户端需要使用的参数签名
        $params = array(
            'appId'     => $app_id,
            'timeStamp' => (string)Formatter::timestamp(),
            'nonceStr'  => Formatter::nonce(),
            'package'   => 'prepay_id=' . $prepay_id,
            'signType'  => $sign_type                           //签名方法：MD5，HMAC-SHA256，RSA，这里设定的值会影响下面调用的签名方法
        );

        switch ($sign_type) {
            case 'HMAC-SHA256':
                $sign = $this->hmacSha256Sign($params);
                break;
            case 'RSA':
                $sign = $this->rsaSign($params);
                break;
            case 'MD5':
                $sign = $this->md5Sign($params);
                break;
        }

        $params['paySign'] = $sign;
        return $params;
    }

    /**
     * 使用hmac_sha256方式签名
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
        //$signed = Hash::sign("HMAC-SHA256",$paramsStr,$this->_pay_config["APIV3KEY"]);
        //用PHP自带的HMAC_SHA256算法生成签名
        $signed = hash_hmac("sha256", $paramsStr, $this->secret_key);
        //然后签名要转换成大写
        return strtoupper($signed);
    }

    /**
     * 使用RSA方式签名
     * @param array $params 需要被签名的参数数组
     * @return array 参数数组，里面增加了一个签名字段
     */
    protected function rsaSign(array $params)
    {
        //构造签名串 [appId,时间戳,随机字符串,与支付交易单号，以上述顺序每行一个字符串，每行以'\n'换行，最后一行也要'\n']
        $signStr = Formatter::joinedByLineFeed($params['appId'], $params['timeStamp'], $params['nonceStr'], $params['package']);
        //使用商户私钥构建一个RSA实例
        $merchantprotectedKeyInstance = Rsa::from($this->apiclient_key_pem, Rsa::KEY_TYPE_PRIVATE);
        //开始签名
        return Rsa::sign($signStr, $merchantprotectedKeyInstance);
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
        //对参数排序
        $params = Formatter::ksort($params);
        //拼接成网址查询字符串的形式
        $paramsStr = Formatter::queryStringLike($params);
        //末尾要带上key
        $paramsStr .= "&key=" . $this->secret_key;

        return strtoupper(md5($paramsStr));
    }
}