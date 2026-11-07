<?php

namespace app\common\service\message\channel;

/**
 * 渠道适配器接口
 *
 * 新增渠道只需实现本接口，并在 ChannelManager 注册。
 */
interface ChannelInterface
{
    /**
     * 渠道标识：inbox / email / sms
     *
     * @return string
     */
    public function key(): string;

    /**
     * 单用户可达性（后台测试/单发用）
     *
     * @param int $shopid
     * @param int $uid
     * @return bool
     */
    public function available(int $shopid, int $uid): bool;

    /**
     * 批量预取可达性，返回 [uid => bool]
     *
     * 硬性要求：批量任务每批 500 用户，禁止在循环内逐用户查库。
     *
     * @param int   $shopid
     * @param array $uids
     * @return array
     */
    public function availableBatch(int $shopid, array $uids): array;

    /**
     * 渠道是否已配置（不依赖具体用户）
     *
     * @param int $shopid
     * @return bool
     */
    public function configured(int $shopid): bool;

    /**
     * 发送
     *
     * @param array $rendered 渲染结果：title/content/extra/var_map/template_code/variables
     * @param array $context  发送上下文：shopid/uid/task_id/biz_key/is_test
     * @return ChannelResult
     */
    public function send(array $rendered, array $context): ChannelResult;
}
