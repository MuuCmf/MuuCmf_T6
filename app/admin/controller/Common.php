<?php

namespace app\admin\controller;

use think\facade\Cache;
use app\common\model\Member as CommonMember;

class Common extends Admin
{
    /* 退出登录 */
    public function logout()
    {
        if (is_login()) {
            $commonMemberModel = new CommonMember;
            $commonMemberModel->logout(is_login());
            return $this->success('退出成功', '', url('ucenter/common/login'));
        } else {
            return $this->error('已退出登录');
        }
    }

    /**
     * 清理缓存 clear cache
     * @return void
     */
    public function clearCache()
    {

        // 清理缓存
        $res = Cache::clear();

        // 清理运行目录
        $runtime_path = root_path() . 'runtime/';
        clear_directory($runtime_path);

        if ($res) return $this->success('缓存清理成功');
    }
}
