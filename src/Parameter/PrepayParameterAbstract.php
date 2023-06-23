<?php

namespace Nece\Brawl\Payment\Weixin\Parameter;

use Nece\Brawl\Payment\ParameterAbstract;

/**
 * 参数基类
 *
 * @Author nece001@163.com
 * @DateTime 2023-06-20
 */
abstract class PrepayParameterAbstract extends ParameterAbstract
{
    protected $goods_detail = array();
    protected $store_info = array();

    /**
     * 设置应用ID
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 应用ID[1,32]
     *
     * @return void
     */
    public function setAppId($value)
    {
        $this->params['appid'] = $value;
    }

    /**
     * 设置直连商户号
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 直连商户号[1,32]
     *
     * @return void
     */
    public function setMchId($value)
    {
        $this->params['mchid'] = $value;
    }

    /**
     * 设置商品描述
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value
     *
     * @return void
     */
    public function setDescription($value)
    {
        $this->params['description'] = $value;
    }

    /**
     * 设置商户订单号,商户系统内部订单号，只能是数字、大小写字母_-*且在同一个商户号下唯一
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value
     *
     * @return void
     */
    public function setOutTradeNo($value)
    {
        $this->params['out_trade_no'] = $value;
    }

    /**
     * 设置交易结束时间 格式为yyyy-MM-DDTHH:mm:ss+TIMEZONE
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param integer $value 过期时间（秒）
     *
     * @return void
     */
    public function setTimeExpire(int $value = 800)
    {
        $this->params['time_expire'] = $this->makeExpireTime($value);
    }

    /**
     * 设置附加数据,在查询API和支付通知中原样返回
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value
     *
     * @return void
     */
    public function setAttach($value)
    {
        $this->params['attach'] = $value;
    }

    /**
     * 设置通知地址
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value
     *
     * @return void
     */
    public function setNotifyUrl($value)
    {
        $this->params['notify_url'] = $value;
    }

    /**
     * 设置订单优惠标记
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value
     *
     * @return void
     */
    public function setGoodsTag($value)
    {
        $this->params['goods_tag'] = $value;
    }

    /**
     * 设置电子发票入口开放标识
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param boolean $value
     *
     * @return void
     */
    public function setSupportFapiao($value)
    {
        $this->params['support_fapiao'] = boolval($value);
    }

    /**
     * 设置订单金额
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param integer $total // 订单总金额，单位为分。
     * @param string $currency // CNY：人民币，境内商户号仅支持人民币。
     *
     * @return void
     */
    public function setAmount($total, $currency = 'CNY')
    {
        $this->params['amount'] = array('total' => $total, 'currency' => $currency);
    }

    /**
     * 设置支付者
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $openid
     *
     * @return void
     */
    public function setPayer($openid)
    {
        $this->params['payer'] = array('openid' => $openid);
    }

    /**
     * 设置优惠功能
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param integer $cost_price
     * @param string $invoice_id
     *
     * @return void
     */
    public function setDetail($cost_price = null, $invoice_id = null)
    {
        $params = array();

        if (!is_null($cost_price)) {
            $params['cost_price'] = $cost_price;
        }

        if (!is_null($invoice_id)) {
            $params['invoice_id'] = $invoice_id;
        }

        $this->params['detail'] = $params;
    }

    /**
     * 添加商品,条目个数限制：【1，6000】
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $merchant_goods_id 商户侧商品编码
     * @param integer $quantity 商品数量
     * @param integer $unit_price 商品单价单位为：分。如果商户有优惠，需传输商户优惠后的单价
     * @param string $goods_name 商品名称
     * @param string $wechatpay_goods_id 微信支付商品编码
     *
     * @return void
     */
    public function addGoodsDetail(string $merchant_goods_id, int $quantity, int $unit_price, $goods_name = '', $wechatpay_goods_id = '')
    {

        $params = array(
            'merchant_goods_id' => $merchant_goods_id,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
        );

        if ($goods_name) {
            $params['goods_name'] = $goods_name;
        }
        if ($wechatpay_goods_id) {
            $params['wechatpay_goods_id'] = $wechatpay_goods_id;
        }

        $this->goods_detail[] = $params;
    }

    /**
     * 设置场景信息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $payer_client_ip 用户终端IP
     * @param string $device_id 商户端设备号
     *
     * @return void
     */
    public function setSceneInfo($payer_client_ip, $device_id = '')
    {
        $params = array('payer_client_ip' => $payer_client_ip);
        if ($device_id) {
            $params['device_id'] = $device_id;
        }

        $this->params['scene_info'] = $params;
    }

    /**
     * 设置商户门店信息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $id 门店编号[1,32]
     * @param string $name 门店名称[1,256]
     * @param string $area_code 地区编码[1,32]
     * @param string $address 详细地址[1,512]
     *
     * @return void
     */
    public function setStoreInfo($id, $name = '', $area_code = '', $address = '')
    {
        $params = array(
            'id' => $id
        );

        if ($name) {
            $params['name'] = $name;
        }
        if ($area_code) {
            $params['area_code'] = $area_code;
        }
        if ($address) {
            $params['address'] = $address;
        }

        $this->store_info = $params;
    }
}
