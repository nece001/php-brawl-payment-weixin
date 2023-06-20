<?php

namespace Nece\Brawl\Payment\Weixin\Parameter;

/**
 * Jsapi参数
 *
 * @Author nece001@163.com
 * @DateTime 2023-06-20
 */
class Jsapi extends PrepayParameterAbstract
{
    /**
     * 返回参数数组
     *
     * @Author nece001@163.com
     * @DateTime 2023-06-20
     *
     * @return array
     */
    public function toArray()
    {
        $params = $this->params;
        if ($this->goods_detail) {
            $params['detail']['goods_detail'] = $this->goods_detail;
        }

        if ($this->store_info) {
            $params['scene_info']['store_info'] = $this->store_info;
        }

        return $params;
    }
}
