<?php
declare(strict_types=1);

namespace app\service;

final class CollectionPayloadNormalizer
{
    public function __construct(private readonly ?SiteSettings $settings = null)
    {
    }

    /** @param array<string, mixed> $filters
     *  @return array<string, int|string>
     */
    public function requestParams(string $protocol, string $resource, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        if ($protocol === 'feifei_json') {
            $params = ['g' => 'plus', 'm' => 'api', 'a' => $resource === 'scenario' ? 'scenario' : 'json', 'p' => $page, 'limit' => $limit];
            foreach (['h', 'wd', 'play'] as $key) {
                if (isset($filters[$key]) && trim((string) $filters[$key]) !== '') $params[$key] = (string) $filters[$key];
            }
            if (!empty($filters['t'])) $params['cid'] = (string) $filters['t'];
            if (!empty($filters['ids'])) $params['vodids'] = (string) $filters['ids'];
            return $params;
        }

        $params = ['ac' => $resource === 'video_list' ? 'list' : 'detail', 'pg' => $page, 'limit' => $limit];
        foreach (['t', 'h', 'wd', 'ids', 'play'] as $key) {
            if (isset($filters[$key]) && trim((string) $filters[$key]) !== '') $params[$key] = (string) $filters[$key];
        }
        return $params;
    }

    /** @return array{page:int,pagecount:int,limit:int,total:int,categories:array<int,array{id:string,parent_id:string,name:string}>,rows:array<int,array<string,mixed>>,protocol:string} */
    public function envelope(array $response, string $configuredProtocol): array
    {
        $protocol = $this->detectProtocol($response, $configuredProtocol);
        if ($protocol === 'feifei_json' && isset($response['status']) && (int) $response['status'] !== 200) {
            throw new \RuntimeException('飞飞采集源返回状态 ' . (int) $response['status'] . '：' . mb_substr((string) ($response['message'] ?? $response['data'] ?? ''), 0, 300));
        }
        if ($protocol === 'maccms_json' && isset($response['code']) && !in_array((int) $response['code'], [1, 200], true)) {
            throw new \RuntimeException('MacCMS 采集源返回状态 ' . (int) $response['code'] . '：' . mb_substr((string) ($response['msg'] ?? ''), 0, 300));
        }
        $pageData = is_array($response['page'] ?? null) ? $response['page'] : [];
        $rows = $response['data'] ?? $response['list'] ?? [];
        if (isset($rows['list']) && is_array($rows['list'])) $rows = $rows['list'];
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $rawCategories = $response['class'] ?? $response['types'] ?? $response['categories'] ?? ($protocol === 'feifei_json' ? ($response['list'] ?? []) : []);
        $categories = [];
        foreach (is_array($rawCategories) ? $rawCategories : [] as $category) {
            if (!is_array($category)) continue;
            $id = trim((string) ($category['type_id'] ?? $category['list_id'] ?? $category['id'] ?? ''));
            $name = trim((string) ($category['type_name'] ?? $category['list_name'] ?? $category['name'] ?? ''));
            if ($id === '' || $name === '') continue;
            $categories[] = ['id' => $id, 'parent_id' => (string) ($category['type_pid'] ?? $category['list_pid'] ?? $category['parent_id'] ?? '0'), 'name' => mb_substr($name, 0, 120)];
        }
        $limit = max(1, (int) ($response['limit'] ?? $pageData['pagesize'] ?? count($rows) ?: 20));
        $total = max(0, (int) ($response['total'] ?? $pageData['recordcount'] ?? count($rows)));
        return [
            'page' => max(1, (int) (is_array($response['page'] ?? null) ? ($pageData['pageindex'] ?? 1) : ($response['page'] ?? 1))),
            'pagecount' => max(1, (int) ($response['pagecount'] ?? $response['page_count'] ?? $pageData['pagecount'] ?? 1)),
            'limit' => $limit, 'total' => $total, 'categories' => $categories, 'rows' => $rows, 'protocol' => $protocol,
        ];
    }

    public function detectProtocol(array $response, string $configuredProtocol = ''): string
    {
        if ($configuredProtocol === 'feifei_json' || $configuredProtocol === 'maccms_json') return $configuredProtocol;
        return isset($response['status'], $response['page']) && is_array($response['page'] ?? null) ? 'feifei_json' : 'maccms_json';
    }

