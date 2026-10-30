<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;

/** One short-comment page with the author's original displayed attribution. */
final class DoubanComments
{
    private const MAX_BODY = 2097152;
    public function __construct(private readonly SiteSettings $settings, private readonly ?ClientInterface $client = null) {}

    /** @return list<array{id:string,content:string,author_name:string,avatar_url:string,created_at:?string,published_at:string}> */
    public function fetch(string $subjectId, int $start = 0, string $sort = 'new_score', ?ClientInterface $client = null): array
    {
        if (!preg_match('/^[1-9]\d{4,11}$/D', $subjectId)) throw new RuntimeException('豆瓣 ID 格式无效');
        if ($start < 0 || $start > 2000 || $start % 20 !== 0 || !in_array($sort, ['time','new_score'], true)) throw new RuntimeException('评论分页或排序无效');
        $query = http_build_query(['count'=>20,'start'=>$start,'order_by'=>$sort === 'time' ? 'latest' : 'hot']);
        $url = 'https://m.douban.com/rexxar/api/v2/movie/'.$subjectId.'/interests?'.$query;
        $cookie = trim($this->settings->string('admin.base.douban_cookie'));
        if (strlen($cookie) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $cookie)) throw new RuntimeException('豆瓣 Cookie 格式无效，请在全局配置中重新填写');
        $headers = ['Accept'=>'application/json','Accept-Language'=>'zh-CN,zh;q=0.9',
            'User-Agent'=>'Mozilla/5.0 (compatible; FeiFeiCMS/8.5)','Referer'=>'https://m.douban.com/movie/subject/'.$subjectId.'/'];
        if ($cookie !== '') $headers['Cookie'] = $cookie;
        $client ??= $this->client ?? new Client();
        $deadline = microtime(true) + 20;
        for ($redirect = 0; $redirect < 3; $redirect++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new RuntimeException('豆瓣评论获取超时，请稍后重试');
            try {
                $response = $client->request('GET', $url, ['headers'=>$headers,'timeout'=>$remaining,'connect_timeout'=>min(4,$remaining),
                    'read_timeout'=>min(4,$remaining),'allow_redirects'=>false,'http_errors'=>false,'verify'=>true,'cookies'=>false,'stream'=>true]);
            } catch (GuzzleException) { throw new RuntimeException('连接豆瓣评论页失败或超时，请稍后重试'); }
            $body = $response->getBody();
            try {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY) throw new RuntimeException('豆瓣评论页过大，已停止读取');
                $html = '';
                while (!$body->eof() && strlen($html) <= self::MAX_BODY) {
                    if (microtime(true) >= $deadline) throw new RuntimeException('豆瓣评论读取超时，请稍后重试');
                    try { $chunk = $body->read(min(65536, self::MAX_BODY + 1 - strlen($html))); }
                    catch (RuntimeException) { throw new RuntimeException('读取豆瓣评论失败或超时，请稍后重试'); }
                    if ($chunk === '' && !$body->eof()) throw new RuntimeException('豆瓣评论读取超时，请稍后重试');
                    $html .= $chunk;
                }
                if (strlen($html) > self::MAX_BODY) throw new RuntimeException('豆瓣评论页过大，已停止读取');
            } finally { $body->close(); }
            $status = $response->getStatusCode();
            if ($status === 403 && $sort === 'time' && (json_decode($html, true)['msg'] ?? '') === 'need_permission') {
                throw new RuntimeException('豆瓣当前登录态没有最新短评排序权限，请改选热门评论；没有写入任何评论', 429);
            }
            if (in_array($status, [401,403,429], true) || preg_match('/(?:_saf=004|错误代码\s*[：:]?\s*004|访问过于频繁|异常请求)/u', $html)) {
                throw new RuntimeException('豆瓣要求登录验证或访问受限，请检查全局配置中的豆瓣 Cookie 后稍后重试', 429);
            }
            if (in_array($status, [301,302,303,307,308], true)) {
                try { $next = UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location'))); }
                catch (\InvalidArgumentException) { throw new RuntimeException('豆瓣评论页返回了无效跳转'); }
                if ($response->getHeaderLine('Location') === '' || $next->getScheme() !== 'https' || $next->getHost() !== 'm.douban.com' || $next->getUserInfo() !== '' || $next->getPort() !== null || $next->getFragment() !== ''
                    || !preg_match('~^/rexxar/api/v2/(?:movie|tv)/'.$subjectId.'/interests/?$~D', $next->getPath())) {
                    throw new RuntimeException('豆瓣要求安全验证，请在全局配置中更新豆瓣 Cookie 后重试', 429);
                }
                $url = (string) $next->withQuery($query);
                continue;
            }
            if ($status !== 200) throw new RuntimeException('豆瓣评论页返回状态 '.$status);
            return $this->parseJson($html);
        }
        throw new RuntimeException('豆瓣评论页跳转过多，已停止请求');
    }

    /** Public Douban mobile short comments; never accept a login/error page as an empty success. */
    public function parseJson(string $json): array
    {
        $payload = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($payload) || !isset($payload['interests']) || !is_array($payload['interests']) || !array_is_list($payload['interests'])) {
            throw new RuntimeException('豆瓣未返回有效短评，请检查 Cookie 或稍后重试；没有写入任何评论', 429);
        }
        $comments = [];
        foreach ($payload['interests'] as $item) {
            if (!is_array($item) || !is_string($item['comment'] ?? null)) continue;
            $content = self::clean($item['comment'], 5000);
            if ($content === '') continue;
            $user = is_array($item['user'] ?? null) ? $item['user'] : [];
            $name = self::clean(is_string($user['name'] ?? null) ? $user['name'] : '', 100);
            $created = self::sourceTime(is_string($item['create_time'] ?? null) ? $item['create_time'] : '');
            $id = is_scalar($item['id'] ?? null) ? (string)$item['id'] : '';
            if (!preg_match('/^[1-9]\d{0,29}$/D', $id)) $id = '';
            $key = $id !== '' ? 'id:'.$id : hash('sha256', $name."\0".($created ?? '')."\0".$content);
            if (isset($comments[$key])) continue;
            $comments[$key] = ['id'=>$id,'content'=>$content,'author_name'=>$name,
                'avatar_url'=>self::avatarUrl(is_string($user['avatar'] ?? null) ? $user['avatar'] : ''),
                'created_at'=>$created,'published_at'=>$created !== null ? (new DateTimeImmutable($created, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s') : ''];
            if (count($comments) >= 20) break;
        }
        return array_values($comments);
    }

    /** @return list<array{id:string,content:string,author_name:string,avatar_url:string,created_at:?string,published_at:string}> */
    public function parseHtml(string $html): array
    {
        if ($html === '') return [];
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        try { $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) return [];
        $xpath = new DOMXPath($dom);
        $cards = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," comment-item ")]');
        $comments = [];
        foreach ($cards ?: [] as $card) {
            if (!$card instanceof DOMElement) continue;
            $text = static fn(string $query): string => trim((string) $xpath->evaluate('string('.$query.')', $card));
            $content = self::clean($text('.//*[contains(concat(" ",normalize-space(@class)," ")," short ")][1]'), 5000);
            if ($content === '') continue;
            $name = self::clean($text('.//*[contains(concat(" ",normalize-space(@class)," ")," comment-info ")]//a[1]'), 100);
            if ($name === '') $name = self::clean($text('.//*[contains(concat(" ",normalize-space(@class)," ")," avatar ")]//img[1]/@alt'), 100);
            $avatar = $text('.//*[contains(concat(" ",normalize-space(@class)," ")," avatar ")]//img[1]/@data-original');
            if ($avatar === '') $avatar = $text('.//*[contains(concat(" ",normalize-space(@class)," ")," avatar ")]//img[1]/@src');
            $date = $text('.//*[contains(concat(" ",normalize-space(@class)," ")," comment-time ")][1]/@title');
            if ($date === '') $date = $text('.//*[contains(concat(" ",normalize-space(@class)," ")," comment-time ")][1]/@datetime');
            if ($date === '') $date = $text('.//*[contains(concat(" ",normalize-space(@class)," ")," comment-time ")][1]');
            $created = self::sourceTime($date);
            $id = $card->getAttribute('data-cid');
            if (!preg_match('/^[1-9]\d{0,29}$/D', $id)) $id = '';
            $key = $id !== '' ? 'id:'.$id : hash('sha256', $name."\0".($created ?? $date)."\0".$content);
            if (isset($comments[$key])) continue;
            $comments[$key] = ['id'=>$id,'content'=>$content,'author_name'=>$name,'avatar_url'=>self::avatarUrl($avatar),
                'created_at'=>$created,'published_at'=>$created !== null ? (new DateTimeImmutable($created, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s') : ''];
            if (count($comments) >= 20) break;
        }
        return array_values($comments);
    }

    public static function sourceTime(string $text): ?string
    {
        $text = trim($text);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/D', $text)) return null;
        $text = str_replace('T', ' ', $text);
        if (strlen($text) === 10) $text .= ' 00:00:00';
        elseif (strlen($text) === 16) $text .= ':00';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $text) return null;
        $utc = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return $utc >= '1000-01-01 00:00:00' ? $utc : null;
    }

    public static function avatarUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) $url = 'https:'.$url;
        if (strlen($url) > 1000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) return '';
        $p = parse_url($url);
        if (!is_array($p) || !in_array($p['scheme'] ?? '', ['http','https'], true) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment'])
            || !preg_match('/^img\d{0,2}\.douban(?:io)?\.com$/D', strtolower($p['host'] ?? ''))
            || !preg_match('~^/icon/[A-Za-z0-9_.-]+\.(?:jpe?g|png|gif|webp)$~iD', $p['path'] ?? '')) return '';
        return preg_replace('~^http:~', 'https:', $url) ?? '';
    }

    private static function clean(string $text, int $limit): string
    {
        return mb_substr(trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', str_replace("\0", '', $text))), 0, $limit);
    }
}
