<?php

namespace Nece\Brawl\Payment\Weixin\Parameter;

use Nece\Brawl\Payment\ParameterAbstract;

class Refund extends ParameterAbstract
{

    protected $params = array();
    private $from = array();

    /**
     * 设置微信支付订单号
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 微信支付订单号[1, 32]二选一
     *
     * @return void
     */
    public function setTransactionId($value)
    {
        $this->params['transaction_id'] = $value;
    }

    /**
     * 设置商户订单号
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 原支付交易对应的商户订单号[6, 32]二选一
     *
     * @return void
     */
    public function setOutTradeNo($value)
    {
        $this->params['out_trade_no'] = $value;
    }
    /**
     * 设置商户退款单号
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 商户系统内部唯一的退款单号[1, 64]
     *
     * @return void
     */
    public function setOutRefundNo($value)
    {
        $this->params['out_refund_no'] = $value;
    }
    /**
     * 设置退款原因
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 退款原因[1, 80]
     *
     * @return void
     */
    public function setReason($value)
    {
        $this->params['reason'] = $value;
    }
    /**
     * 设置退款结果回调url
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 退款结果回调url[8, 256]
     *
     * @return void
     */
    public function setNotifyUrl($value)
    {
        $this->params['notify_url'] = $value;
    }
    /**
     * 退款资金来源
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $value 退款资金来源 枚举值：AVAILABLE：可用余额账户
     *
     * @return void
     */
    public function setFundsAccount($value)
    {
        $this->params['funds_account'] = $value;
    }


    /**
     * 设置金额信息
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param integer $refund 退款金额，单位为分，只能为整数，不能超过原订单支付金额。
     * @param integer $total 原支付交易的订单总金额，单位为分，只能为整数。
     * @param string $currency 退款币种
     *
     * @return void
     */
    public function setAmount(int $refund, int $total, $currency = 'CNY')
    {
        $this->params['amount'] = array(
            'refund' => $refund,
            'total' => $total,
            'currency' => $currency,
        );
    }

    /**
     * 设置退款出资账户及金额
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $account 出资账户类型 枚举值[AVAILABLE : 可用余额，UNAVAILABLE : 不可用余额]
     * @param integer $amount 出资金额，单位：分
     *
     * @return void
     */
    public function setFrom(string $account, int $amount)
    {
        $this->from = array(
            'account' => $account,
            'amount' => $amount,
        );
    }

    /**
     * 设置退款商品
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @param string $merchant_goods_id 商户侧商品编码
     * @param integer $unit_price 商品单价，单位为分
     * @param integer $refund_amount 商品退款金额，单位为分
     * @param integer $refund_quantity 商品退货数量
     * @param string $goods_name 商品名称
     * @param string $wechatpay_goods_id 微信支付商品编码
     *
     * @return void
     */
    public function setGoodsDetail($merchant_goods_id, int $unit_price, int $refund_amount, int $refund_quantity, $goods_name = '', $wechatpay_goods_id = '')
    {
        $params = array(
            'merchant_goods_id' => $merchant_goods_id,
            'unit_price' => $unit_price,
            'refund_amount' => $refund_amount,
            'refund_quantity' => $refund_quantity,
        );
        if ($goods_name) {
            $params['goods_name'] = $goods_name;
        }
        if ($wechatpay_goods_id) {
            $params['wechatpay_goods_id'] = $wechatpay_goods_id;
        }

        $this->params['goods_detail'] = $params;
    }

    /**
     * 转数组
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @return array
     */
    public function toArray()
    {
        $params = $this->params;
        if ($this->from) {
            $params['amount']['from'] = $this->from;
        }

        return $params;
    }
}
