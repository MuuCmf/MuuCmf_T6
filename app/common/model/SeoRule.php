<?php

namespace app\common\model;

class SeoRule extends Base
{
    /**
     * 获取seo规则
     */
    public function getRule($app, $controller, $action)
    {
        // 参数绑定，防止 SQL 注入
        $where = "(`app`=:app or `app`='') and (`controller`=:controller or `controller`='') and (`action`=:action or `action`='') and `status`=1";
        $bind = ['app' => $app, 'controller' => $controller, 'action' => $action];
        $rule = (new SeoRule())->whereRaw($where, $bind)->find();
        if ($rule) {
            $rule = $rule->toArray();
        } else {
            $rule = NULL;
        }

        return $rule;
    }
}
