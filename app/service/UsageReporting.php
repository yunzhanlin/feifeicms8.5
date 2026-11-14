<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use PDO;
use think\facade\Db;

final class UsageReporting
{
    public const ENDPOINT = 'https://tj.shankan.cc/v1/heartbeat';
    public const STATE_KEY = 'internal.usage_reporting.v1';
    public const ENABLED_KEY = 'admin.base.usage_reporting';
    private const INTERVAL = 86400;

    public function __construct(private readonly \think\App $app) {}

    /** @return array{status:string} */
    public function report(string $source = 'cron', ?PDO $pdo = null, string $installedUrl = ''): array
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
            if ($pdo === null) {
                Db::query('SELECT 1');
                $pdo = Db::connect()->getPdo();
            }
            $query = $pdo->prepare('SELECT setting_key,setting_value FROM ffx_site_settings WHERE setting_key IN (?,?)');
            $query->execute([self::ENABLED_KEY, 'admin.base.site_domain']);
            $settings = $query->fetchAll(PDO::FETCH_KEY_PAIR);
            if (isset($settings[self::ENABLED_KEY]) && !in_array(json_decode($settings[self::ENABLED_KEY], true), ['1', 1, true], true)) {
                return ['status' => 'disabled'];
            }
            $state = $this->claim($pdo, $now);
            if ($state['next_attempt'] > $now) {
                @file_put_contents($stamp, (string) $state['next_attempt'], LOCK_EX);
                return ['status' => 'not_due'];
            }
            $state['last_attempt'] = $now;
            $state['next_attempt'] = $now + 3600;
            $this->save($pdo, $state);
            $configuredHost = json_decode((string) ($settings['admin.base.site_domain'] ?? '""'), true);
            $domain = self::domain(is_string($configuredHost) ? $configuredHost : '') ?? self::domain($installedUrl)
                ?? self::domain((string) env('APP_URL', ''));
            $payload = self::payload($state['id'], (string) config('feifei.version_id'), $source,
                filter_var(env('USAGE_ENVIRONMENT', true), FILTER_VALIDATE_BOOLEAN), $domain);
            $schema = $this->send($endpoint, $payload);
            $ok = $schema !== 0;
            $state['failures'] = $ok ? 0 : min(5, (int) ($state['failures'] ?? 0) + 1);
            if ($ok) $state['last_success'] = $now;
            $state['next_attempt'] = $now + ($ok ? self::INTERVAL : min(self::INTERVAL, 3600 * (2 ** ($state['failures'] - 1))));
            $this->save($pdo, $state);
            @file_put_contents($stamp, (string) $state['next_attempt'], LOCK_EX);
            return ['status' => $ok ? ($schema === 2 ? 'sent' : 'sent_legacy') : 'failed'];
        } catch (\Throwable) {
            // Statistics must never break page rendering, installation or updates.
            return ['status' => 'unavailable'];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function enabled(): bool
    {
        return filter_var(env('USAGE_REPORTING', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function payload(string $id, string $version, string $source, bool $environment, ?string $domain = null): array
    {
        $payload = ['schema' => 2, 'installation_id' => $id, 'version' => $version, 'source' => $source === 'web' ? 'web' : 'cron'];
        if ($environment) $payload['php'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if ($domain !== null) $payload['domain'] = $domain;
        return $payload;
    }

    public static function domain(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $host = rtrim(strtolower((string) parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST)), '.');
        if ($host !== '' && preg_match('/[^\x00-\x7f]/', $host) && function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        return str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP)
            && !str_ends_with($host, '.localhost') && !str_ends_with($host, '.local')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $host : null;
    }

    public static function validEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return false;
        return ($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'], ['127.0.0.1', '[::1]', 'localhost'], true));
    }

    private function send(string $endpoint, array $payload): int
    {
        try {
            $client = new Client(['connect_timeout' => 1.5, 'timeout' => 4.0, 'allow_redirects' => false, 'http_errors' => false]);
            $options = ['headers' => ['User-Agent' => 'FeiFeiCMS-Usage/2', 'Accept' => 'application/json']];
            $response = $client->post($endpoint, $options + ['json' => $payload]);
            if ($response->getStatusCode() === 422) {
                unset($payload['domain']);
                $payload['schema'] = 1;
                $response = $client->post($endpoint, $options + ['json' => $payload, 'timeout' => 2.0]);
            }
            $body = (string) $response->getBody();
            $reply = strlen($body) <= 1024 ? json_decode($body, true) : null;
            if ($response->getStatusCode() === 200 && is_array($reply) && ($reply['ok'] ?? false) === true
                && ($reply['schema'] ?? null) === $payload['schema']) return $payload['schema'];
        } catch (\Throwable) { /* Retry on the next scheduled attempt. */ }
        return 0;
    }

    private function claim(PDO $pdo, int $now): array
    {
        $pdo->beginTransaction();
        try {
            $initial = ['id' => self::newId(), 'next_attempt' => 0, 'last_attempt' => 0, 'last_success' => 0, 'failures' => 0];
            $insert = $pdo->prepare('INSERT IGNORE INTO ffx_site_settings (setting_key,setting_value,is_public,updated_at) VALUES (?,?,0,?)');
            $insert->execute([self::STATE_KEY, json_encode($initial, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s', $now)]);
            $query = $pdo->prepare('SELECT setting_value FROM ffx_site_settings WHERE setting_key=? FOR UPDATE');
            $query->execute([self::STATE_KEY]);
            $state = json_decode((string) $query->fetchColumn(), true);
            $query->closeCursor();
            if (!is_array($state) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', (string) ($state['id'] ?? ''))) $state = $initial;
            $state['next_attempt'] = (int) ($state['next_attempt'] ?? 0);
            if ($state['next_attempt'] <= $now) {
                $lease = $state;
                $lease['next_attempt'] = $now + 3600;
                $this->save($pdo, $lease);
            }
            $pdo->commit();
            return $state;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    private function save(PDO $pdo, array $state): void
    {
        $statement = $pdo->prepare('UPDATE ffx_site_settings SET setting_value=?,is_public=0,updated_at=? WHERE setting_key=?');
        $statement->execute([json_encode($state, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s'), self::STATE_KEY]);
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
