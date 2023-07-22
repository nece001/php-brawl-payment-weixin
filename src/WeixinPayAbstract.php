<?php

namespace Nece\Brawl\Payment\Weixin;

use Nece\Brawl\Payment\PaymentInterface;
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
    protected $apiclient_cert_pem_file_path;
    protected $apiclient_key_pem_file_path;
    protected $platform_cert_pem_file_path;

    protected $pay_notify_url;
    protected $refund_notify_url;
    protected $http_proxy;
    protected $https_proxy;
    protected $timeout = 10;
    protected $connect_timeout = 10;
    protected $ssl_cert = true;

    private $raw_response = '';
    private $error_message = '';

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
    public function getErrorMessage(): string
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
    public function getRawRespnose(): string
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
        $this->ssl_cert = $this->payment->getConfigValue('ssl_cert');

        $this->apiclient_cert_pem_file_path = $this->payment->getConfigValue('apiclient_cert_pem');
        $this->apiclient_key_pem_file_path = $this->payment->getConfigValue('apiclient_key_pem');
        $this->platform_cert_pem_file_path = $this->payment->getConfigValue('platform_cert_pem');
    }

    /**
     * 构建签名参数
     *
     * @author gjw
     * @created 2022-07-20 17:16:05
     *
     * @param string $app_id // 微信app_id
     * @param string $prepay_id // 预支付单ID
     * @param string $sign_type //签名方法：MD5，HMAC-SHA256，RSA，这里设定的值会影响下面调用的签名方法
     * @return array
     */
    public function buildSignParam($app_id, $prepay_id, $sign_type)
    {
        $params = array(
            'appId'     => $app_id,
            'timeStamp' => (string)Formatter::timestamp(),
            'nonceStr'  => Formatter::nonce(),
            'package'   => 'prepay_id=' . $prepay_id,
            'signType'  => $sign_type
        );

        $params['paySign'] = $this->sign($params, $sign_type);
        return $params;
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
    abstract protected function sign(array $params, $sign_type);
}
