<?php

namespace Nece\Brawl\Payment\Weixin;

use GuzzleHttp\Exception\ClientException;
use Nece\Brawl\BrawlException;
use Nece\Brawl\Payment\NotifyEvent;
use Nece\Brawl\Payment\NotifyResponse;
use Nece\Brawl\Payment\ParameterAbstract;
use Nece\Brawl\Payment\PaymentException;
use Nece\Brawl\Payment\Result\PaidNotify;
use Nece\Brawl\Payment\Result\Refund;
use Nece\Brawl\Payment\Result\RefundedNotify;
use Nece\Brawl\ResultAbstract;
use WeChatPay\Builder;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;
use WeChatPay\Util\PemUtil;

class V3 extends WeixinPayAbstract
{
    private $client;

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
            if ($this->apiclient_cert_pem_file) {
                $this->apiclient_cert_pem = file_get_contents($this->apiclient_cert_pem_file);
            }

            if ($this->apiclient_key_pem_file) {
                $this->apiclient_key_pem = file_get_contents($this->apiclient_key_pem_file);
            }

            if ($this->platform_cert_pem_file) {
                $this->platform_cert_pem = file_get_contents($this->platform_cert_pem_file);
            }

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

    /**
     * 发起支付
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-21
     *
     * @param ParameterAbstract $params
     *
     * @return array
     */
    public function prepay(ParameterAbstract $params): array
    {
        return $this->prepayJsapiParams($params);
    }

