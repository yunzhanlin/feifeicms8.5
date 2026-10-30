<?php
declare(strict_types=1);
namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

require_once __DIR__ . '/DoubanComments.php';

/** Bounded image downloads from Douban's icon hosts; files are never executable. */
final class DoubanAvatarStore
{
    private const MAX_BYTES = 262144;
    public function __construct(private readonly ?ClientInterface $client = null, private readonly ?string $publicPath = null) {}

    /** @return array<string,string> Original URL maps to a local path, or itself if temporarily unavailable. */
    public function cacheMany(array $urls, float $budget = 6): array
    {
        $deadline = microtime(true) + max(0,min(10,$budget));
        $root = rtrim($this->publicPath ?? dirname(__DIR__,2).'/public','/');
        $directory = $root.'/uploads/comments/douban';
        $result = [];
        $client = $this->client ?? new Client();
        foreach (array_unique(array_slice($urls,0,20)) as $original) {
            $url = DoubanComments::avatarUrl((string)$original);
            if ($url === '') continue;
            $hash = hash('sha256',$url);
            foreach (['jpg','png','gif','webp'] as $ext) {
                $local = '/uploads/comments/douban/'.$hash.'.'.$ext;
                if (is_file($root.$local) && !is_link($root.$local) && filesize($root.$local) > 0) { $result[$url]=$local; continue 2; }
            }
            $result[$url]=$url;
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.1) continue;
            $body = null;
            try {
                $timeout = min(2.5,$remaining);
                $response = $client->request('GET',$url,['headers'=>['Accept'=>'image/jpeg,image/png,image/gif,image/webp','Referer'=>'https://www.douban.com/',
                    'User-Agent'=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'],
                    'timeout'=>$timeout,'connect_timeout'=>min(1.5,$timeout),'read_timeout'=>min(1.5,$timeout),
                    'allow_redirects'=>false,'http_errors'=>false,'verify'=>true,'cookies'=>false,'stream'=>true]);
                $body = $response->getBody();
                if ($response->getStatusCode() !== 200 || (int)$response->getHeaderLine('Content-Length') > self::MAX_BYTES) continue;
                $bytes = '';
                while (!$body->eof() && strlen($bytes) <= self::MAX_BYTES) {
                    if (microtime(true) >= $deadline) throw new \RuntimeException('avatar deadline');
                    $chunk = $body->read(min(65536,self::MAX_BYTES+1-strlen($bytes)));
                    if ($chunk === '' && !$body->eof()) throw new \RuntimeException('avatar stalled');
                    $bytes .= $chunk;
                }
                if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) continue;
                $info = @getimagesizefromstring($bytes);
                $ext = match ($info[2] ?? 0) { IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_GIF=>'gif',IMAGETYPE_WEBP=>'webp',default=>'' };
                if ($ext === '' || (int)$info[0] > 2048 || (int)$info[1] > 2048) continue;
                if (!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory)) continue;
                $temporary = tempnam($directory,'.avatar-');
                if ($temporary === false) continue;
                try {
                    if (file_put_contents($temporary,$bytes,LOCK_EX) !== strlen($bytes)) continue;
                    chmod($temporary,0644);
                    $local = '/uploads/comments/douban/'.$hash.'.'.$ext;
                    if (rename($temporary,$root.$local)) $result[$url]=$local;
                } finally { if (is_file($temporary)) @unlink($temporary); }
            } catch (\Throwable) {
                // Keep the source URL and retry on a later import; text attribution is still useful.
            } finally { if ($body !== null) $body->close(); }
        }
        return $result;
    }
}
