<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

require_once __DIR__ . '/DoubanComments.php';

final class CommentPresentation
{
    public static function timestamp(string $value): int
    {
        if ($value === '') return 0;
        try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp(); }
        catch (\Throwable) { return 0; }
    }

    public static function html(array $comment, bool $reply = false): string
    {
        $escape = static fn(string $s): string => htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $name = (string) ($comment['author_name'] ?: ($reply ? '管理员' : '访客'));
        $avatar = self::avatarUrl((string) ($comment['author_avatar_url'] ?? ''));
        $face = $avatar !== '' ? '<img src="'.$escape($avatar).'" alt="'.$escape($name).'的头像" width="38" height="38" loading="lazy" referrerpolicy="no-referrer">' : $escape(mb_substr($name,0,1));
        $time = (string) ($comment['created_at'] ?? '');
        $stamp = self::timestamp($time);
        if ($stamp > 0) $time = (new DateTimeImmutable('@'.$stamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        return '<article class="mx-comment mx-comment-item'.($reply?' mx-comment-reply mx-comment-nested':'').'"><span class="mx-avatar">'.$face.'</span><div class="mx-comment-body"><div class="mx-comment-head"><strong>'.$escape($name).'</strong><small>'.$escape($time).'</small></div><p>'
            .nl2br($escape((string)($comment['content']??''))).'</p></div></article>';
    }

    public static function avatarUrl(string $avatar): string
    {
        $avatar = trim($avatar);
        if (preg_match('~^/uploads/[A-Za-z0-9/_.-]+$~D',$avatar) && !str_contains($avatar,'..')) return $avatar;
        return DoubanComments::avatarUrl($avatar);
    }
}
