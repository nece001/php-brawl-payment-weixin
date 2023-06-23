# php-brawl-payment-weixin
PHP 微信支付基础服务适配项目

# 依赖

composer require "wechatpay/wechatpay": "^1.4"

# 示例

```php
    $conf = array(
        'pay_notify_url' => '支付通知回调地址',
        'refund_notify_url' => '退款通知回调地址',
        'version' => 'APIv3',
        'mchid' => '商户号',
        'secret_key' => 'APIv3/APIv2密钥',
        'apiclient_cert_pem' => '证书文件路径',
        'apiclient_key_pem' => '证书密钥文件路径',
        'serial' => '序列号',
        'platform_cert_pem' => '平台证书文件路径',
        // 'http_proxy' => 'http代理',
        // 'https_proxy' => 'https代理',
    );

    // 创建配置
    $config = Factory::createConfig('Weixin');
    // print_r($config->getTemplate());exit;
    $config->setConfig($conf);

    // 创建客户端
    $payment = Factory::createClient($config);

    try {
        // -------------------------
        // 支付
        // -------------------------
        // $params = new Jsapi();
        // $params->setAppId('wxef6df151e6d8442e');
        // $params->setPayer('oM77J4q6hdfNynqtqIbDek6g8cmc');
        // $params->setAmount(1);
        // $params->setOutTradeNo('PY' . StringUtil::randNum(20)); // 'PY' . StringUtil::randNum(20)
        // $params->setTimeExpire();
        // $params->setDescription('测试商品');

        // $result = $payment->prepay($params);
        echo '发送结果：', '<br>';
        print_r($result);

        echo '返回结果：' . $payment->getRawRespnose();
    } catch (Throwable $e) {
        echo '异常消息：' . $e->getMessage(), '<br>';
        echo '错误信息：' . $payment->getErrorMessage();
    }
```