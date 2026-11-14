<?php
declare(strict_types=1);

namespace app\service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class TvznMetadata
{
    private const ORIGIN = 'https://www.tvzn.com';

    public function __construct(private readonly PublicJsonClient $http) {}

    public function search(string $title): array
    {
        $page = $this->page('/search.aspx', ['keyword' => mb_substr($title, 0, 50)]);
        $results = [];
        foreach ($page->query('//article[' . self::hasClass('search-result') . ']//h2/a') as $link) {
            $id = self::matched($link->getAttribute('href'), '~^/([1-9]\d{0,9})/$~D');
            if ($id !== '') {
                $results[$id] = [
                    'id' => $id, 'title' => self::text($link->textContent),
                    'detail' => mb_substr(self::text($link->parentNode->parentNode->textContent), 0, 160),
                    'url' => self::ORIGIN . '/' . $id . '/',
                ];
            }
        }
        return array_slice(array_values($results), 0, 15);
    }

    /** @return array<string, string> */
    public function profile(string $id): array
    {
        if (!preg_match('/^[1-9]\d{0,9}$/D', $id)) throw new RuntimeException('TVZN 人物 ID 无效');
        $path = '/person/' . $id . '.html';
        $page = $this->page($path);
        if (self::text($page->evaluate('string(//*[@id="person-name"])')) === '') {
            throw new RuntimeException('TVZN 未返回有效的演员档案');
        }
        $facts = [];
        foreach ($page->query('//*[' . self::hasClass('person-facts') . ']//dt') as $term) {
            $facts[self::text($term->textContent)] = self::text($page->evaluate('string(following-sibling::*[1][self::dd])', $term));
        }

        $intro = $page->query('//*[@aria-labelledby="person-biography-title"]//*[' . self::hasClass('person-rich') . ']')->item(0);
        $photo = $page->evaluate('string((//img[' . self::hasClass('person-hero__portrait') . ']/@src)[1])');
        if (str_starts_with($photo, 'http://img.tvzn.com/')) $photo = 'https://' . substr($photo, 7);
        return [
            'aliases' => $facts['别名'] ?? '',
            'gender' => $facts['性别'] ?? '',
            'birthday' => $facts['生日'] ?? '',
            'profession' => $facts['职业'] ?? '',
            'nationality' => $facts['国籍'] ?? '',
            'native_place' => $facts['籍贯'] ?? '',
            'school' => $facts['毕业院校'] ?? '',
            'avatar_url' => $this->photo($photo),
            'biography' => $intro === null ? '' : self::text($intro->textContent),
            'source_url' => self::ORIGIN . $path,
        ];
    }

    public function preview(string $id, bool $plots, bool $cast): array
    {
        $deadline = microtime(true) + 40;
        $overview = $this->page('/' . $id . '/', [], $deadline);
        $title = self::text($overview->evaluate('string((//h1)[1])'));
        if ($title === '') throw new RuntimeException('TVZN 没有返回有效的剧目页');

        $facts = $overview->evaluate('string((//*[' . self::hasClass('overview-hero__facts') . '])[1])');
        $year = (int) self::matched($facts, '/\b((?:19|20)\d{2})\s*年/u');
        $episodes = $actors = [];
        if ($plots) {
            foreach ($this->pages($id, 'juqingjieshao', $deadline) as $page) {
                foreach ($page->query('//article[starts-with(@id,"episode-")]') as $article) {
                    $no = (int) self::matched($article->getAttribute('id'), '/^episode-([1-9][0-9]{0,4})$/D');
                    $body = $page->query('.//*[' . self::hasClass('episode__body') . ']', $article)->item(0);
                    $link = $page->query('./h3/a', $article)->item(0);
                    if (!$no || !$body || !$link) continue;
                    $path = $link->getAttribute('href');
                    if (!preg_match('~^/' . $id . '/juqingjieshao/[0-9]+\.html$~D', $path)) continue;
                    $content = $this->paragraphs($page, $body);
                    if ($content !== '') {
                        $episodes[$no] = ['episode_no' => $no, 'title' => self::text($link->textContent),
                            'content' => $content, 'url' => self::ORIGIN . $path];
                    }
                }
            }
        }
        if ($cast) {
            foreach ($this->pages($id, 'yanyuanbiao', $deadline) as $page) {
                $this->readCast($page, $actors);
            }

            $rolePath = '/' . $id . '/role.html';
            if ($overview->query('//a[@href="' . $rolePath . '"]')->length > 0) {
                $this->readRoles($this->page($rolePath, [], $deadline), $id, $actors);
            }
        }

        ksort($episodes, SORT_NUMERIC);
        return ['title' => $title, 'url' => self::ORIGIN . '/' . $id . '/', 'episodes' => array_values($episodes), 'cast' => array_values($actors), 'seasons' => [], 'year' => $year];
    }

    private function pages(string $id, string $kind, float $deadline): iterable
    {
        $paths = ['/' . $id . '/' . $kind . '.html'];
        $seen = array_fill_keys($paths, true);
        while ($paths !== []) {
            $page = $this->page(array_shift($paths), [], $deadline);
            foreach ($page->query('//nav[' . self::hasClass('pagination') . ']//a[@href]') as $link) {
                $path = $link->getAttribute('href');
                if (!isset($seen[$path]) && preg_match('~^/' . $id . '/' . $kind . '[1-9][0-9]{0,2}\.html$~D', $path)) {
                    if (count($seen) >= 60) throw new RuntimeException('TVZN 分页超过处理上限，请选择较小的采集范围');
                    $seen[$path] = true;
                    $paths[] = $path;
                }
            }
            yield $page;
        }
    }

