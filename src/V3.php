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
            $merchantPrivateKeyInstance = Rsa::from($this->buildFilePath($this->apiclient_key_pem_file_path), Rsa::KEY_TYPE_PRIVATE); // 从本地文件中加载「商户API私钥」，「商户API私钥」会用来生成请求的签名
            $platformPublicKeyInstance = Rsa::from($this->buildFilePath($this->platform_cert_pem_file_path), Rsa::KEY_TYPE_PUBLIC); // 从本地文件中加载「微信支付平台证书」，用来验证微信支付应答的签名
            $platformCertificateSerial = PemUtil::parseCertificateSerialNo($this->buildFilePath($this->platform_cert_pem_file_path)); // 从「微信支付平台证书」中获取「证书序列号」
            
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
     * @param string $content 通知内容(HTTP请求的body内容)
     * @param array $headers 请求头(HTTP的请求头)
     * @param boolean $verify 是否验证签名
     *
     * @return NotifyEvent
     */
    public function notifyDecode($content, array $headers, $verify = true): NotifyEvent
    {
        $signature = $headers["wechatpay-signature"];
        $timestamp = intval($headers["wechatpay-timestamp"]);
        $nonce = $headers["wechatpay-nonce"];

        if ($verify) {
            $verifiedStatus = false;
            // 检查通知时间偏移量，允许5分钟之内的偏移
            $timeOffsetStatus = 30000 >= abs(Formatter::timestamp() - $timestamp);
            if ($timeOffsetStatus) {
                // 根据通知的平台证书序列号，查询本地平台证书文件，
                $platformPublicKeyInstance = Rsa::from($this->buildFilePath($this->platform_cert_pem_file_path), Rsa::KEY_TYPE_PUBLIC);
                $verifiedStatus = Rsa::verify(
                    Formatter::joinedByLineFeed($timestamp, $nonce, $content),
                    $signature,
                    $platformPublicKeyInstance
                );
            }

            if (!$verifiedStatus) {
                throw new PaymentException('无效签名');
            }
        }

        // 转换通知的JSON文本消息为PHP Array数组
        $result = json_decode($content, true);
        $ciphertext = $result['resource']['ciphertext'];
        $nonce = $result['resource']['nonce'];
        $aad = $result['resource']['associated_data'];

        // 加密文本消息解密
        $resource = AesGcm::decrypt($ciphertext, $this->secret_key, $nonce, $aad);

        $event = new NotifyEvent();
        $event->setId($result['id']);
        $event->setCreateTime(date('Y-m-d H:i:s', strtotime($result['create_time'])));
        $event->setResourceType($result['resource_type']);
        $event->setEventType($result['event_type']);
        $event->setSummary($result['summary']);
        $event->setResource($resource);

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
        return $this->buildSignParam($appid, $prepay_id, 'RSA');
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

    /**
     * 使用RSA方式签名（V3只能用RSA方式）
     * 
     * @Author nece001@163.com
     * @DateTime 2023-06-22
     * 
     * @param array $params 需要被签名的参数数组
     * @param string $sign_type
     * 
     * @return string
     */
    protected function sign(array $params, $sign_type)
    {
        //构造签名串 [appId,时间戳,随机字符串,与支付交易单号，以上述顺序每行一个字符串，每行以'\n'换行，最后一行也要'\n']
        $signStr = Formatter::joinedByLineFeed($params['appId'], $params['timeStamp'], $params['nonceStr'], $params['package']);

        //使用商户私钥构建一个RSA实例
        $merchantprotectedKeyInstance = Rsa::from($this->buildFilePath($this->apiclient_key_pem_file_path), Rsa::KEY_TYPE_PRIVATE);
        //开始签名
        return Rsa::sign($signStr, $merchantprotectedKeyInstance);
    }

    /**
     * 构建文件路径
     *
     * @Author nece001@163.com
     * @DateTime 2023-07-22
     *
     * @param string $path
     *
     * @return string
     */
    private function buildFilePath($path)
    {
        return 'file://' . $path;
    }
}
