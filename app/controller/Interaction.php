<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CsrfToken;
use app\service\FrontendData;
use app\service\ResilientCache;
use app\service\SiteSettings;
use app\service\MediaAccess;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class Interaction extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly FrontendData $frontend, private readonly ResilientCache $cache, private readonly SiteSettings $settings, private readonly MediaAccess $mediaAccess) { parent::__construct($app); }

    public function rate(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['status' => 0, 'message' => '请求已过期'], 419);
        $id = max(0, (int) $this->request->post('id', 0));
        $score = max(1, min(10, (float) $this->request->post('score', 0)));
        if ($id < 1 || Db::table('ffx_media')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->count() < 1) return json(['status' => 0, 'message' => '影片不存在'], 404);
        $userId = (int) Session::get('user_id', 0);
        $sessionKey = 'rated_media_' . $id;
        if ($userId < 1 && Session::get($sessionKey, false)) return json(['status' => 0, 'message' => '你已经评过分了'], 422);
        $now = gmdate('Y-m-d H:i:s');
        if ($userId > 0) {
            $existing = Db::table('ffx_ratings')->where('user_id', $userId)->where('target_type', 'media')->where('target_id', $id)->find();
            if ($existing === null) Db::table('ffx_ratings')->insert(['user_id' => $userId, 'target_type' => 'media', 'target_id' => $id, 'score' => $score, 'created_at' => $now, 'updated_at' => $now]);
            else Db::table('ffx_ratings')->where('id', (int) $existing['id'])->update(['score' => $score, 'updated_at' => $now]);
        } else {
            Db::table('ffx_ratings')->insert(['user_id' => null, 'target_type' => 'media', 'target_id' => $id, 'score' => $score, 'created_at' => $now, 'updated_at' => $now]);
            Session::set($sessionKey, true);
        }
        $aggregate = Db::table('ffx_ratings')->where('target_type', 'media')->where('target_id', $id)->fieldRaw('AVG(score) AS score, COUNT(*) AS count')->find();
        $average = round((float) ($aggregate['score'] ?? 0), 1);
        $count = (int) ($aggregate['count'] ?? 0);
        Db::table('ffx_media')->where('id', $id)->update(['rating' => $average, 'rating_count' => $count, 'updated_at' => $now]);
        return json(['status' => 1, 'score' => $average, 'count' => $count, 'user_score' => $score]);
    }

    public function vote(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['status' => 0, 'message' => '请求已过期'], 419);
        $id = max(0, (int) $this->request->post('id', 0));
        $type = $this->request->post('type') === 'down' ? 'down' : 'up';
        $key = 'rate:vod-vote:' . $id . ':' . hash('sha256', (string) $this->request->ip());
        $voted = (string) $this->cache->get($key, '');
        if ($voted !== '') return json(['status' => 0, 'data' => ['voted' => $voted]], 422);
        $column = $type === 'up' ? 'like_count' : 'dislike_count';
        Db::table('ffx_media')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->inc($column)->update();
        $this->cache->set($key, $type, 86400);
        $media = Db::table('ffx_media')->where('id', $id)->field('like_count,dislike_count')->find();
        return json(['status' => 1, 'data' => ['up' => (int) ($media['like_count'] ?? 0), 'down' => (int) ($media['dislike_count'] ?? 0)]]);
    }

    public function unlock(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return response('请求已过期', 419);
        $userId = (int) Session::get('user_id', 0);
        $mediaId = max(0, (int) $this->request->post('id', 0));
        if ($userId < 1) return redirect('/user/login?redirect=' . rawurlencode('/vod/' . $mediaId));
        $media = Db::table('ffx_media')->where('id', $mediaId)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($media === null) return response('影片不存在', 404);
        $result = $this->mediaAccess->unlock($media, $userId);
        if (!$result['allowed']) return redirect('/vod/' . $mediaId . '?access_message=' . rawurlencode($result['message']));
        return redirect(ff_play_url($mediaId, 0, 0));
    }

    public function comment(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return $this->commentResult(419, '请求已过期');
        if (!$this->settings->bool('admin.comments.user_forum', true)) return $this->commentResult(403, '评论功能已关闭');
        $mediaId = max(0, (int) $this->request->post('cid', 0));
        $parentId = max(0, (int) $this->request->post('pid', 0));
        $content = mb_substr(trim((string) $this->request->post('content', '')), 0, $this->settings->int('admin.comments.max_length', 1000, 1, 10000));
        $rateKey = 'rate:comment:' . hash('sha256', (string) $this->request->ip());
        if ($content === '') return $this->commentResult(422, '评论内容不能为空');
        if ($this->blockedCommentIp()) return $this->commentResult(403, '当前网络地址不能发表评论');
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1 && !$this->settings->bool('admin.comments.guest_enabled', true)) return $this->commentResult(401, '请先登录后再评论');
        if ((int) $this->cache->get($rateKey, 0) > 0) return $this->commentResult(429, '提交过于频繁');
        if (Db::table('ffx_media')->where('id', $mediaId)->where('status', 'published')->whereNull('deleted_at')->count() < 1) return $this->commentResult(404, '影片不存在');
        if ($parentId > 0 && Db::table('ffx_comments')->where('id', $parentId)->where('target_type', 'media')->where('target_id', $mediaId)->count() < 1) $parentId = 0;
        $content = $this->filterComment($content);
        Db::table('ffx_comments')->insert([
            'user_id' => $userId > 0 ? $userId : null, 'parent_id' => $parentId > 0 ? $parentId : null,
            'target_type' => 'media', 'target_id' => $mediaId, 'title' => '', 'content' => $content,
            'author_name' => $userId > 0 ? (string) Session::get('user_name', '会员') : '访客',
            'ip_address' => (string) $this->request->ip(), 'status' => $this->settings->bool('admin.comments.user_check', true) ? 'pending' : 'approved',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->cache->set($rateKey, 1, $this->settings->int('admin.comments.user_second', 30, 1, 86400));
        $pending = $this->settings->bool('admin.comments.user_check', true);
        return $this->commentResult(201, $pending ? '评论已提交，审核后显示' : '评论发布成功', true);
    }

    public function comments(int $id): Response
    {
        $media = Db::table('ffx_media')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($media === null) return response('影片不存在', 404);
        $pager = Db::table('ffx_comments')->where('target_type', 'media')->where('target_id', $id)->where('status', 'approved')->whereNull('parent_id')->order('created_at', 'desc')
            ->paginate(['list_rows' => $this->settings->int('admin.comments.page_size', 30, 1, 100), 'query' => $this->request->get()]);
        $html = '';
        foreach ($pager->items() as $comment) {
            $html .= '<article class="mx-comment-item"><strong>' . htmlspecialchars((string) ($comment['author_name'] ?: '访客'), ENT_QUOTES, 'UTF-8') . '</strong><p>'
                . nl2br(htmlspecialchars((string) $comment['content'], ENT_QUOTES, 'UTF-8')) . '</p><small>' . htmlspecialchars((string) $comment['created_at'], ENT_QUOTES, 'UTF-8') . '</small></article>';
        }
        $vod = $this->frontend->vod($media);
        return view(ff_theme_view('vod/comments'), $this->frontend->shared((string) $media['title'] . ' 评论') + [
            'vod' => $vod, 'commentCount' => $pager->total(), 'commentHtml' => $html ?: '<p class="empty-note">暂无评论</p>',
            'commentLoginRequired' => $this->settings->bool('admin.comments.guest_enabled', true) ? 0 : 1,
            'commentsEnabled' => $this->settings->bool('admin.comments.user_forum', true) ? 1 : 0,
            'isLoggedIn' => (int) Session::get('user_id', 0) > 0 ? 1 : 0,
            'fullComments' => 1, 'csrf' => $this->csrf->get(), 'pager' => $pager->render(),
        ]);
    }

    public function favorite(): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return json(['status' => 5001, 'info' => '请先登录']);
        $mediaId = max(0, (int) $this->request->param('id', 0));
        if ($mediaId < 1 || Db::table('ffx_media')->where('id', $mediaId)->where('status', 'published')->whereNull('deleted_at')->count() < 1) {
            return json(['status' => 404, 'info' => '影片不存在'], 404);
        }
        $query = Db::table('ffx_favorites')->where('user_id', $userId)->where('media_id', $mediaId);
        if ($this->request->isGet()) return json(['status' => 200, 'data' => ['active' => $query->count() > 0]]);
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['status' => 419, 'info' => '请求已过期'], 419);
        if ($query->count() > 0) {
            $query->delete();
            $active = false;
        } else {
            Db::table('ffx_favorites')->insert(['user_id' => $userId, 'media_id' => $mediaId, 'created_at' => gmdate('Y-m-d H:i:s')]);
            $active = true;
        }
        return json(['status' => 200, 'data' => ['active' => $active]]);
    }

    public function history(): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return json(['status' => 5001, 'info' => '未登录']);
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['status' => 419, 'info' => '请求已过期'], 419);
        $mediaId = max(0, (int) $this->request->post('id', 0));
        $episodeId = max(0, (int) $this->request->post('episode_id', 0));
        if (Db::table('ffx_media')->where('id', $mediaId)->where('status', 'published')->whereNull('deleted_at')->count() < 1) {
            return json(['status' => 404, 'info' => '影片不存在'], 404);
        }
        if ($episodeId > 0 && Db::table('ffx_episodes')->where('id', $episodeId)->where('media_id', $mediaId)->count() < 1) $episodeId = 0;
        $now = gmdate('Y-m-d H:i:s');
        $existing = Db::table('ffx_watch_history')->where('user_id', $userId)->where('media_id', $mediaId)->find();
        $data = ['episode_id' => $episodeId > 0 ? $episodeId : null, 'watched_at' => $now];
        if ($existing === null) Db::table('ffx_watch_history')->insert(['user_id' => $userId, 'media_id' => $mediaId, 'progress_seconds' => 0] + $data);
        else Db::table('ffx_watch_history')->where('id', (int) $existing['id'])->update($data);
        return json(['status' => 200]);
    }

    private function commentResult(int $code, string $message, bool $success = false): Response
    {
        if ($this->request->isAjax() || str_contains((string) $this->request->header('accept', ''), 'json')) return json(['status' => $success ? 1 : 0, 'info' => $message], $code);
        return $success ? redirect((string) $this->request->header('referer', '/')) : response($message, $code);
    }

    private function blockedCommentIp(): bool
    {
        $blocked = preg_split('/[\s,]+/', $this->settings->string('admin.comments.blocked_ips'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array((string) $this->request->ip(), $blocked, true);
    }

    private function filterComment(string $content): string
    {
        $words = preg_split('/\R+/', $this->settings->string('admin.comments.user_replace'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '') $content = str_ireplace($word, '***', $content);
        }
        return $content;
    }
}
