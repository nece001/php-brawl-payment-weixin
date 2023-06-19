<?php

namespace Nece\Brawl\Payment\Weixin;

use Nece\Brawl\ClientAbstract;
use Nece\Brawl\ConfigAbstract;
use Nece\Brawl\Payment\PaymentException;

/**
 * 微信支付主类
 *
 * @Author nece001@163.com
 * @DateTime 2023-06-19
 */
class Payment extends ClientAbstract
{
    private $client;

    /**
     * 设置配置
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @param ConfigAbstract $config
     *
     * @return void
     */
    public function setConfig(ConfigAbstract $config)
    {
        parent::setConfig($config);
        $this->initClient();
    }

    /**
     * 初始客户端
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @return void
     */
    private function initClient()
    {
        if (!$this->client) {
            $version = $this->getConfigValue('version');
            switch ($version) {
                case 'APIv2':
                    $this->client = new V2($this);
                    break;
                case 'APIv3':
                    $this->client = new V3($this);
                    break;
                default:
                    throw new PaymentException('暂不支持指定的微信支付API版本：' . $version);
            }
        }
    }

    /**
     * 执行方法
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-19
     *
     * @param string $name
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->client->$name(...$arguments);
    }
}
