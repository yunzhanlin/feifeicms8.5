<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use think\facade\Db;

/** Performs one administrator-approved release update in a CLI worker. */
final class ProgramUpdater
{
    private const MAX_ARCHIVE = 100_000_000;
    private const MAX_UNPACKED = 300_000_000;

    public function directory(): string
    {
        $path = runtime_path() . 'updates';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('无法创建更新工作目录');
        return $path;
    }

    public function state(): ?array
    {
        $path = $this->directory() . '/job.json';
        if (!is_file($path)) return null;
        $value = json_decode((string) file_get_contents($path), true);
        return is_array($value) ? $value : null;
    }

    /** @param array{tag:string,version:string,title:string,url:string,available:bool} $release */
    public function queue(array $release): array
    {
        if (!$release['available'] || version_compare($release['version'], (string) config('feifei.version_id'), '<=')) throw new \RuntimeException('没有可安装的新版本');
        ReleaseCatalog::assetUrl($release['tag'], ReleaseCatalog::PACKAGE);
        foreach (['', 'app', 'config', 'database/migrations', 'route', 'view', 'vendor', 'public', 'public/static'] as $directory) {
            $path = root_path() . $directory;
            if (is_dir($path) && !is_writable($path)) throw new \RuntimeException('自动更新需要站点 PHP 用户可写程序目录：' . ($directory ?: '.'));
        }
        $previous = $this->state();
        if (in_array((string) ($previous['status'] ?? ''), ['queued', 'running'], true)) throw new \RuntimeException('已有更新任务正在运行');
        $job = ['id' => bin2hex(random_bytes(12)), 'tag' => $release['tag'], 'version' => $release['version'],
            'title' => $release['title'], 'status' => 'queued', 'message' => '等待安装', 'updated_at' => gmdate('c')];
        $this->save($job);
        return $job;
    }

    public function failQueued(string $id, string $reason): void
    {
        $job = $this->state();
        if ($job === null || ($job['id'] ?? '') !== $id || ($job['status'] ?? '') !== 'queued') return;
        $job['status'] = 'failed';
        $this->progress($job, $reason);
    }