    /**
     * 发起退款
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-21
     *
     * @param ParameterAbstract $params
     *
     * @return string
     */
    public function refund(ParameterAbstract $params): string
    {
        $uri = 'v3/refund/domestic/refunds';
        $params = $this->buildRefundParamsArray($params);

        try {
            $response_content = $this->getClient()->chain($uri)->post($params)->getBody()->getContents();
            $this->setRawResponse($response_content);
            return $response_content;
        } catch (ClientException $e) {
            $response_content = $e->getResponse()->getBody()->getContents();
            $result = json_decode($response_content, true);
            $this->setRawResponse($response_content);
            $this->setErrorMessage($result['message']);
            throw new BrawlException($result['message'], $result['code']);
        }
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
    public function notifyDecode($inBody, array $headers, $verify = true): NotifyEvent
    {
        $inWechatpaySignature = $headers["wechatpay-signature"];
        $inWechatpayTimestamp = $headers["wechatpay-timestamp"];
        $inWechatpaySerial = $headers["wechatpay-serial"];
        $inWechatpayNonce = $headers["wechatpay-nonce"];

        $apiv3Key = $this->secret_key; // 在商户平台上设置的APIv3密钥

        if ($verify) {

            // 根据通知的平台证书序列号，查询本地平台证书文件，
            $platformPublicKeyInstance = Rsa::from($this->apiclient_cert_pem, Rsa::KEY_TYPE_PUBLIC);

            // 检查通知时间偏移量，允许5分钟之内的偏移
            $timeOffsetStatus = 300 >= abs(Formatter::timestamp() - (int)$inWechatpayTimestamp);
            $verifiedStatus = Rsa::verify(
                // 构造验签名串
                Formatter::joinedByLineFeed($inWechatpayTimestamp, $inWechatpayNonce, $inBody),
                $inWechatpaySignature,
                $platformPublicKeyInstance
            );

            if (!($timeOffsetStatus && $verifiedStatus)) {
                throw new PaymentException('无效签名');
            }
        }

        // 转换通知的JSON文本消息为PHP Array数组
        $inBodyArray = json_decode($inBody, true);
        // 使用PHP7的数据解构语法，从Array中解构并赋值变量
        // ['resource' => [
        //     'ciphertext'      => $ciphertext,
        //     'nonce'           => $nonce,
        //     'associated_data' => $aad
        // ]] = $inBodyArray;

        $ciphertext = $inBodyArray['resource']['ciphertext'];
        $nonce = $inBodyArray['resource']['nonce'];
        $aad = $inBodyArray['resource']['associated_data'];

        // 加密文本消息解密
        $inBodyResource = AesGcm::decrypt($ciphertext, $apiv3Key, $nonce, $aad);

        // 把解密后的文本转换为PHP Array数组
        // $inBodyResourceArray = json_decode($inBodyResource, true);
        // // print_r($inBodyResourceArray);// 打印解密后的结果
        // return $inBodyResourceArray;

        $event = new NotifyEvent();
        $event->setId($inBodyArray['id']);
        $event->setCreateTime(date('Y-m-d H:i:s', strtotime($inBodyArray['create_time'])));
        $event->setResourceType($inBodyArray['resource_type']);
        $event->setEventType($inBodyArray['event_type']);
        $event->setSummary($inBodyArray['summary']);
        $event->setResource($inBodyResource);

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
        $content = json_encode(array(
            "code" => "SUCCESS",
            "message" => ""
        ));

        $result = new NotifyResponse();
        $result->setContentType('application/json');
        $result->setContent($content);
        return $result;
    }

    /**
     * 获取预支付交易会话标识
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param ParameterAbstract $params
     *
     * @return string
     */
    public function prepayJsapiPrepayId(ParameterAbstract $params)
    {
        $uri = '/v3/pay/transactions/jsapi';
        $body = $this->buildPrepayParamsArray($params);

        try {
            $response_content = $this->getClient()->chain($uri)->post($body)->getBody()->getContents();
            $result = json_decode($response_content, true);
            $this->setRawResponse($response_content);
            return $result['prepay_id'];
        } catch (ClientException $e) {
            $response_content = $e->getResponse()->getBody()->getContents();
            $result = json_decode($response_content, true);
            $this->setRawResponse($response_content);
            $this->setErrorMessage($result['message']);
            throw new \Exception('JSAPI请求异常：' . $result['message'], $result['code']);
        }
    }

    /**
     * 获取支付发起参数
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param ParameterAbstract $params
     *
     * @return array
     */
    public function prepayJsapiParams(ParameterAbstract $params)
    {
        $appid = $params->getParamValue('appid');
        $prepay_id = $this->prepayJsapiPrepayId($params);
        return $this->buildSignParam($appid, $prepay_id);
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
        // 设置请求头
        $result = array(
            'headers' => array('Accept' => 'application/json'),
            'decode_content' => true,
            'verify' => $this->ssl_cert ? $this->ssl_cert : false
        );

        // 设置参数
        $data = $params->toArray();
        if (!isset($data['notify_url'])) {
            if ($this->pay_notify_url) {
                $data['notify_url'] = $this->pay_notify_url;
            }
        }

        if (!isset($data['mchid'])) {
            if ($this->mchid) {
                $data['mchid'] = $this->mchid;
            }
        }

        $result['json'] = $data;

        // 设置代理
        $proxy = array();
        if ($this->http_proxy) {
            $proxy['http'] = $this->http_proxy;
        }
        if ($this->https_proxy) {
            $proxy['https'] = $this->https_proxy;
        }
        if ($proxy) {
            $result['proxy'] = $proxy;
        }

        if ($this->timeout) {
            $result['timeout'] = $this->timeout;
        }
        if ($this->connect_timeout) {
            $result['connect_timeout'] = $this->connect_timeout;
        }

        return $result;
    }

    /**
     * 构建退款参数
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param ParameterAbstract $params
     *
     * @return array
     */
    private function buildRefundParamsArray(ParameterAbstract $params)
    {
        // 设置请求头
        $result = array(
            'headers' => array('Accept' => 'application/json')
        );

        // 设置参数
        $data = $params->toArray();
        if (!isset($data['notify_url'])) {
            if ($this->refund_notify_url) {
                $data['notify_url'] = $this->refund_notify_url;
            }
        }

        $result['json'] = $data;

        // 设置代理
        $proxy = array();
        if ($this->http_proxy) {
            $proxy['http_proxy'] = $this->http_proxy;
        }
        if ($this->https_proxy) {
            $proxy['https_proxy'] = $this->https_proxy;
        }
        if ($proxy) {
            $result['proxy'] = $proxy;
        }

        return $result;
    }

    /**
     * 解析退款返回结果
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-21
     *
     * @param string $content
     *
     * @return \Nece\Brawl\Payment\ResultAbstract
     */
    public function parseRefundResult(string $content): ResultAbstract
    {
        $data = json_decode($content, true);
        if (!$data) {
            throw new PaymentException('微信支付V3.退款结果解析失败：' . json_last_error_msg(), json_last_error());
        }

        $result = new Refund();
        $result->setRaw($content);

        $result->setRefundId($data['refund_id']);
        $result->setOutRefundNo($data['out_refund_no']);
        $result->setTansactionId($data['transaction_id']);
        $result->setOutTradeNo($data['out_trade_no']);
        $result->setChannel($data['channel']);
        $result->setUserReceivedAccount($data['user_received_account']);
        $result->setSuccessTime($data['success_time'] ? date('Y-m-d H:i:s', strtotime($data['success_time'])) : '');
        $result->setCreateTime(date('Y-m-d H:i:s', strtotime($data['create_time'])));
        $result->setStatus($data['status']);
        $result->setFundsAccount(isset($data['funds_account']) ? $data['funds_account'] : '');

        $amount = $data['amount'];

        $from_list = array();
        foreach ($amount['from'] as $from) {
            $from_list[] = $result->buildFrom($from['account'], $from['amount']);
        }

        $result->setAmount(
            $amount['total'],
            $amount['refund'],
            $amount['payer_total'],
            $amount['payer_refund'],
            $amount['settlement_refund'],
            $amount['settlement_total'],
            $amount['discount_refund'],
            $amount['currency'],
            isset($amount['refund_fee']) ? $amount['refund_fee'] : 0,
            $from_list
        );

        $promotion_detail = isset($data['promotion_detail']) ? $data['promotion_detail'] : array();
        foreach ($promotion_detail as $detail) {
            $goods = array();
            $goods_detail = isset($detail['goods_detail']) ? $detail['goods_detail'] : array();
            foreach ($goods_detail as $row) {
                $goods[] = $result->buildGoodsDetail(
                    $row['merchant_goods_id'],
                    $row['unit_price'],
                    $row['refund_amount'],
                    $row['refund_quantity'],
                    $row['goods_name'],
                    isset($row['wechatpay_goods_id']) ? $row['wechatpay_goods_id'] : '',
                );
            }

            $result->addPromotionDetail(
                isset($detail['promotion_id']) ? $detail['promotion_id'] : '',
                isset($detail['scope']) ? $detail['scope'] : '',
                isset($detail['type']) ? $detail['type'] : '',
                isset($detail['amount']) ? $detail['amount'] : 0,
                isset($detail['refund_amount']) ? $detail['refund_amount'] : 0,
                $goods
            );
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
     * @param NotifyEvent $content
     *
     * @return \Nece\Brawl\Payment\Result\PaidNotify
     */
    public function parsePaidNotifyResult(NotifyEvent $event): PaidNotify
    {
        $content = $event->getResource();
        $data = json_decode($content, true);
        if (!$data) {
            throw new PaymentException('微信支付V3.支付通知解析失败：' . json_last_error_msg(), json_last_error());
        }

        $result = new PaidNotify();
        $result->setAppId($data['appid']);
        $result->setMchId($data['mchid']);
        $result->setOutTradeNo($data['out_trade_no']);
        $result->setTransactionId($data['transaction_id']);
        $result->setTradeType($data['trade_type']);
        $result->setTradeState($data['trade_state']);
        $result->setTradeStateDesc($data['trade_state_desc']);
        $result->setBankType($data['bank_type']);
        $result->setAttach(isset($data['attach']) ? $data['attach'] : '');
        $result->setSuccessTime($data['success_time'] ? date('Y-m-d H:i:s', strtotime($data['success_time'])) : '');
        $result->setPayer($data['payer']['openid']);

        $amount = $data['amount'];
        $result->setAmount($amount['total'], $amount['payer_total'], $amount['currency'], $amount['payer_currency']);

        $result->setSceneInfo(isset($data['scene_info']['device_id']) ? $data['scene_info']['device_id'] : '');

        $promotion_detail = isset($data['promotion_detail']) ? $data['promotion_detail'] : array();
        foreach ($promotion_detail as $promotion) {
            $goods_list = array();
            $goods_detail = isset($promotion['goods_detail']) ? $promotion['goods_detail'] : array();
            foreach ($goods_detail as $goods) {
                $goods_list[] = $result->buildGoodsDetail($goods['goods_id'], $goods['quantity'], $goods['unit_price'], $goods['discount_amount'], $goods['goods_remark']);
            }

            $result->addPromotionDetail(
                $promotion['coupon_id'],
                $promotion['amount'],
                $goods_list,
                isset($promotion['name']) ? $promotion['name'] : '',
                isset($promotion['scope']) ? $promotion['scope'] : '',
                isset($promotion['type']) ? $promotion['type'] : '',
                isset($promotion['stock_id']) ? $promotion['stock_id'] : '',
                isset($promotion['wechatpay_contribute']) ? $promotion['wechatpay_contribute'] : 0,
                isset($promotion['merchant_contribute']) ? $promotion['merchant_contribute'] : 0,
                isset($promotion['other_contribute']) ? $promotion['other_contribute'] : 0,
                isset($promotion['currency']) ? $promotion['currency'] : ''
            );
        }

        return $result;
    }

    /**
     * 解析退款回调消息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-22
     *
     * @param NotifyEvent $content
     *
     * @return \Nece\Brawl\Payment\Result\RefundedNotify
     */
    public function parseRefundedNotifyResult(NotifyEvent $event): RefundedNotify
    {
        $content = $event->getResource();
        $data = json_decode($content, true);
        if (!$data) {
            throw new PaymentException('微信支付V3.退款通知解析失败：' . json_last_error_msg(), json_last_error());
        }

        $result = new RefundedNotify();
        $result->setMchId($data['mchid']);
        $result->setOutTradeNo($data['out_trade_no']);
        $result->setTransactionId($data['transaction_id']);
        $result->setOutRefundNo($data['out_refund_no']);
        $result->setRefundId($data['refund_id']);
        $result->setRefundStatus($data['refund_status']);
        $result->setSuccessTime($data['success_time'] ? date('Y-m-d H:i:s', strtotime($data['success_time'])) : '');
        $result->setUserReceivedAccount($data['user_received_account']);

        $amount = $data['amount'];
        $result->setAmount($amount['total'], $amount['refund'], $amount['payer_total'], $amount['payer_refund']);

        return $result;
    }
}
