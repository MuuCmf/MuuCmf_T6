<?php

namespace app\common\model;

/**
 * 扫码登录记录
 * Class QrcodeLogin
 * @package app\common\model
 */
class QrcodeLogin extends Base
{
    protected $autoWriteTimestamp = true;

    /**
     * 按 scene_key 读取扫码会话中的可信身份信息
     * openid / unionid 由微信回调写入 metadata，不采信前端传参，避免伪造他人身份。
     * 二维码有效期与 createQrcode() 的 3600 秒保持一致。
     */
    public function getScanIdentity(string $sceneKey): array
    {
        if ($sceneKey === '') {
            return [];
        }
        $row = $this->where('scene_key', $sceneKey)->find();
        if (!$row) {
            return [];
        }
        $createTime = (int)($row['create_time'] ?? 0);
        if ($createTime > 0 && $createTime < time() - 3600) {
            return [];
        }
        $metadata = json_decode((string)$row['metadata'], true);
        if (!is_array($metadata) || empty($metadata['openid'])) {
            return [];
        }

        return [
            'openid'     => (string)$metadata['openid'],
            'unionid'    => trim((string)($metadata['unionid'] ?? '')),
            'nickname'   => (string)($metadata['nickname'] ?? ''),
            'headimgurl' => (string)($metadata['headimgurl'] ?? ''),
            'sex'        => (int)($metadata['sex'] ?? 0),
        ];
    }
}
