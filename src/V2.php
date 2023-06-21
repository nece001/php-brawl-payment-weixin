<?php

namespace Nece\Brawl\Payment\Weixin;

use Exception;
use GuzzleHttp\Exception\ClientException;
use Nece\Brawl\Payment\NotifyResponse;
use Nece\Brawl\Payment\ParameterAbstract;
use WeChatPay\Builder;
use WeChatPay\Transformer;

class V2 extends WeixinPayAbstract
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
            $this->client =  Builder::factory([
                'mchid'      => $this->mchid, // 商户号
                'secret'     => $this->secret_key, // APIv2密钥(32字节)
                'serial'     => 'nop', // 商户证书序列号，不使用APIv3可填任意值
                'privateKey' => 'any', // 商户API私钥，不使用APIv3可填任意值
                'certs'      => ['any' => null], // 不使用APIv3可填任意值, key 注意不要与商户证书序列号serial相同
                'merchant' => [
                    'cert' => $this->apiclient_cert_pem_file, // 商户证书,一般是文件名为apiclient_cert.pem文件路径
                    'key'  => $this->apiclient_key_pem_file, // 商户API私钥，一般是通过官方证书生成工具生成的文件名是apiclient_key.pem文件路径
                ],
            ]);
        }
        return $this->client;
    }

    /**
     * 预支付
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @return array
     */
    public function prepay(ParameterAbstract $params)
    {
        $result = $this->payToLingQian($params);
    }

    /**
     * 退款
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @return \Nece\brawl\ResultAbstract
     */
    public function refund(ParameterAbstract $params)
    {
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
     * @return void
     */
    public function notifyDecode($content, array $headers, $verify = true)
    {
    }

    /**
     * 返回通知应答数据
     *
     * @author gjw
     * @created 2023-05-24 17:36:47
     *
     * @return \Nece\Brawl\Payment\NotifyResponse
     */
    public function notifyResponse()
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
     * 企业付款到零钱
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-21
     *
     * @param ParameterAbstract $params
     *
     * @return array
     */
    private function payToLingQian(ParameterAbstract $params)
    {
        $data = array(
            'mch_appid' => $params->getParamValue('appid'),
            'mchid' => $params->getParamValue('mchid'),
            'partner_trade_no' => $params->getParamValue('out_trade_no'),
            'openid' => $params->getParamValue('payer.openid'),
            'amount' => $params->getParamValue('amount.total'),
            'desc' => $params->getParamValue('description'),
            'notify_url' => $params->getParamValue('notify_url'),
            // 'check_name'       => 'FORCE_CHECK',
            // 're_user_name'     => '王小王',
            // 'spbill_create_ip' => '192.168.0.1',
        );

        if ($this->pay_notify_url && !$data['notify_url']) {
            $data['notify_url'] = $this->pay_notify_url;
        }

        try {
            $response = $this->getClient()->v2->mmpaymkttransfers->promotion->transfers
                ->post(array(
                    'xml' => $data,
                    'security' => false, //请求需要双向证书
                    'debug' => true //开启调试模式
                ));

            return Transformer::toArray((string)$response->getBody());
        } catch (ClientException $e) {
            $response_content = $e->getResponse()->getBody()->getContents();
            $result = json_decode($response_content, true);
            $this->setRawResponse($response_content);
            $this->setErrorMessage($result['message']);
            throw new Exception('JSAPI请求异常：' . $result['message'], $result['code']);
        }
    }
}
