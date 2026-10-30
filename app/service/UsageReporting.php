<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use think\facade\Db;

/** Anonymous installation/version counts. Never reads domains, accounts or content. */
final class UsageReporting
{
    public const ENDPOINT = 'https://tj.shankan.cc/v1/heartbeat';
    public const STATE_KEY = 'internal.usage_reporting.v1';
    private const INTERVAL = 86400;

    public function __construct(private readonly \think\App $app) {}

    /** @return array{status:string} */
    public function report(string $source = 'cron'): array
    {
        if (!self::enabled()) return ['status' => 'disabled'];
        if (!is_file($this->app->getRuntimePath() . 'install.lock')) return ['status' => 'not_installed'];
        $endpoint = (string) env('USAGE_ENDPOINT', self::ENDPOINT);
        if (!self::validEndpoint($endpoint)) return ['status' => 'invalid_endpoint'];
        $stamp = $this->app->getRuntimePath() . 'usage-reporting.next';
        $now = time();
        if ((int) @file_get_contents($stamp) > $now) return ['status' => 'not_due'];
        $lock = @fopen($this->app->getRuntimePath() . 'usage-reporting.lock', 'c');
        if ($lock === false) return ['status' => 'unavailable'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['status' => 'busy']; }
        try {
            $state = $this->claim($now);
            if ($state['next_attempt'] > $now) {
                @file_put_contents($stamp, (string) $state['next_attempt'], LOCK_EX);
                return ['status' => 'not_due'];
            }
            // A dead process leaves a one-hour lease, rather than flooding retries.
            $state['last_attempt'] = $now;
            $state['next_attempt'] = $now + 3600;
            $this->save($state);
            $payload = self::payload($state['id'], (string) config('feifei.version_id'), $source,
                filter_var(env('USAGE_ENVIRONMENT', true), FILTER_VALIDATE_BOOLEAN));
            try {
                $response = (new Client(['connect_timeout' => 1.5, 'timeout' => 4.0, 'allow_redirects' => false]))->post($endpoint, [
                    'headers' => ['User-Agent' => 'FeiFeiCMS-Usage/1', 'Accept' => 'application/json'],
                    'json' => $payload,
                    'http_errors' => false,
                ]);
                $body = (string) $response->getBody();
                $reply = strlen($body) <= 1024 ? json_decode($body, true) : null;
                $ok = $response->getStatusCode() === 200 && is_array($reply) && ($reply['ok'] ?? false) === true
                    && ($reply['schema'] ?? null) === 1;
            } catch (\Throwable) { $ok = false; }
            $state['failures'] = $ok ? 0 : min(5, (int) ($state['failures'] ?? 0) + 1);
            if ($ok) $state['last_success'] = $now;
            $state['next_attempt'] = $now + ($ok ? self::INTERVAL : min(self::INTERVAL, 3600 * (2 ** ($state['failures'] - 1))));
            $this->save($state);
            @file_put_contents($stamp, (string) $state['next_attempt'], LOCK_EX);
            return ['status' => $ok ? 'sent' : 'failed'];
        } catch (\Throwable) {
            // Statistics must never break page rendering, installation or updates.
            return ['status' => 'unavailable'];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function enabled(): bool
    {
        return filter_var(env('USAGE_REPORTING', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** Explicit allowlist: no request URL, host, IP, credentials, database or content. */
    public static function payload(string $id, string $version, string $source, bool $environment): array
    {
        $payload = ['schema' => 1, 'installation_id' => $id, 'version' => $version, 'source' => $source === 'web' ? 'web' : 'cron'];
        if ($environment) $payload['php'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return $payload;
    }

    public static function validEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return false;
        return ($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'], ['127.0.0.1', '[::1]', 'localhost'], true));
    }

    private function claim(int $now): array
    {
        return Db::transaction(function () use ($now): array {
            $initial = ['id' => self::newId(), 'next_attempt' => 0, 'last_attempt' => 0, 'last_success' => 0, 'failures' => 0];
            Db::execute('INSERT IGNORE INTO ffx_site_settings (setting_key,setting_value,is_public,updated_at) VALUES (?,?,0,?)',
                [self::STATE_KEY, json_encode($initial, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s', $now)]);
            $row = Db::table('ffx_site_settings')->where('setting_key', self::STATE_KEY)->lock(true)->find();
            $state = json_decode((string) ($row['setting_value'] ?? ''), true);
            if (!is_array($state) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', (string) ($state['id'] ?? ''))) $state = $initial;
            $state['next_attempt'] = (int) ($state['next_attempt'] ?? 0);
            if ($state['next_attempt'] <= $now) {
                $lease = $state;
                $lease['next_attempt'] = $now + 3600;
                $this->save($lease);
            }
            return $state;
        });
    }

    private function save(array $state): void
    {
        Db::table('ffx_site_settings')->where('setting_key', self::STATE_KEY)->update([
            'setting_value' => json_encode($state, JSON_THROW_ON_ERROR), 'is_public' => 0, 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