    public function run(string $id): void
    {
        $dir = $this->directory();
        $lock = fopen($dir . '/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('另一项更新正在执行');
        try {
            $job = $this->state();
            if ($job === null || $job['id'] !== $id || $job['status'] !== 'queued') throw new \RuntimeException('更新任务不存在或已执行');
            $job['status'] = 'running';
            $work = $dir . '/' . $id;
            if (!mkdir($work, 0700) && !is_dir($work)) throw new \RuntimeException('无法创建更新暂存目录');
            $this->progress($job, '正在下载 GitHub Release 安装包');
            $archive = $work . '/package.zip';
            $client = new Client(['connect_timeout' => 5, 'timeout' => 180, 'http_errors' => true]);
            $client->get(ReleaseCatalog::assetUrl($job['tag'], ReleaseCatalog::PACKAGE), ['sink' => $archive]);
            if (!is_file($archive) || filesize($archive) < 100 || filesize($archive) > self::MAX_ARCHIVE) throw new \RuntimeException('更新包大小无效');
            $checksum = (string) $client->get(ReleaseCatalog::assetUrl($job['tag'], ReleaseCatalog::PACKAGE . '.sha256'))->getBody();
            if (!preg_match('/^([a-f0-9]{64})\s+feifeicms-update\.zip\s*$/i', trim($checksum), $m)
                || !hash_equals(strtolower($m[1]), hash_file('sha256', $archive))) throw new \RuntimeException('更新包 SHA-256 校验失败');
            $this->progress($job, '正在检查版本和文件清单');
            $stage = $work . '/stage';
            mkdir($stage, 0700);
            $manifest = $this->unpack($archive, $stage, $job['version']);
            $this->progress($job, '正在备份程序和数据库');
            $backup = $work . '/backup';
            mkdir($backup, 0700);
            $this->backupDatabase($backup . '/database.sql');
            $this->install($stage, $backup, array_keys($manifest['files']), $job);
            $job['status'] = 'succeeded';
            $this->progress($job, '程序已更新到 ' . $job['version'] . '，请重新登录后台');
        } catch (\Throwable $e) {
            if (isset($job) && is_array($job)) {
                $job['status'] = 'failed';
                $this->progress($job, mb_substr($e->getMessage(), 0, 300));
            }
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{format:int,version:string,files:array<string,string>} */
    public function unpack(string $archive, string $stage, string $expectedVersion): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) throw new \RuntimeException('更新包不是有效 ZIP');
        try {
            $raw = $zip->getFromName('manifest.json');
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || ($manifest['version'] ?? null) !== $expectedVersion || !is_array($manifest['files'] ?? null)) throw new \RuntimeException('更新包清单或版本不匹配');
            $files = $manifest['files'];
            if (count($files) < 10 || count($files) > 20_000 || !isset($files['config/version.php'], $files['vendor/autoload.php'], $files['app/service/ProgramUpdater.php'])) throw new \RuntimeException('更新包缺少核心文件');
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === 'manifest.json') continue;
                if (!isset($files[$name]) || !$this->allowed($name) || !preg_match('/^[a-f0-9]{64}$/', (string) $files[$name])) throw new \RuntimeException('更新包含非法文件：' . $name);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNPACKED) throw new \RuntimeException('更新包解压后过大');
                $opsys = $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) throw new \RuntimeException('更新包不能包含软链接');
                $source = $zip->getStream($name);
                if ($source === false) throw new \RuntimeException('无法读取更新文件：' . $name);
                $target = $stage . '/' . $name;
                if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
                $output = fopen($target, 'wb');
                if ($output === false) throw new \RuntimeException('无法写入暂存文件');
                stream_copy_to_stream($source, $output);
                fclose($source);
                fclose($output);
                if (!hash_equals((string) $files[$name], hash_file('sha256', $target))) throw new \RuntimeException('更新文件校验失败：' . $name);
                unset($files[$name]);
            }
            if ($files !== []) throw new \RuntimeException('更新包缺少清单中的文件');
            $identity = require $stage . '/config/version.php';
            if (($identity['version'] ?? null) !== $expectedVersion) throw new \RuntimeException('版本文件与发布版本不一致');
            return $manifest;
        } finally { $zip->close(); }
    }

    private function allowed(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)~', $path)) return false;
        if (in_array($path, ['think','composer.json','composer.lock','public/index.php','public/install.php','public/router.php','public/.htaccess','public/favicon.ico','public/robots.txt'], true)) return true;
        foreach (['app/','config/','database/migrations/','extend/','route/','view/','vendor/','public/static/','public/legacy/','public/mxstatic/','public/player/'] as $prefix) if (str_starts_with($path, $prefix)) return true;
        return false;
    }

    /** @param list<string> $files */
    private function install(string $stage, string $backup, array $files, array &$job): void
    {
        $root = rtrim(root_path(), '/');
        $changed = [];
        $migrationStarted = false;
        try {
            foreach ($files as $relative) {
                $target = $root . '/' . $relative;
                for ($parent = dirname($target); str_starts_with($parent, $root . '/'); $parent = dirname($parent)) {
                    if (is_link($parent)) throw new \RuntimeException('目标目录包含软链接：' . $relative);
                }
                if (is_link($target)) throw new \RuntimeException('目标文件是软链接：' . $relative);
                if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
                $existed = is_file($target);
                if ($existed) {
                    $save = $backup . '/' . $relative;
                    if (!is_dir(dirname($save))) mkdir(dirname($save), 0700, true);
                    if (!copy($target, $save)) throw new \RuntimeException('程序备份失败：' . $relative);
                }
                $changed[] = [$relative, $existed];
            }
            $this->progress($job, '正在安装程序文件');
            foreach ($files as $relative) {
                $target = $root . '/' . $relative;
                $temporary = $target . '.feifei-update-' . $job['id'];
                if (!copy($stage . '/' . $relative, $temporary)) throw new \RuntimeException('无法复制更新文件：' . $relative);
                chmod($temporary, is_executable($target) || $relative === 'think' ? 0755 : 0644);
                if (!rename($temporary, $target)) throw new \RuntimeException('无法替换程序文件：' . $relative);
            }
            $this->progress($job, '正在升级数据库结构');
            $current = (int) Db::table('ffx_schema_versions')->max('version');
            $schemaLock = (int) (Db::query("SELECT GET_LOCK('feifeicms_schema_upgrade', 0) AS acquired")[0]['acquired'] ?? 0) === 1;
            if (!$schemaLock) throw new \RuntimeException('另一项数据库升级正在执行');
            try {
                foreach (glob($root . '/database/migrations/[0-9][0-9][0-9]_*.sql') ?: [] as $migration) {
                    if (!preg_match('~/(\d{3})_~', $migration, $m) || (int) $m[1] <= $current) continue;
                    $migrationStarted = true;
                    foreach ((new SqlStatementStream())->fromFile($migration) as $sql) Db::execute($sql);
                    $recorded = (int) Db::table('ffx_schema_versions')->where('version', (int) $m[1])->count();
                    if ($recorded !== 1) throw new \RuntimeException('迁移未记录版本：' . basename($migration));
                    $current = (int) $m[1];
                }
            } finally {
                Db::query("SELECT RELEASE_LOCK('feifeicms_schema_upgrade')");
            }
            if (function_exists('opcache_reset')) opcache_reset();
            clearstatcache(true);
        } catch (\Throwable $e) {
            // MySQL DDL implicitly commits. Once any migration has begun, a
            // file-only rollback would leave old PHP against a newer schema.
            if (!$migrationStarted) {
                foreach (array_reverse($changed) as [$relative, $existed]) {
                    $target = $root . '/' . $relative;
                    if ($existed) copy($backup . '/' . $relative, $target);
                    else @unlink($target);
                }
            }
            if (function_exists('opcache_reset')) opcache_reset();
            if ($migrationStarted) throw new \RuntimeException('数据库迁移失败，程序文件已保留；请查看更新备份并修复迁移：' . $e->getMessage(), 0, $e);
            throw $e;
        }
    }

    private function backupDatabase(string $path): void
    {
        // ThinkPHP opens PDO lazily; getPdo() is false before the first query.
        $tables = Db::query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'ffx\\_%' AND table_type='BASE TABLE' ORDER BY table_name");
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO) throw new \RuntimeException('无法连接数据库进行备份');
        $out = fopen($path, 'wb');
        if ($out === false) throw new \RuntimeException('无法建立数据库备份');
        try {
            fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($tables as $tableRow) {
                $name = (string) ($tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? '');
                if (!preg_match('/^ffx_[a-z0-9_]+$/', $name)) continue;
                $create = $pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(\PDO::FETCH_ASSOC);
                fwrite($out, 'DROP TABLE IF EXISTS `' . $name . "`;\n" . (string) ($create['Create Table'] ?? '') . ";\n");
                $columns = Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND EXTRA NOT LIKE ?', [$name, '%GENERATED%']);
                $names = array_map(static fn (array $row): string => (string) ($row['COLUMN_NAME'] ?? $row['column_name']), $columns);
                if ($names === []) continue;
                $escaped = implode(',', array_map(static fn (string $n): string => '`' . $n . '`', $names));
                $cursor = $pdo->query('SELECT ' . $escaped . ' FROM `' . $name . '`');
                while (($row = $cursor->fetch(\PDO::FETCH_NUM)) !== false) {
                    $values = array_map(static fn (mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
                    if (fwrite($out, 'INSERT INTO `' . $name . '` (' . $escaped . ') VALUES (' . implode(',', $values) . ");\n") === false) throw new \RuntimeException('数据库备份写入失败');
                }
                $cursor->closeCursor();
            }
            $triggers = Db::query("SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'ffx\\_%' ORDER BY TRIGGER_NAME");
            if ($triggers !== []) {
                fwrite($out, "DELIMITER $$\n");
                foreach ($triggers as $trigger) {
                    $name = (string) ($trigger['TRIGGER_NAME'] ?? $trigger['trigger_name'] ?? '');
                    $table = (string) ($trigger['EVENT_OBJECT_TABLE'] ?? $trigger['event_object_table'] ?? '');
                    if (!preg_match('/^ffx_[a-z0-9_]+$/', $name) || !preg_match('/^ffx_[a-z0-9_]+$/', $table)) continue;
                    $when = strtoupper((string) ($trigger['ACTION_TIMING'] ?? $trigger['action_timing'] ?? ''));
                    $event = strtoupper((string) ($trigger['EVENT_MANIPULATION'] ?? $trigger['event_manipulation'] ?? ''));
                    if (!in_array($when, ['BEFORE', 'AFTER'], true) || !in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) continue;
                    $body = (string) ($trigger['ACTION_STATEMENT'] ?? $trigger['action_statement'] ?? '');
                    fwrite($out, 'CREATE TRIGGER `' . $name . '` ' . $when . ' ' . $event . ' ON `' . $table . '` FOR EACH ROW ' . $body . "$$\n");
                }
                fwrite($out, "DELIMITER ;\n");
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fflush($out)) throw new \RuntimeException('数据库备份无法写完');
            chmod($path, 0600);
        } finally { fclose($out); }
    }

    private function progress(array &$job, string $message): void
    {
        $job['message'] = $message;
        $job['updated_at'] = gmdate('c');
        $this->save($job);
    }

    private function save(array $job): void
    {
        $path = $this->directory() . '/job.json';
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temporary, $path)) throw new \RuntimeException('无法保存更新任务状态');
        chmod($path, 0600);
    }
}
