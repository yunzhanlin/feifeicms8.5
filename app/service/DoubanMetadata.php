<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class DoubanMetadata
{
    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly SiteSettings $settings, private readonly ?ClientInterface $client = null) {}

    /** Like 7.4: one subject page for every video type, JSON only fills missing fields. */
    public function fetch(string $subjectId, ?ClientInterface $client = null): array
    {
        if (!preg_match('/^[1-9]\d{4,11}$/D', $subjectId)) {
            throw new RuntimeException('豆瓣 ID 格式无效');
        }
        $client ??= $this->client ?? new Client();
        $deadline = microtime(true) + 20;
        $pageData = [];
        try {
            $page = $this->request($client, 'https://movie.douban.com/subject/' . $subjectId . '/', false, $deadline, 5);
            $this->assertNotBlocked($page);
            if ($page['status'] === 200) $pageData = $this->mapSubjectPage($subjectId, $page['body']);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 429) throw $exception;
            // An unavailable HTML page is not a reason to discard the public JSON interface.
        }
        if (!$this->needsFallback($pageData)) return $pageData;

        try {
            // Douban resolves the ID here, redirecting TV/animation/variety subjects itself.
            [$payload, $url] = $this->requestJson($client, $subjectId, 'https://m.douban.com/rexxar/api/v2/movie/' . $subjectId, $deadline);
            if (!is_string($payload['title'] ?? null) || trim($payload['title']) === '' || (isset($payload['id']) && (string) $payload['id'] !== $subjectId)) {
                throw new RuntimeException('豆瓣没有返回匹配该 ID 的有效资料');
            }
            $data = $this->mergeMissing($pageData, $this->mapPayload($subjectId, $payload));
            if ($data['imdb_id'] === '' && microtime(true) < $deadline) {
                $data['imdb_id'] = $this->supplementImdb($client, $subjectId, $payload, $deadline);
            }
            if ($data['writer'] === '' && microtime(true) < $deadline) {
                try {
                    [$credits] = $this->requestJson($client, $subjectId, $url . '/credits', $deadline);
                    $data['writer'] = $this->creditWriters($credits['items'] ?? []);
                } catch (RuntimeException) {
                    // Credits are supplemental: keep already fetched video metadata.
                }
            }
            return $data;
        } catch (RuntimeException $exception) {
            if ($pageData !== []) return $pageData;
            throw $exception;
        }
    }

    /** @return array{status:int, body:string, location:string} */
    private function request(ClientInterface $client, string $url, bool $json, float $deadline, float $maximum = 8): array
    {
        $timeout = min($maximum, $deadline - microtime(true));
        if ($timeout <= 0) throw new RuntimeException('豆瓣资料获取超时，请稍后重试');
        $headers = [
            'Accept' => $json ? 'application/json' : 'text/html,application/xhtml+xml',
            'Accept-Language' => 'zh-CN,zh;q=0.9',
            'Referer' => $json && preg_match('#/api/v2/(?:movie|tv)/(\d+)#', $url, $subject)
                ? 'https://m.douban.com/movie/subject/' . $subject[1] . '/' : 'https://movie.douban.com/',
            'User-Agent' => 'Mozilla/5.0 (compatible; FeiFeiCMS/8.5)',
        ];
        $cookie = substr(trim(str_replace(["\r", "\n"], '', $this->settings->string('admin.base.douban_cookie'))), 0, 4096);
        if (preg_match('/[\x00-\x1F\x7F]/', $cookie)) throw new RuntimeException('豆瓣 Cookie 含有无效控制字符，请重新填写');
        if ($cookie !== '') $headers['Cookie'] = $cookie;
        try {
            $response = $client->request('GET', $url, [
                'timeout' => $timeout, 'connect_timeout' => min(4, $timeout), 'read_timeout' => min(4, $timeout),
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'stream' => true, 'cookies' => false, 'headers' => $headers,
            ]);
        } catch (GuzzleException) {
            // Transport messages may contain credentials, URLs or environment details.
            throw new RuntimeException('连接豆瓣失败或超时，请稍后重试');
        }
        $body = $response->getBody();
        try {
            if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) throw new RuntimeException('豆瓣响应过大，已停止读取');
            $text = '';
            while (!$body->eof() && strlen($text) <= self::MAX_BODY_BYTES) {
                if (microtime(true) >= $deadline) throw new RuntimeException('豆瓣资料获取超时，请稍后重试');
                try { $chunk = $body->read(min(65536, self::MAX_BODY_BYTES + 1 - strlen($text))); }
                catch (RuntimeException) { throw new RuntimeException('读取豆瓣资料失败或超时，请稍后重试'); }
                if ($chunk === '' && !$body->eof()) throw new RuntimeException('读取豆瓣资料超时，请稍后重试');
                $text .= $chunk;
            }
            if (strlen($text) > self::MAX_BODY_BYTES) throw new RuntimeException('豆瓣响应过大，已停止读取');
            return ['status' => $response->getStatusCode(), 'body' => $text, 'location' => $response->getHeaderLine('Location')];
        } finally {
            $body->close();
        }
    }

    /** Only follow Douban's subject-type redirects, never arbitrary Location URLs. */
    private function requestJson(ClientInterface $client, string $subjectId, string $url, float $deadline): array
    {
        $visited = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (isset($visited[$url])) break;
            $visited[$url] = true;
            $response = $this->request($client, $url, true, $deadline);
            $this->assertNotBlocked($response);
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                try { $next = UriResolver::resolve(new Uri($url), new Uri($response['location'])); }
                catch (\InvalidArgumentException) { throw new RuntimeException('豆瓣接口返回了无效跳转'); }
                $suffix = str_ends_with($url, '/credits') ? '/credits' : '';
                if ($response['location'] === '' || $next->getScheme() !== 'https' || $next->getHost() !== 'm.douban.com'
                    || $next->getPort() !== null || $next->getUserInfo() !== '' || $next->getQuery() !== '' || $next->getFragment() !== ''
                    || !preg_match('#^/rexxar/api/v2/(?:movie|tv)/' . $subjectId . $suffix . '/?$#D', $next->getPath())) {
                    throw new RuntimeException('豆瓣要求验证或返回了不支持的跳转，请检查豆瓣 Cookie 后稍后重试');
                }
                $url = rtrim((string) $next, '/');
                continue;
            }
            if ($response['status'] !== 200) throw new RuntimeException('豆瓣接口返回状态 ' . $response['status'] . '，请检查条目 ID 后稍后重试');
            $payload = json_decode($response['body'], true);
            if (!is_array($payload)) throw new RuntimeException('豆瓣没有返回有效资料，可能需要登录验证');
            return [$payload, $url];
        }
        throw new RuntimeException('豆瓣接口跳转过多，已停止请求');
    }

    private function assertNotBlocked(array $response): void
    {
        if ($response['status'] === 429 || preg_match('/(?:_saf=004|错误代码\s*[：:]?\s*004)/u', $response['body'])) {
            throw new RuntimeException('豆瓣访问受限或请求过于频繁，请稍后重试', 429);
        }
        if (in_array($response['status'], [401, 403], true)) throw new RuntimeException('豆瓣拒绝访问，请检查豆瓣 Cookie 后稍后重试', 429);
    }

    private function needsFallback(array $data): bool
    {
        if ($data === []) return true;
        foreach (['title', 'director', 'actor', 'writer', 'type', 'release_date', 'poster_url', 'area', 'release_year', 'length', 'language', 'content', 'imdb_id'] as $field) {
            if (($data[$field] ?? '') === '' || ($data[$field] ?? 0) === 0) return true;
        }
        return false;
    }

    /** The official JSON response supplies its own app detail page, also for TV. */
    private function supplementImdb(ClientInterface $client, string $subjectId, array $payload, float $deadline): string
    {
        $url = $payload['info_url'] ?? null;
        if (!is_string($url)) return '';
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.douban.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('#^/doubanapp/{1,2}h5/movie/' . $subjectId . '/desc$#D', (string) ($parts['path'] ?? ''))) return '';
        try {
            // Never follow a verification redirect or fetch a payload-supplied host.
            $response = $this->request($client, 'https://www.douban.com/doubanapp//h5/movie/' . $subjectId . '/desc', false, $deadline, 5);
            if ($response['status'] !== 200) return '';
            return $this->mapAppImdb($response['body'], (string) $payload['title']);
        } catch (RuntimeException) {
            return ''; // Supplement failure must not discard already fetched metadata.
        }
    }

    private function mapAppImdb(string $html, string $title): string
    {
        if ($html === '' || !class_exists(DOMDocument::class)) return '';
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        try { $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) return '';
        $xpath = new DOMXPath($dom);
        $fields = [];
        foreach ($xpath->query('//section[contains(concat(" ",normalize-space(@class)," ")," subject-desc ")]//tr') as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length !== 2) continue;
            $fields[trim($cells->item(0)->textContent)] = trim($cells->item(1)->textContent);
        }
        if (($fields['片名'] ?? '') !== trim($title)) return '';
        $imdb = strtolower($fields['IMDb'] ?? '');
        return preg_match('/^tt\d{5,12}$/D', $imdb) ? $imdb : '';
    }

    private function mergeMissing(array $primary, array $fallback): array
    {
        foreach ($fallback as $key => $value) {
            if (!isset($primary[$key]) || $primary[$key] === '' || $primary[$key] === 0 || $primary[$key] === 0.0) $primary[$key] = $value;
        }
        return $primary;
    }

    private function creditWriters(mixed $rows): string
    {
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            // `roles` describes a person's entire career, NOT their credit on this video.
            $category = is_string($row['category'] ?? null) ? trim($row['category']) : '';
            $character = is_string($row['character'] ?? null) ? trim($row['character']) : '';
            if ((preg_match('/^(?:编剧|screenwriter|writer)$/iu', $category) || preg_match('/^(?:编剧|screenwriter|writer)(?:\s|$)/iu', $character))
                && is_string($row['name'] ?? null)) $names[] = trim($row['name']);
        }
        return implode(',', array_unique(array_filter($names)));
    }

    /** Unified desktop subject pages contain films, TV, animation and variety shows. */
    public function mapSubjectPage(string $subjectId, string $html): array
    {
        if ($html === '' || !class_exists(DOMDocument::class)) return [];
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        try { $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) return [];
        $xpath = new DOMXPath($dom);
        $text = static function (string $query) use ($xpath): string {
            $nodes = $xpath->query($query);
            return trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', $nodes?->item(0)?->textContent ?? ''));
        };
        $reviewed = $text('//span[@property="v:itemreviewed"]');
        if ($reviewed === '' || $xpath->query('//*[@id="info"]')->length === 0) return [];
        $canonical = $text('//link[@rel="canonical"]/@href');
        if (preg_match('#/subject/(\d+)#', $canonical, $match) && $match[1] !== $subjectId) return [];
        $info = static function (string $label) use ($xpath): string {
            foreach ($xpath->query('//*[@id="info"]//span[contains(concat(" ",normalize-space(@class)," ")," pl ")]') as $node) {
                if (rtrim(trim($node->textContent), ':： ') !== $label) continue;
                $value = '';
                for ($sibling = $node->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                    if ($sibling instanceof DOMElement && ($sibling->tagName === 'br' || preg_match('/(?:^|\s)pl(?:\s|$)/', $sibling->getAttribute('class')))) break;
                    $value .= $sibling->textContent;
                }
                return trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', ltrim(trim($value), ':：')));
            }
            return '';
        };
        $split = static fn (string $value): array => array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*|[,，、;；|]+/u', $value) ?: [])));
        $list = static function (string $query) use ($xpath): array {
            $result = [];
            foreach ($xpath->query($query) as $node) if (trim($node->textContent) !== '') $result[] = trim($node->textContent);
            return $result;
        };
        $title = explode(',', $text('//meta[translate(@name,"KEYWORDS","keywords")="keywords"]/@content'))[0];
        if ($title === '') $title = trim((string) preg_replace('/\s*[（(]豆瓣[)）]\s*$/u', '', $text('//title')));
        if ($title === '') $title = $reviewed;
        $releaseDates = $list('//span[@property="v:initialReleaseDate"]/@content');
        if ($releaseDates === []) $releaseDates = $list('//span[@property="v:initialReleaseDate"]');
        $runtime = $text('//span[@property="v:runtime"]');
        if ($runtime === '') $runtime = $info('单集片长') ?: $info('片长');
        $payload = [
            'title' => $title, 'aka' => $split($info('又名')),
            'year' => (int) trim($text('//span[contains(concat(" ",normalize-space(@class)," ")," year ")]'), '()（） '),
            'cover_url' => $text('//*[@id="mainpic"]//img/@src'),
            'directors' => $list('//*[@id="info"]//*[@rel="v:directedBy"]') ?: $split($info('导演')),
            'actors' => $list('//*[@id="info"]//*[@rel="v:starring"]') ?: $split($info('主演')),
            'writers' => $split($info('编剧')),
            'genres' => $list('//*[@id="info"]//span[@property="v:genre"]'),
            'countries' => $split($info('制片国家/地区')), 'languages' => $split($info('语言')),
            'release_date' => $releaseDates, 'durations' => [$runtime], 'episodes_count' => (int) $info('集数'),
            'rating' => ['value' => (float) $text('//*[@property="v:average"]'), 'count' => (int) str_replace(',', '', $text('//*[@property="v:votes"]'))],
            'imdb_id' => $info('IMDb'),
            'intro' => $text('//span[contains(concat(" ",normalize-space(@class)," ")," all ") and contains(concat(" ",normalize-space(@class)," ")," hidden ")]') ?: $text('//span[@property="v:summary"]'),
        ];

        return $this->mapPayload($subjectId, $payload);
    }

    public function mapPayload(string $subjectId, array $payload): array
    {
        $names = static fn (mixed $rows): string => implode(',', array_values(array_filter(array_map(
            static fn (mixed $row): string => is_array($row) && is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : (is_scalar($row) ? trim((string) $row) : ''),
            is_array($rows) ? $rows : []
        ))));
        $strings = static fn (mixed $rows): string => implode(',', array_values(array_filter(array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', is_array($rows) ? $rows : []))));
        $rating = is_array($payload['rating'] ?? null) ? $payload['rating'] : [];
        $poster = is_array($payload['pic'] ?? null) ? (string) (($payload['pic']['large'] ?? $payload['pic']['normal'] ?? '')) : (string) ($payload['cover_url'] ?? '');
        if ($poster === '') $poster = (string) ($payload['cover_url'] ?? '');
        $releaseDates = [];
        foreach (array_merge((array) ($payload['release_date'] ?? []), (array) ($payload['pubdate'] ?? [])) as $date) {
            if (is_string($date) && preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $date, $match)
                && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                $releaseDates[] = sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]);
            }
        }
        $originalDates = array_filter($releaseDates, static fn (string $date): bool => (int) substr($date, 0, 4) === (int) ($payload['year'] ?? 0));
        // Never mistake a re-release for the original release, or invent January 1.
        if ((int) ($payload['year'] ?? 0) > 0) $releaseDates = $originalDates;
        sort($releaseDates, SORT_STRING);
        $releaseDate = $releaseDates[0] ?? '';
        $length = 0;
        if (preg_match('/(\d+)\s*分钟/u', (string) (($payload['durations'][0] ?? '')), $match)) $length = (int) $match[1] * 60;
        $tags = [];
        foreach ((array) ($payload['tags'] ?? []) as $tag) {
            $value = is_array($tag) ? (string) ($tag['name'] ?? '') : (string) $tag;
            if ($value !== '') $tags[] = $value;
        }
        $subtitle = array_values(array_filter(array_merge([(string) ($payload['original_title'] ?? '')], array_map('strval', (array) ($payload['aka'] ?? [])))));
        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
        $imdb = trim((string) ($payload['imdb_id'] ?? $payload['imdb'] ?? $extra['imdb'] ?? ''));
        if ($imdb !== '' && preg_match('/tt\d{5,12}/i', $imdb, $match)) $imdb = strtolower($match[0]);
        else $imdb = '';

        return [
            'douban_id' => $subjectId,
            'title' => trim((string) ($payload['title'] ?? '')),
            'original_title' => trim((string) ($payload['original_title'] ?? '')),
            'subtitle' => implode(' / ', array_slice(array_unique($subtitle), 0, 8)),
            'poster_url' => $poster,
            'release_year' => (int) ($payload['year'] ?? 0),
            'release_date' => $releaseDate,
            'area' => (new CollectionPayloadNormalizer($this->settings))->normalizeArea($strings($payload['countries'] ?? [])),
            'language' => (new CollectionPayloadNormalizer($this->settings))->normalizeLanguage($strings($payload['languages'] ?? [])),
            'type' => $strings($payload['genres'] ?? []),
            'tags' => $tags !== [] ? implode(',', array_slice(array_unique($tags), 0, 20)) : $strings($payload['genres'] ?? []),
            'director' => $names($payload['directors'] ?? []),
            'actor' => $names($payload['actors'] ?? []),
            'writer' => $names($payload['writers'] ?? $payload['screenwriters'] ?? []),
            'content' => trim((string) ($payload['intro'] ?? '')),
            'imdb_id' => $imdb,
            'douban_score' => (float) ($rating['value'] ?? 0),
            'rating' => (float) ($rating['value'] ?? 0),
            'rating_count' => (int) ($rating['count'] ?? 0),
            'length' => $length,
            'episode_total' => max(0, (int) ($payload['episodes_count'] ?? 0)),
            'episode_label' => trim((string) ($payload['episodes_info'] ?? '')),
            'source_ref' => '[douban]=[' . $subjectId . ']',
        ];
    }
}
