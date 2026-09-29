<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CsrfToken;
use app\service\MediaUrlGuard;
use app\service\ResilientCache;
use app\service\SiteSettings;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class Danmaku extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly ResilientCache $cache, private readonly MediaUrlGuard $urlGuard, private readonly SiteSettings $settings) { parent::__construct($app); }

    public function read(): Response
    {
        if (!$this->settings->bool('admin.player.danmaku_enabled', true)) return json(['code' => 0, 'data' => []]);
        $key = $this->videoKey((string) $this->request->get('id', $this->request->get('legacy_id', '')));
        if ($key === '') return json(['code' => 0, 'data' => []]);
        $rows = Db::table('ffx_danmaku')->where('video_key', $key)->where('status', 'approved')->order('time_seconds')->limit(5000)->select()->toArray();
        return json(['code' => 0, 'data' => array_map(static fn (array $row): array => [
            'text' => $row['text'], 'time' => (float) $row['time_seconds'], 'mode' => (int) $row['mode'],
            'color' => $row['color'], 'border' => (bool) $row['is_border'],
        ], $rows)]);
    }

    public function send(): Response
    {
        if (!$this->settings->bool('admin.player.danmaku_enabled', true)) return json(['status' => 403, 'info' => '弹幕功能已关闭'], 403);
        $body = json_decode((string) $this->request->getContent(), true);
        $body = is_array($body) ? $body : [];
        if (!$this->csrf->verify($body['_token'] ?? null)) return json(['status' => 419, 'info' => '请求已过期'], 419);
        $key = $this->videoKey((string) ($body['id'] ?? ''));
        $text = mb_substr(trim((string) ($body['text'] ?? '')), 0, 200);
        if ($key === '' || $text === '') return json(['status' => 422, 'info' => '弹幕内容无效'], 422);
        if ($this->settings->bool('admin.player.danmu_filter_url_enabled', true) && preg_match('~(?:https?://|www\.|[a-z0-9-]+\.(?:com|cn|net|org))~iu', $text)) return json(['status' => 422, 'info' => '弹幕不能包含网址'], 422);
        if ($this->settings->bool('admin.player.danmu_filter_enabled', true)) {
            $words = preg_split('/\R+/', $this->settings->string('admin.player.danmu_filter_words'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) if (($word = trim($word)) !== '' && mb_stripos($text, $word) !== false) return json(['status' => 422, 'info' => '弹幕包含禁止内容'], 422);
        }
        $rateKey = 'rate:danmaku:' . hash('sha256', (string) $this->request->ip());
        $count = (int) $this->cache->get($rateKey, 0);
        if ($count >= 30) return json(['status' => 429, 'info' => '发送过于频繁'], 429);
        $mode = (int) ($body['mode'] ?? 0); if (!in_array($mode, [0, 1, 2], true)) $mode = 0;
        $color = strtoupper((string) ($body['color'] ?? '#FFFFFF')); if (!preg_match('/^#[0-9A-F]{6}$/', $color)) $color = '#FFFFFF';
        Db::table('ffx_danmaku')->insert([
            'video_key' => $key, 'user_id' => (($uid = (int) Session::get('user_id', 0)) > 0 ? $uid : null),
            'text' => $text, 'time_seconds' => max(0, min(86400, (float) ($body['time'] ?? 0))), 'mode' => $mode,
            'color' => $color, 'is_border' => !empty($body['border']) ? 1 : 0, 'status' => 'approved',
            'ip_address' => (string) $this->request->ip(), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->cache->set($rateKey, $count + 1, 3600);
        return json(['code' => 0, 'status' => 200]);
    }

    public function remote(): Response { return json(['code' => 0, 'data' => []]); }

    public function parse(): Response
    {
        $url = $this->urlGuard->allow((string) $this->request->get('url', ''));
        if ($url === null || !preg_match('/\.(?:m3u8|mp4|webm|ogg)(?:\?|$)/i', $url)) return json(['status' => 422, 'message' => '该地址不是可直接播放的媒体'], 422);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return json(['status' => 200, 'data' => ['url' => $url, 'type' => str_ends_with($path, '.m3u8') ? 'm3u8' : pathinfo($path, PATHINFO_EXTENSION)]]);
    }

    private function videoKey(string $value): string { return preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $value) ? $value : ''; }
}
