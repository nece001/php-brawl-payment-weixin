<?php

namespace Nece\Brawl\Payment\Weixin;

use WeChatPay\Builder;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Util\PemUtil;

class V3 extends WexinPayAbstract
{
    private $client;

    protected function init()
    {
        $this->mchid = $this->payment->getConfigValue('mchid');
        $this->serial = $this->payment->getConfigValue('serial');
        $this->secret_key = $this->payment->getConfigValue('secret_key');
        $this->http_proxy = $this->payment->getConfigValue('http_proxy');
        $this->https_proxy = $this->payment->getConfigValue('https_proxy');

        $apiclient_cert_pem = $this->payment->getConfigValue('apiclient_cert_pem');
        $apiclient_key_pem = $this->payment->getConfigValue('apiclient_key_pem');
        $platform_cert_pem = $this->payment->getConfigValue('platform_cert_pem');
        $this->apiclient_cert_pem = file_get_contents($apiclient_cert_pem);
        $this->apiclient_key_pem = file_get_contents($apiclient_key_pem);
        $this->platform_cert_pem = file_get_contents($platform_cert_pem);
    }

    /**
     * 获取客户端
     *
     * @author gjw
     * @created 2023-05-24 13:49:44
     *
     * @return \WeChatPay\BuilderChainable
     */
    protected function getClient()
    {
        if (!$this->client) {
            $merchantPrivateKeyInstance = Rsa::from($this->apiclient_key_pem, Rsa::KEY_TYPE_PRIVATE); // 从本地文件中加载「商户API私钥」，「商户API私钥」会用来生成请求的签名
            $platformCertificateSerial = PemUtil::parseCertificateSerialNo($this->platform_cert_pem); // 从「微信支付平台证书」中获取「证书序列号」
            $platformPublicKeyInstance = Rsa::from($this->platform_cert_pem, Rsa::KEY_TYPE_PUBLIC); // 从本地文件中加载「微信支付平台证书」，用来验证微信支付应答的签名

            $this->client = Builder::factory([
                'mchid'      => $this->mchid,
                'serial'     => $this->serial,
                'privateKey' => $merchantPrivateKeyInstance,
                'certs'      => [
                    $platformCertificateSerial => $platformPublicKeyInstance,
                ],
            ]);
        }
        return $this->client;
    }

    public function prepay()
    {
    }

    public function refund()
    {
    }

    public function notifyDecode($content, $verify = false)
    {
    }

    private function prepayJsapi()
    {
        $uri = '/v3/pay/transactions/jsapi';
        $params = $this->buildPrepayData($total, $out_trade_no, $description, $attach);
    }

    /**
     * 构造预下单参数
     *
     * @author gjw
     * @created 2023-05-24 14:30:02
     *
     * @param intger $total 支付金额(单位:分)
     * @param string $out_trade_no 商户系统内部交易号
     * @param string $description 商品说明
     * @param string $attach 附加数据
     * @return void
     */
    protected function buildPrepayData($total, $out_trade_no, $description, $attach = '')
    {
        $params = array(
            "mchid" => $this->mchid,
            "out_trade_no" => $out_trade_no,                           //商户系统内部订单号，只能是数字、大小写字母_-*且在同一个商户号下唯一
            "time_expire" => $this->makeExpireTime(),
            "amount" => array(
                "total" => $total,
                "currency" => $this->currency,
            ),
            "description" => $description,
            "attach" => $attach,                              //附加数据，在查询API和服务器接收微信服务器发过来的支付通知中原样返回，可作为自定义参数使用
        );

        //微信应用ID，可以是微信公众号的、也可以是微信小程序的、也可以是其它微信应用的
        if ($this->app_id) {
            $params['appid'] = $this->app_id;
        }

        //微信应用下的用户openid，同一用户在不同微信应用下的openid是不同的
        if ($this->open_id) {
            $params['payer']['openid'] = $this->open_id;
        }

        //异步接收微信支付结果通知的回调地址，通知url必须为外网可访问的url，不能携带参数。 公网域名必须为https，如果是走专线接入，使用专线NAT IP或者私有回调域名可使用http
        if ($this->notify_url) {
            $params['notify_url'] = $this->notify_url;
        }

        return $params;
    }

    /**
     * 过期时间
     * PHP的“date("Y-m-dTH:i:s+08:00")”方法出来的字符串中“T”前面多了“CS”，要替换成空
     *
     * @author gjw
     * @created 2023-05-24 14:27:07
     *
     * @param int $$expires 支付超时时长（秒）
     * 
     * @return string
     */
    private function makeExpireTime($expires = 3600)
    {
        return str_replace("CS", "", date("Y-m-dTH:i:s+08:00", time() + $expires));
    }
}
