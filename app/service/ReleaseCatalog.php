<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use think\facade\Cache;

/** GitHub's public release feed includes prereleases, unlike releases/latest. */
final class ReleaseCatalog
{
    public const REPO = 'yunzhanlin/feifeicms8.5';
    public const PACKAGE = 'feifeicms-update.zip';

    /** @return array{tag:string,version:string,title:string,url:string,available:bool} */
    public function latest(bool $refresh = false): array
    {
        $current = (string) config('feifei.version_id');
        // The cached "available" flag is relative to the installed version.
        // Never reuse it after an updater replaces config/version.php.
        $key = self::cacheKey($current);
        if (!$refresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) return $cached;
        }
        $client = new Client(['connect_timeout' => 3, 'timeout' => 7, 'http_errors' => true]);
        $response = $client->get('https://github.com/' . self::REPO . '/releases.atom', [
            'headers' => ['User-Agent' => 'FeiFeiCMS-Updater/8.5', 'Accept' => 'application/atom+xml'],
        ]);
        $result = self::parseFeed((string) $response->getBody(), $current);
        // GitHub emits the release feed before the packaging workflow uploads
        // its two assets. Never offer an update that cannot yet be installed.
        if ($result['available']) {
            foreach ([self::PACKAGE, self::PACKAGE . '.sha256'] as $asset) {
                $head = $client->head(self::assetUrl($result['tag'], $asset), ['http_errors' => false]);
                if ($head->getStatusCode() !== 200) {
                    $result['available'] = false;
                    $result['asset_ready'] = false;
                    break;
                }
            }
            if ($result['available']) $result['asset_ready'] = true;
        }
        Cache::set($key, $result, 300);
        return $result;
    }

    public static function cacheKey(string $current): string
    {
        return 'feifei:update:github-release:v2:' . $current;
    }

    /** @return array{tag:string,version:string,title:string,url:string,available:bool} */
    public static function parseFeed(string $xml, string $current): array
    {
        if (strlen($xml) > 2_000_000 || !str_contains($xml, '<feed')) throw new \RuntimeException('GitHub 版本信息无效');
        $feed = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($feed === false) throw new \RuntimeException('GitHub 版本信息无法解析');
        $best = null;
        foreach ($feed->entry as $entry) {
            $id = (string) $entry->id;
            if (!preg_match('~/((?:v)?([0-9]+\.[0-9]+\.[0-9]{6}(?:\.[0-9]+)?(?:-[A-Za-z0-9.-]+)?))$~', $id, $m)) continue;
            $tag = $m[1];
            $version = $m[2];
            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = ['tag' => $tag, 'version' => $version, 'title' => trim((string) $entry->title),
                    'url' => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode($tag),
                    'available' => version_compare($version, $current, '>')];
            }
        }
        if ($best === null) throw new \RuntimeException('没有找到符合版本命名规则的 GitHub Release');
        return $best;
    }

    public static function assetUrl(string $tag, string $name): string
    {
        if (!preg_match('/^v[0-9]+\.[0-9]+\.[0-9]{6}(?:\.[0-9]+)?(?:-[A-Za-z0-9.-]+)?$/', $tag)) throw new \InvalidArgumentException('版本标签无效');
        if (!in_array($name, [self::PACKAGE, self::PACKAGE . '.sha256'], true)) throw new \InvalidArgumentException('更新包名称无效');
        return 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode($tag) . '/' . $name;
    }
}