    private function readCast(DOMXPath $page, array &$actors): void
    {
        foreach ($page->query('//*[@id="cast-introductions"]//li[a]') as $item) {
            $link = $page->query('./a[1]', $item)->item(0);
            $name = self::text($link->textContent);
            $line = self::text($item->textContent);
            $role = self::matched($line, '/^' . preg_quote($name, '/') . '\s*饰(?:演)?\s*[：:]?\s*(.*?)$/uD');
            $this->credit($actors, $link, $role);
        }

        foreach ($page->query('//*[@id="cast-introductions"]//*[' . self::hasClass('cast-bio') . ']') as $bio) {
            $heading = $page->query('.//*[' . self::hasClass('cast-bio__heading') . ']', $bio)->item(0);
            if (!$heading) continue;
            $person = $page->query('.//a', $heading)->item(0);
            if (!$person) continue;
            $role = self::matched(self::text($heading->textContent), '/^(.*?)\s*[(（].*饰\s*[)）]$/uD');
            $intro = self::text($page->evaluate('string((.//*[' . self::hasClass('cast-bio__intro') . '])[1])', $bio));
            $portrait = $page->evaluate('string((.//*[' . self::hasClass('cast-bio__portrait') . ']//img/@src)[1])', $bio);
            $this->credit($actors, $person, $role, $intro, $portrait);
        }
    }

    private function readRoles(DOMXPath $page, string $id, array &$actors): void
    {
        foreach ($page->query('//article[' . self::hasClass('role-card') . ']') as $card) {
            $role = self::text($page->evaluate('string(.//h3)', $card));
            $link = $page->query('.//p/a[contains(@href,"person/")]', $card)->item(0);
            $intro = self::text($page->evaluate('string(.//*[' . self::hasClass('role-card__description') . '])', $card));
            $photo = $page->evaluate('string(.//img/@src)', $card);
            $path = $page->evaluate('string(.//h3/a/@href)', $card);
            if ($link && $role !== '') {
                $url = preg_match('~^/' . $id . '/role/[0-9]+\.html$~D', $path) ? self::ORIGIN . $path : '';
                $this->credit($actors, $link, $role, $intro, $photo, $url);
            }
        }
    }

    private function credit(array &$actors, DOMElement $person, string $role, string $intro = '', string $photo = '', string $roleUrl = ''): void
    {
        $name = self::text($person->textContent);
        $id = self::matched($person->getAttribute('href'), '~^/person/([1-9]\d{0,9})\.html$~D');
        if ($name === '' || $id === '') return;
        if (preg_match('/^[-*－—\s]+$/uD', $intro)) $intro = '';
        $photo = $this->photo($photo);
        $key = $id . '/' . $role;
        $existing = $actors[$key] ?? [];
        $actors[$key] = [
            'id' => $id, 'name' => $name, 'character' => $role,
            'url' => self::ORIGIN . '/person/' . $id . '.html',
            'character_url' => $roleUrl !== '' ? $roleUrl : ($existing['character_url'] ?? ''),
            'character_bio' => mb_substr($intro !== '' ? $intro : ($existing['character_bio'] ?? ''), 0, 3500),
            'photo' => $photo !== '' ? $photo : ($existing['photo'] ?? ''),
        ];
    }

    private function photo(string $photo): string
    {
        if (str_starts_with($photo, '//')) $photo = 'https:' . $photo;
        if (str_starts_with($photo, '/') && !str_starts_with($photo, '//')) $photo = self::ORIGIN . $photo;
        if (!preg_match('~^https://(?:[a-z0-9-]+\.)*(?:tvzn\.com|myqcloud\.com)/[^\s]+$~iD', $photo)) $photo = '';
        return $photo;
    }

    private function paragraphs(DOMXPath $page, DOMElement $body): string
    {
        $paragraphs = [];
        foreach ($page->query('.//p', $body) as $paragraph) {
            $text = self::text($paragraph->textContent);
            if ($text !== '') $paragraphs[] = $text;
        }
        return mb_substr($paragraphs === [] ? self::text($body->textContent) : implode("\n\n", $paragraphs), 0, 18000);
    }

    private function page(string $path, array $query = [], ?float $deadline = null): DOMXPath
    {
        $remaining = $deadline === null ? 8 : min(8, (int) ceil($deadline - microtime(true)));
        if ($remaining < 1) throw new RuntimeException('TVZN 分页读取超时，请重试');
        $html = $this->http->body(self::ORIGIN . $path, $query, ['Accept' => 'text/html'], $remaining);
        if (!class_exists(DOMDocument::class)) throw new RuntimeException('TVZN 采集需要启用 PHP DOM 扩展');
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('TVZN 返回了无效的网页');
        }
        return new DOMXPath($dom);
    }

    private static function text(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00a0}\x{3000}]+/u', ' ', $value));
    }

    private static function matched(string $value, string $pattern): string
    {
        return preg_match($pattern, $value, $matches) ? $matches[1] : '';
    }

    private static function hasClass(string $value): string
    {
        return 'contains(concat(" ",normalize-space(@class)," "), " ' . $value . ' ")';
    }
}
