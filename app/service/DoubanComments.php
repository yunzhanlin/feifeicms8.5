<?php
declare(strict_types=1);

namespace app\service;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use RuntimeException;

/**
 * Reads only anonymous review text from Douban. User names, profile links,
 * avatars and vote counts are deliberately not collected.
 */
final class DoubanComments
{
    /** @return array<int, string> */
    public function fetch(string $subjectId): array
    {
        if (!preg_match('/^\d{5,12}$/', $subjectId)) {
            throw new RuntimeException('豆瓣 ID 格式无效');
        }
        $url = 'https://movie.douban.com/subject/' . $subjectId . '/comments?sort=time';
        $client = new Client([
            'timeout' => 15,
            'connect_timeout' => 6,
            'allow_redirects' => ['max' => 2, 'strict' => true],
            'http_errors' => false,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'Referer' => 'https://movie.douban.com/subject/' . $subjectId . '/',
                'User-Agent' => 'Mozilla/5.0 (compatible; FeiFeiCMS/8.0; +https://feifeicms.local)',
            ],
        ]);
        $response = $client->get($url);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('豆瓣评论页返回状态 ' . $response->getStatusCode());
        }
        $comments = $this->parseHtml((string) $response->getBody());
        if ($comments === []) {
            throw new RuntimeException('页面未解析到评论，可能触发了访问限制');
        }
        return $comments;
    }

    /** @return array<int, string> */
    public function parseHtml(string $html): array
    {
        if ($html === '') return [];
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return [];
        $nodes = (new DOMXPath($dom))->query('//span[contains(concat(" ", normalize-space(@class), " "), " short ")]');
        if ($nodes === false) return [];
        $comments = [];
        foreach ($nodes as $node) {
            $content = html_entity_decode(strip_tags((string) $node->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', $content));
            if (mb_strlen($content) < 2) continue;
            $content = mb_substr($content, 0, 1000);
            $comments[hash('sha256', $content)] = $content;
        }
        return array_values($comments);
    }
}