    /** @return array<string, mixed> */
    public function media(array $row): array
    {
        $metadata = [
            'actor' => $this->plainText($row, ['vod_actor']), 'director' => $this->plainText($row, ['vod_director']),
            'writer' => $this->plainText($row, ['vod_writer']), 'producer' => $this->plainText($row, ['vod_producer']),
            'camera' => $this->plainText($row, ['vod_camera']), 'editor' => $this->plainText($row, ['vod_editor']),
            'music' => $this->plainText($row, ['vod_music']), 'art' => $this->plainText($row, ['vod_art']),
            'version' => $this->plainText($row, ['vod_version']), 'state' => $this->plainText($row, ['vod_state', 'vod_remarks']),
            'tv' => $this->plainText($row, ['vod_tv']), 'weekday' => $this->plainText($row, ['vod_weekday']),
            'series' => $this->plainText($row, ['vod_series']), 'keywords' => $this->plainText($row, ['vod_keywords']),
            'type' => $this->normalizeList($this->plainText($row, ['vod_class', 'vod_type'])),
            'source_ref' => $this->text($row, ['vod_reurl']),
            'legacy_ename' => $this->plainText($row, ['vod_ename']), 'inputer' => $this->plainText($row, ['vod_inputer']),
            'server' => $this->text($row, ['vod_server']), 'jumpurl' => $this->text($row, ['vod_jumpurl']),
            'color' => $this->plainText($row, ['vod_color']), 'tmdb_id' => $this->text($row, ['vod_tmdb_id']),
            'tmdb_type' => $this->text($row, ['vod_tmdb_type']), 'platform_url' => $this->text($row, ['vod_platform_url']),
            'slide_image' => $this->text($row, ['vod_pic_slide']), 'length' => max(0, (int) $this->text($row, ['vod_duration', 'vod_length'])),
            'copyright' => max(0, (int) $this->text($row, ['vod_copyright'])), 'extend' => $row['vod_extend'] ?? null,
        ];
        return [
            'external_id' => $this->text($row, ['vod_id', 'id']),
            'title' => mb_substr($this->plainText($row, ['vod_name', 'name', 'title']), 0, 255),
            'category_remote_id' => $this->text($row, ['type_id', 'vod_cid']),
            'original_title' => mb_substr($this->plainText($row, ['vod_en', 'vod_name_en']), 0, 255),
            'subtitle' => mb_substr($this->plainText($row, ['vod_sub', 'vod_title']), 0, 255),
            'content' => $this->text($row, ['vod_content', 'vod_blurb', 'vod_plot']),
            'poster_url' => mb_substr($this->text($row, ['vod_pic']), 0, 1000),
            'backdrop_url' => mb_substr($this->text($row, ['vod_pic_bg', 'vod_pic_thumb']), 0, 1000),
            'area' => mb_substr($this->normalizeArea($this->text($row, ['vod_area'])), 0, 80),
            'language' => mb_substr($this->normalizeLanguage($this->text($row, ['vod_lang', 'vod_language'])), 0, 80),
            'release_year' => (($year = (int) $this->text($row, ['vod_year'])) > 0 && $year < 10000) ? $year : null,
            'release_date' => $this->date($this->text($row, ['vod_pubdate', 'vod_filmtime'])),
            'episode_total' => (($total = (int) $this->text($row, ['vod_total'])) > 0) ? $total : null,
            'episode_label' => mb_substr($this->plainText($row, ['vod_remarks', 'vod_continu', 'vod_state']), 0, 50),
            'is_completed' => (int) ((bool) ($row['vod_isend'] ?? false)),
            'douban_id' => mb_substr($this->text($row, ['vod_douban_id']), 0, 32),
            'imdb_id' => mb_substr($this->text($row, ['vod_imdb_id']), 0, 32),
            'rating' => (($rating = (float) $this->text($row, ['vod_score', 'vod_gold'])) > 0) ? min(10, $rating) : null,
            'rating_count' => max(0, (int) $this->text($row, ['vod_score_num', 'vod_golder'])),
            'view_count' => max(0, (int) $this->text($row, ['vod_hits'])),
            'like_count' => max(0, (int) $this->text($row, ['vod_up'])),
            'dislike_count' => max(0, (int) $this->text($row, ['vod_down'])),
            'weight' => (int) $this->text($row, ['vod_level', 'vod_stars']),
            'access_mode' => ((int) $this->text($row, ['vod_ispay'])) > 0 ? 'member' : 'free',
            'price_points' => max(0, (int) $this->text($row, ['vod_price'])),
            'play_from' => $this->sourceNames($this->text($row, ['vod_play_from', 'vod_play', 'vod_from'])),
            'play_url' => $this->text($row, ['vod_play_url', 'vod_url']),
            'down_from' => $this->sourceNames($this->text($row, ['vod_down_from'])), 'down_url' => $this->text($row, ['vod_down_url']),
            'metadata' => array_filter($metadata, static fn (mixed $value): bool => $value !== ''),
            'scenario' => $row['vod_scenario'] ?? null,
        ];
    }

