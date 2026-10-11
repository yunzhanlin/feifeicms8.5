<?php
declare(strict_types=1);
namespace app\service;

/** Durable marker deliberately lives outside SQL backups and data caches. */
final class SearchConsistency
{
    public function __construct(private readonly ?string $directory = null) {}

    private function path(): string { return ($this->directory ?? runtime_path()) . '/search-rebuild.required'; }

    public function generation(): string
    {
        $path = $this->path();
        if (!is_file($path)) return '';
        $value = file_get_contents($path);
        if ($value === false) throw new \RuntimeException('无法读取搜索重建标记');
        return trim($value) ?: 'required';
    }

    public function requireRebuild(): void
    {
        $path = $this->path();
        $temporary = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, bin2hex(random_bytes(16)), LOCK_EX) === false) throw new \RuntimeException('无法写入搜索重建标记；恢复未开始');
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('无法保存搜索重建标记；恢复未开始'); }
    }

    /** Must hold the same DB lock as restoration and full synchronization. */
    public function complete(string $generation): void
    {
        if ($generation !== '' && hash_equals($generation, $this->generation()) && !unlink($this->path())) throw new \RuntimeException('无法清除搜索重建标记');
    }
}
