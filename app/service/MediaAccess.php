<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class MediaAccess
{
    public function __construct(private readonly SiteSettings $settings) {}

    /** @param array<string, mixed> $media
     *  @return array{allowed:bool,mode:string,message:string,price:int}
     */
    public function status(array $media, int $userId): array
    {
        $mode = (string) ($media['access_mode'] ?? 'free');
        $price = max(0, (int) ($media['price_points'] ?? 0));
        if (!$this->settings->bool('admin.pay.pay_enabled') || $mode === 'free') return ['allowed' => true, 'mode' => 'free', 'message' => '', 'price' => 0];
        if ($userId < 1) return ['allowed' => false, 'mode' => $mode, 'message' => '请登录后观看', 'price' => $price];
        $user = Db::table('ffx_users')->where('id', $userId)->where('status', 'active')->whereNull('deleted_at')->find();
        if ($user === null) return ['allowed' => false, 'mode' => $mode, 'message' => '会员状态不可用', 'price' => $price];
        if ($mode === 'member' || $mode === 'vip') {
            $active = !empty($user['expires_at']) && strtotime((string) $user['expires_at']) >= time();
            return ['allowed' => $active, 'mode' => 'member', 'message' => $active ? '' : '该影片需要有效 VIP 会员', 'price' => 0];
        }
        if ($mode === 'points') {
            $unlocked = Db::table('ffx_media_entitlements')->where('user_id', $userId)->where('media_id', (int) $media['id'])->count() > 0;
            return ['allowed' => $unlocked, 'mode' => 'points', 'message' => $unlocked ? '' : ('需要 ' . $price . ' 积分解锁'), 'price' => $price];
        }
        return ['allowed' => false, 'mode' => $mode, 'message' => '该影片暂不可播放', 'price' => $price];
    }

    /** @param array<string, mixed> $media */
    public function unlock(array $media, int $userId): array
    {
        $status = $this->status($media, $userId);
        if ($status['allowed'] || $status['mode'] !== 'points') return $status;
        $price = $status['price'];
        return Db::transaction(function () use ($media, $userId, $price): array {
            $user = Db::table('ffx_users')->where('id', $userId)->where('status', 'active')->whereNull('deleted_at')->lock(true)->find();
            if ($user === null) return ['allowed' => false, 'mode' => 'points', 'message' => '会员状态不可用', 'price' => $price];
            if (Db::table('ffx_media_entitlements')->where('user_id', $userId)->where('media_id', (int) $media['id'])->count() > 0) return ['allowed' => true, 'mode' => 'points', 'message' => '', 'price' => $price];
            if ((int) $user['points'] < $price) return ['allowed' => false, 'mode' => 'points', 'message' => '积分不足，请先充值', 'price' => $price];
            Db::table('ffx_users')->where('id', $userId)->update(['points' => (int) $user['points'] - $price, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            Db::table('ffx_media_entitlements')->insert(['user_id' => $userId, 'media_id' => (int) $media['id'], 'points_spent' => $price, 'created_at' => gmdate('Y-m-d H:i:s')]);
            return ['allowed' => true, 'mode' => 'points', 'message' => '解锁成功', 'price' => $price];
        });
    }
}
