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
abstract class WexinPayAbstract implements PaymentInterface
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

    protected $http_proxy;
    protected $https_proxy;

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

        $this->init();
    }

    abstract protected function init();

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
