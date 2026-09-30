<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Runtime access to admin-managed settings with a short shared cache. */
final class SiteSettings
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(private readonly FrontendCache $cache) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = (int) $this->get($key, $default);
        if ($min !== null) $value = max($min, $value);
        if ($max !== null) $value = min($max, $value);
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? '1' : '0');
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    public function section(string $section): array
    {
        $prefix = 'admin.' . $section . '.';
        $result = [];
        foreach ($this->all() as $key => $value) {
            if (str_starts_with($key, $prefix)) $result[substr($key, strlen($prefix))] = $value;
        }
        return $result;
    }

    public function forget(): void
    {
        $this->values = null;
        $this->cache->invalidateSettings();
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->values !== null) return $this->values;
        $loader = static function (): array {
            $values = [];
            try {
                foreach (Db::table('ffx_site_settings')->field('setting_key,setting_value')->select()->toArray() as $row) {
                    $raw = $row['setting_value'] ?? null;
                    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                    $values[(string) $row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
                }
            } catch (\Throwable) {
                // Fresh installs may render the installer before the settings table exists.
            }
            return $values;
        };
        $values = $this->cache->settings($loader);
        $this->values = is_array($values) ? $values : [];
        return $this->values;
    }
}
