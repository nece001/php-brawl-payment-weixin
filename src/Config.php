<?php

namespace Nece\Brawl\Payment\Weixin;

use Nece\Brawl\ConfigAbstract;

class Config extends ConfigAbstract
{
    public function buildTemplate()
    {
        $this->addTemplate(true, 'pay_notify_url', '支付回调地址', '例：http://xxxx', '');
        $this->addTemplate(true, 'refund_notify_url', '退款回调地址', '例：http://xxxx', '');

        $this->addTemplate(true, 'version', '版本', '支付接口版本：APIv2、APIv3', '', array('APIv2' => 'APIv2', 'APIv3' => 'APIv3'));
        $this->addTemplate(true, 'mchid', '商户ID', 'MCHID', '');
        $this->addTemplate(true, 'secret_key', '密钥', 'APIV2密钥或APIV3密钥', '');

        $this->addTemplate(true, 'apiclient_cert_pem', '商户证书', '商户证书文件存放位置,用于通知解码（APIv2、APIv3） apiclient_cert.pem', '');
        $this->addTemplate(true, 'apiclient_key_pem', '私钥文件', '私钥文件存放位置（APIv2、APIv3） apiclient_key.pem', '');
        $this->addTemplate(false, 'serial', '证书序列号', '商户API证书序列号（APIV3使用）', '');
        $this->addTemplate(false, 'platform_cert_pem', '平台证书', '平台证书文件存放位置（APIV3使用）', '');
        $this->addTemplate(false, 'http_proxy', 'http代理', '例：http://xxx:xxx', '');
        $this->addTemplate(false, 'https_proxy', 'https代理', '例：https://xxx:xxx', '');
        $this->addTemplate(false, 'ssl_cert', 'SSL证书', '例：/path/to/cert.pem', '');
    }
}
