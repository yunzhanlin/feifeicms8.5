<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Episode;
use app\model\Media;
use app\model\PlaySource;
use app\service\MediaUrlGuard;
use app\service\FrontendData;
use app\service\CsrfToken;
use app\service\SiteSettings;
use app\service\MediaAccess;
use app\service\PlaybackPosition;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Session;
use think\response\Redirect;
use think\response\View;

final class Vod extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly MediaUrlGuard $mediaUrlGuard,
        private readonly FrontendData $frontend,
        private readonly CsrfToken $csrf,
        private readonly SiteSettings $settings,
        private readonly MediaAccess $mediaAccess,
    ) {
        parent::__construct($app);
    }

    public function detail(int $id): View
    {
        $media = $this->findPublished($id);
        $mediaData = $media->toArray();
        $legacyVod = $this->frontend->vod($mediaData);
        $sources = $this->frontend->sources($this->sources($media));
        $related = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')->where('category_id', (int) $media->category_id)
            ->where('id', '<>', $id)->order('view_count', 'desc')->order('id', 'desc')->limit($this->settings->int('admin.content.related_limit', 12, 0, 50))->select()->toArray();
        $comments = Db::table('ffx_comments')->where('target_type', 'media')->where('target_id', $id)->where('status', 'approved')->order('created_at', 'desc')->limit(20)->select()->toArray();
        $scenarios = Db::table('ffx_scenarios')->where('media_id', $id)->where('status', 'published')->whereNull('deleted_at')->order('sort_order')->order('episode_no')->limit(6)->select()->toArray();
        $assets = array_values(array_filter(
            Db::table('ffx_media_assets')->where('media_id', $id)->order('asset_type')->order('sort_order')->order('id')->select()->toArray(),
            fn (array $asset): bool => $this->mediaUrlGuard->allow((string) ($asset['url'] ?? '')) !== null,
        ));
        $userId = (int) Session::get('user_id', 0);
        $userScore = $userId > 0 ? Db::table('ffx_ratings')->where('user_id', $userId)->where('target_type', 'media')->where('target_id', $id)->value('score') : null;
        $actors = array_values(array_filter(array_map('trim', preg_split('/[,\/、|]+/u', (string) ($legacyVod['vod_actor'] ?? '')) ?: [])));
        $sameActorItems = [];
        if ($actors !== []) {
            $sameActorItems = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')->where('id', '<>', $id)
                ->whereLike('metadata', '%' . $actors[0] . '%')->order('view_count', 'desc')->limit(12)->select()->toArray();
        }
        $access = $this->mediaAccess->status($mediaData, (int) Session::get('user_id', 0));
        return view(ff_theme_view('vod/detail'), $this->frontend->shared($media->title . ' - ' . $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'))) + [
            'media' => $mediaData,
            'vod' => $legacyVod,
            'sources' => $sources,
            'tags' => Db::table('ffx_media_tags')->alias('mt')->join(['ffx_tags' => 't'], 't.id = mt.tag_id')->where('mt.media_id', $id)->order('t.name')->field('t.*')->select()->toArray(),
            'credits' => Db::table('ffx_media_people')->alias('mp')->join(['ffx_people' => 'p'], 'p.id = mp.person_id')->where('mp.media_id', $id)->order('mp.sort_order')->field('p.id,p.name,p.avatar_url,mp.credit_type,mp.character_name')->select()->toArray(),
            'rating' => ['score' => number_format((float) ($media->rating ?? 0), 1), 'count' => (int) ($media->rating_count ?? 0), 'user_score' => $userScore],
            'sameActors' => $actors, 'sameActorLabel' => $actors[0] ?? '', 'sameActorActive' => $actors[0] ?? '', 'sameActorItems' => $this->frontend->vodList($sameActorItems),
            'scenarios' => $scenarios, 'scenarioCount' => Db::table('ffx_scenarios')->where('media_id', $id)->where('status', 'published')->whereNull('deleted_at')->count(),
            'assets' => $assets,
            'commentCount' => count($comments), 'commentHtml' => $this->commentHtml($comments),
            'commentLoginRequired' => $this->settings->bool('admin.comments.guest_enabled', true) ? 0 : 1,
            'commentsEnabled' => $this->settings->bool('admin.comments.user_forum', true) ? 1 : 0,
            'isLoggedIn' => (int) Session::get('user_id', 0) > 0 ? 1 : 0,
            'csrf' => $this->csrf->get(),
            'related' => $this->frontend->vodList($related),
            'access' => $access,
            'accessMessage' => mb_substr(trim((string) $this->request->get('access_message', $access['message'])), 0, 200),
        ]);
    }

    public function play(int $id, int $sid, int $pid): View|Redirect
    {
        $sourceIndex = PlaybackPosition::internalSourceIndex($sid);
        $episodeIndex = PlaybackPosition::internalEpisodeIndex($pid);
        if ($sourceIndex === null || $episodeIndex === null) {
            return redirect(ff_play_url($id, max(0, $sid - 1), max(0, $pid - 1)), 301);
        }
        $media = $this->findPublished($id);
        $access = $this->mediaAccess->status($media->toArray(), (int) Session::get('user_id', 0));
        if (!$access['allowed']) throw new HttpException(403, $access['message']);
        $sourceRows = $this->frontend->sources($this->sources($media));
        $source = $sourceRows[$sourceIndex] ?? null;
        $episode = is_array($source) ? ($source['episodes'][$episodeIndex] ?? null) : null;
        if (!is_array($source) || !is_array($episode)) {
            throw new HttpException(404, '播放线路或剧集不存在');
        }
        $legacyVod = $this->frontend->vod($media->toArray());
        $related = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')->where('category_id', (int) $media->category_id)
            ->where('id', '<>', $id)->order('view_count', 'desc')->order('id', 'desc')->limit($this->settings->int('admin.content.related_limit', 12, 0, 50))->select()->toArray();
        $currentUrl = $this->mediaUrlGuard->allow((string) $episode['url']);
        return view(ff_theme_view('vod/play'), $this->frontend->shared($media->title . ' ' . $episode['title']) + [
            'media' => $media->toArray(), 'vod' => $legacyVod,
            'source' => $source, 'episode' => $episode,
            'sources' => $sourceRows, 'currentSource' => $source,
            'current' => ['title' => $episode['title'], 'url' => $currentUrl],
            'mediaUrl' => $currentUrl, 'sid' => $sourceIndex, 'pid' => $episodeIndex,
            'prevUrl' => $episodeIndex > 0 ? ff_play_url($id, $sourceIndex, $episodeIndex - 1) : '',
            'nextUrl' => isset($source['episodes'][$episodeIndex + 1]) ? ff_play_url($id, $sourceIndex, $episodeIndex + 1) : '',
            'playerAutoplay' => $this->settings->bool('admin.player.play_autoplay') ? 1 : 0,
            'playerAutoNext' => $this->settings->bool('admin.player.play_auto_next', true) ? 1 : 0,
            'danmakuEnabled' => $this->settings->bool('admin.player.danmaku_enabled', true) ? 1 : 0,
            'danmuVideoId' => 'vod-' . $id . '-' . $sourceIndex . '-' . $episodeIndex,
            'related' => $this->frontend->vodList($related),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function scenarios(int $id): View
    {
        $media = $this->findPublished($id);
        $rows = Db::table('ffx_scenarios')->where('media_id', $id)->where('status', 'published')->whereNull('deleted_at')
            ->order('sort_order')->order('episode_no')->order('id')->select()->toArray();
        return view(ff_theme_view('vod/scenarios'), $this->frontend->shared($media->title . ' 分集剧情') + [
            'vod' => $this->frontend->vod($media->toArray()), 'scenarios' => $rows, 'pageActive' => '',
        ]);
    }

    public function scenario(int $id): View
    {
        $scenario = Db::table('ffx_scenarios')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($scenario === null) throw new HttpException(404, '剧情不存在或未发布');
        $media = $this->findPublished((int) $scenario['media_id']);
        $base = Db::table('ffx_scenarios')->where('media_id', (int) $scenario['media_id'])->where('status', 'published')->whereNull('deleted_at');
        $previous = (clone $base)->where('episode_no', '<', (int) $scenario['episode_no'])->order('episode_no', 'desc')->find();
        $next = (clone $base)->where('episode_no', '>', (int) $scenario['episode_no'])->order('episode_no')->find();
        return view(ff_theme_view('vod/scenario'), $this->frontend->shared($media->title . ' ' . ($scenario['title'] ?: '第' . $scenario['episode_no'] . '集剧情')) + [
            'vod' => $this->frontend->vod($media->toArray()), 'scenario' => $scenario, 'previous' => $previous, 'next' => $next, 'pageActive' => '',
        ]);
    }

    private function findPublished(int $id): Media
    {
        $media = Media::where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($media === null) {
            throw new HttpException(404, '影片不存在或未发布');
        }
        return $media;
    }

    /** @return array<int, array<string, mixed>> */
    private function sources(Media $media): array
    {
        $result = [];
        $sources = Db::table('ffx_play_sources')->where('media_id', (int) $media->id)->where('status', 'enabled')->order('sort_order')->order('id')->select()->toArray();
        foreach ($sources as $source) {
            $row = $source;
            $row['episodes'] = Db::table('ffx_episodes')->where('media_id', (int) $media->id)->where('source_id', (int) $source['id'])
                ->where('status', 'enabled')->order('sort_order')->order('id')->select()->toArray();
            $result[] = $row;
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $comments */
    private function commentHtml(array $comments): string
    {
        if ($comments === []) return '<p class="empty-note">暂无评论</p>';
        $html = '';
        foreach ($comments as $comment) {
            $name = htmlspecialchars((string) ($comment['author_name'] ?? '访客'), ENT_QUOTES, 'UTF-8');
            $body = nl2br(htmlspecialchars((string) ($comment['content'] ?? ''), ENT_QUOTES, 'UTF-8'));
            $time = htmlspecialchars((string) ($comment['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<article class="mx-comment-item"><strong>' . $name . '</strong><p>' . $body . '</p><small>' . $time . '</small></article>';
            $replies = Db::table('ffx_comments')->where('parent_id', (int) $comment['id'])->where('status', 'approved')->whereNull('deleted_at')->order('created_at')->select()->toArray();
            foreach ($replies as $reply) {
                $html .= '<article class="mx-comment-item mx-comment-reply"><strong>' . htmlspecialchars((string) ($reply['author_name'] ?: '管理员'), ENT_QUOTES, 'UTF-8') . '</strong><p>'
                    . nl2br(htmlspecialchars((string) $reply['content'], ENT_QUOTES, 'UTF-8')) . '</p><small>' . htmlspecialchars((string) $reply['created_at'], ENT_QUOTES, 'UTF-8') . '</small></article>';
            }
        }
        return $html;
    }
}
