<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use think\facade\Db;

require_once __DIR__ . '/DoubanComments.php';
require_once __DIR__ . '/DoubanAvatarStore.php';

/** Imports attribution snapshots into actual media comments, without fake member accounts. */
final class DoubanCommentImporter
{
    public function __construct(private readonly ?DoubanAvatarStore $avatars = null) {}
    public static function ready(): bool
    {
        return (int) (Db::query("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_comments' AND COLUMN_NAME='author_avatar_url'")[0]['n'] ?? 0) === 1;
    }

    /** @return array{created:int,updated:int,skipped:int} */
    public function import(int $mediaId, string $subjectId, array $comments): array
    {
        if (!self::ready()) throw new RuntimeException('请先执行 php think feifei:schema:upgrade，升级评论表后再采集');
        if (count($comments) > 20 || !preg_match('/^[1-9]\d{4,11}$/D', $subjectId)) throw new RuntimeException('采集预览数据无效，请重新预览');
        $avatarMap = ($this->avatars ?? new DoubanAvatarStore())->cacheMany(array_map(static fn($comment) => is_array($comment) ? (string)($comment['avatar_url'] ?? '') : '',$comments));
        return Db::transaction(function () use ($mediaId, $subjectId, $comments, $avatarMap): array {
            $media = Db::table('ffx_media')->where('douban_id', $subjectId)->whereNull('deleted_at')->order('id','desc')->lock(true)->find();
            if ($media === null) throw new RuntimeException('影片或豆瓣 ID 已变更，请重新预览，避免入库到错误影片');
            if ((int)$media['id'] !== $mediaId) throw new RuntimeException('该豆瓣 ID 的最新影片已变更为 #'.$media['id'].' '.$media['title'].'，请重新预览后入库');
            $stats = ['created'=>0,'updated'=>0,'skipped'=>0];
            $now = gmdate('Y-m-d H:i:s');
            foreach ($comments as $comment) {
                if (!is_array($comment)) throw new RuntimeException('采集预览数据无效');
                $content = mb_substr(trim((string) ($comment['content'] ?? '')), 0, 5000);
                $author = mb_substr(trim((string) ($comment['author_name'] ?? '')), 0, 100);
                $date = (string) ($comment['created_at'] ?? '');
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
                if ($content === '' || $author === '' || !$parsed || $parsed->format('Y-m-d H:i:s') !== $date) { $stats['skipped']++; continue; }
                $sourceAvatar = DoubanComments::avatarUrl((string) ($comment['avatar_url'] ?? ''));
                $avatar = $avatarMap[$sourceAvatar] ?? $sourceAvatar;
                $sourceId = (string) ($comment['id'] ?? '');
                if (!preg_match('/^[1-9]\d{0,29}$/D', $sourceId)) $sourceId = 'h'.hash('sha256', $author."\0".$date."\0".$content);
                $externalId = 'media:'.$mediaId.':subject:'.$subjectId.':comment:'.$sourceId;
                $refs = static fn() => Db::table('ffx_external_refs')->where('provider','douban-comment')->where('entity_type','comment');
                $ref = $refs()->where('external_id',$externalId)->find();
                $legacy = false;
                if ($ref === null) {
                    $old = $refs()->where('external_id',$subjectId.':'.hash('sha256',$content))->find();
                    if ($old !== null && Db::table('ffx_comments')->where('id',(int)$old['entity_id'])->where('target_type','media')->where('target_id',$mediaId)->count() === 1) { $ref = $old; $legacy = true; }
                }
                $data = ['author_name'=>$author,'author_avatar_url'=>$avatar,'created_at'=>$date];
                if ($ref !== null) {
                    $existing = Db::table('ffx_comments')->where('id',(int)$ref['entity_id'])->find();
                    if ($existing === null || !empty($existing['deleted_at'])) { $stats['skipped']++; continue; }
                    if ($existing['target_type'] !== 'media' || (int)$existing['target_id'] !== $mediaId) throw new RuntimeException('来源评论关联不一致，已停止本片入库');
                    // Preserve locally edited text, moderation, pins and local votes.
                    if (hash_equals((string)$ref['checksum'],hash('sha256',(string)$existing['content']))) $data['content'] = $content;
                    $changed = $legacy;
                    foreach ($data as $key=>$value) if (($key === 'created_at' ? substr((string)$existing[$key],0,19) : (string)($existing[$key] ?? '')) !== $value) $changed = true;
                    if ($changed) { $data['updated_at']=$now; Db::table('ffx_comments')->where('id',(int)$ref['entity_id'])->update($data); $stats['updated']++; }
                    else $stats['skipped']++;
                    $commentId = (int)$ref['entity_id'];
                } else {
                    $commentId = Db::table('ffx_comments')->insertGetId($data + ['user_id'=>null,'parent_id'=>null,'target_type'=>'media','target_id'=>$mediaId,
                        'episode_id'=>null,'title'=>'','content'=>$content,'ip_address'=>null,'status'=>'approved','is_pinned'=>0,'like_count'=>0,'dislike_count'=>0,'updated_at'=>$now]);
                    $stats['created']++;
                }
                $payload = ['media_id'=>$mediaId,'subject_id'=>$subjectId,'comment_id'=>$sourceId,'author_name'=>$author,'author_avatar_url'=>$sourceAvatar,'published_at'=>$date];
                $reference = ['external_id'=>$externalId,'source_url'=>'https://movie.douban.com/subject/'.$subjectId.'/comments',
                    'checksum'=>hash('sha256',$content),'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'last_synced_at'=>$now,'updated_at'=>$now];
                if ($ref === null) $refs()->insert($reference + ['source_id'=>null,'entity_type'=>'comment','entity_id'=>$commentId,'provider'=>'douban-comment','created_at'=>$now]);
                else Db::table('ffx_external_refs')->where('id',(int)$ref['id'])->update($reference);
            }
            return $stats;
        });
    }
}