    /** @return array<int, array{episode_no:int,title:string,content:string}> */
    public function scenarios(mixed $payload): array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($payload)) return [];
        $items = is_array($payload['info'] ?? null) ? $payload['info'] : $payload;
        $zeroBasedList = array_is_list($items);
        $result = [];
        $position = 0;
        foreach ($items as $key => $item) {
            $position++;
            $content = trim((string) (is_array($item) ? ($item['content'] ?? $item['scenario_content'] ?? $item['text'] ?? '') : $item));
            if ($content === '') continue;
            $episode = is_array($item) ? (int) ($item['episode_no'] ?? $item['scenario_pid'] ?? $item['pid'] ?? 0) : 0;
            if ($episode < 1) $episode = !$zeroBasedList && is_numeric($key) && (int) $key > 0 ? (int) $key : $position;
            $title = trim((string) (is_array($item) ? ($item['title'] ?? $item['name'] ?? $item['scenario_name'] ?? '') : ''));
            $result[] = ['episode_no' => $episode, 'title' => mb_substr($title !== '' ? $title : '第' . $episode . '集', 0, 255), 'content' => $content];
        }
        return $result;
    }

    public function normalizeArea(string $value): string
    {
        if ($this->settings !== null && !$this->settings->bool('admin.collection.normalize_area', true)) {
            return $this->normalizeTokens($value, static fn (string $token): string => $token);
        }
        return $this->normalizeTokens($value, static function (string $token): string {
            if (str_starts_with($token, '中国')) return $token;
            return match ($token) { '大陆', '内地' => '中国大陆', '香港' => '中国香港', '台湾' => '中国台湾', default => $token };
        });
    }

    public function normalizeLanguage(string $value): string
    {
        if ($this->settings !== null && !$this->settings->bool('admin.collection.normalize_language', true)) {
            return $this->normalizeTokens($value, static fn (string $token): string => $token);
        }
        return $this->normalizeTokens($value, static fn (string $token): string => in_array($token, ['汉语', '普通话', '汉语普通话'], true) ? '国语' : $token);
    }

    private function normalizeTokens(string $value, callable $normalizer): string
    {
        $tokens = preg_split('/[,，、\/／|｜;；]+/u', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') continue;
            $token = $normalizer($token);
            $token = $this->replaceToken($token);
            if (!in_array($token, $result, true)) $result[] = $token;
        }
        return implode(',', $result);
    }

    private function replaceToken(string $token): string
    {
        if ($this->settings === null) return $token;
        $rules = preg_split('/\R/u', $this->settings->string('admin.collection.replace_rules')) ?: [];
        foreach ($rules as $rule) {
            if (!str_contains($rule, '=>')) continue;
            [$from, $to] = array_map('trim', explode('=>', $rule, 2));
            if ($from !== '' && $token === $from) return $to;
        }
        return $token;
    }

    private function normalizeList(string $value): string
    {
        return $this->normalizeTokens($value, static fn (string $token): string => $token);
    }

    private function sourceNames(string $value): string
    {
        return str_contains($value, '$$$') ? $value : str_replace([',', '，'], '$$$', $value);
    }

    /** @param array<int, string> $keys */
    private function text(array $row, array $keys): string
    {
        foreach ($keys as $key) if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') return trim((string) $row[$key]);
        return '';
    }

    /** @param array<int, string> $keys */
    private function plainText(array $row, array $keys): string
    {
        $value = $this->text($row, $keys);
        // Some collectors encode HTML entities more than once. Decode to a
        // stable value before stripping markup, then escape only at render time.
        for ($pass = 0; $pass < 8; $pass++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) break;
            $value = $decoded;
        }
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    private function date(string $value): ?string
    {
        if ($value === '' || $value === '0') return null;
        if (preg_match('/(19|20)\d{2}[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', $value, $m)) {
            $date = sprintf('%04d-%02d-%02d', (int) substr($m[0], 0, 4), (int) $m[2], (int) $m[3]);
            return checkdate((int) $m[2], (int) $m[3], (int) substr($m[0], 0, 4)) ? $date : null;
        }
        return null;
    }
}
