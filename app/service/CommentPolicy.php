<?php
declare(strict_types=1);
namespace app\service;

use think\exception\HttpException;

/** Shared by media comments and the guestbook. */
final class CommentPolicy
{
    public function __construct(private readonly SiteSettings $settings) {}

    public function content(string $content, string $ip, int $userId): string
    {
        if (!$this->settings->bool('admin.comments.user_forum', true)) throw new HttpException(403, '评论功能已关闭');
        $blocked = preg_split('/[\s,]+/', $this->settings->string('admin.comments.blocked_ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array($ip, $blocked, true)) throw new HttpException(403, '当前网络地址不能发表评论');
        if ($userId < 1 && !$this->settings->bool('admin.comments.guest_enabled', true)) throw new HttpException(401, '请先登录后再评论');
        $content = mb_substr(trim($content), 0, $this->settings->int('admin.comments.max_length', 1000, 1, 10000));
        if ($content === '') throw new HttpException(422, '评论内容不能为空');
        foreach (preg_split('/\R+/', $this->settings->string('admin.comments.user_replace'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (($word = trim($word)) !== '') $content = str_ireplace($word, '***', $content);
        }
        return $content;
    }
}
