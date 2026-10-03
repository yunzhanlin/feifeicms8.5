<?php
declare(strict_types=1);

namespace app\service;

use PDO;
use PDOException;
use RuntimeException;
use think\facade\Log;
use Throwable;

final class WebInstaller
{
    public function __construct(private readonly PasswordHasher $passwordHasher, private readonly SqlStatementStream $sql)
    {
    }

    /** @return list<array{label:string,ok:bool,value:string,required:bool}> */
    public function environmentChecks(): array
    {
        $root = root_path();
        $runtime = runtime_path();
        $uploads = $root . 'public' . DIRECTORY_SEPARATOR . 'uploads';

        return [
            ['label' => 'PHP 8.2 - 8.5', 'ok' => PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80600, 'value' => PHP_VERSION, 'required' => true],
            ['label' => 'PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? '已安装' : '未安装', 'required' => true],
            ['label' => 'JSON', 'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? '已安装' : '未安装', 'required' => true],
            ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? '已安装' : '未安装', 'required' => true],
            ['label' => 'OpenSSL', 'ok' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? '已安装' : '未安装', 'required' => true],
            ['label' => 'cURL', 'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? '已安装' : '未安装', 'required' => true],
            ['label' => 'runtime 可写', 'ok' => is_dir($runtime) && is_writable($runtime), 'value' => $runtime, 'required' => true],
            ['label' => 'uploads 可写', 'ok' => is_dir($uploads) && is_writable($uploads), 'value' => $uploads, 'required' => true],
            ['label' => '.env 可创建', 'ok' => is_file($root . '.env') ? is_writable($root . '.env') : is_writable($root), 'value' => $root . '.env', 'required' => true],
        ];
    }

    public function environmentReady(): bool
    {
        foreach ($this->environmentChecks() as $check) {
            if ($check['required'] && !$check['ok']) return false;
        }
        return true;
    }

    public function isLocked(): bool
    {
        return is_file($this->lockPath());
    }

    public function isInstalled(): bool
    {
        if ($this->isLocked()) return true;
        try {
            $database = (string) config('database.connections.mysql.database', '');
            if ($database === '') return false;
            return (int) \think\facade\Db::query(
                "SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ffx_schema_versions'"
            )[0]['total'] > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{database_version:string,tables:int,admin:string}
     */
    public function install(array $input): array
    {
        if ($this->isLocked()) throw new RuntimeException('程序已安装；如需重装，请先备份数据并手工删除 runtime/install.lock。');
        if (!$this->environmentReady()) throw new RuntimeException('服务器环境检查未通过。');

        $installGuard = fopen(runtime_path() . 'installing.lock', 'c+');
        if ($installGuard === false || !flock($installGuard, LOCK_EX | LOCK_NB)) {
            if (is_resource($installGuard)) fclose($installGuard);
            throw new RuntimeException('另一个安装过程正在运行，请稍后再试。');
        }

        try {
            $values = $this->validate($input);
            $pdo = $this->connect($values);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!MySqlCompatibility::supports($version)) {
                throw new RuntimeException('需要 ' . MySqlCompatibility::requirement() . '，当前版本为 ' . ($version ?: '未知') . '。');
            }

            $existing = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'")->fetchColumn();
            if ($existing > 0) {
                throw new RuntimeException('目标数据库已存在 ffx_ 数据表，为防止覆盖已停止安装。');
            }

            $environment = $this->buildEnvironment($values);
            $temporaryEnvironment = root_path() . '.env.installing';
            if (file_put_contents($temporaryEnvironment, $environment, LOCK_EX) === false) {
                throw new RuntimeException('无法写入临时环境配置。');
            }
            @chmod($temporaryEnvironment, 0600);
            $environmentPath = root_path() . '.env';
            $environmentActivated = false;

            try {
                $this->executeSchema($pdo);
                $this->createAdministrator($pdo, $values);
                $this->saveSiteSettings($pdo, $values);

                if (!@rename($temporaryEnvironment, $environmentPath)) {
                    throw new RuntimeException('数据库已完成，但 .env 配置无法生效，请检查站点根目录权限。');
                }
                $environmentActivated = true;
                @chmod($environmentPath, 0600);
                $this->writeLock($version);
            } catch (Throwable $exception) {
                @unlink($temporaryEnvironment);
                if ($environmentActivated) @unlink($environmentPath);
                $this->rollbackFreshSchema($pdo);
                throw $exception;
            }

            $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'")->fetchColumn();
            return ['database_version' => $version, 'tables' => $tables, 'admin' => $values['admin_username']];
        } finally {
            flock($installGuard, LOCK_UN);
            fclose($installGuard);
        }
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function validate(array $input): array
    {
        $value = static fn (string $key, string $default = ''): string => trim((string) ($input[$key] ?? $default));
        $values = [
            'site_name' => mb_substr($value('site_name', '飞飞影视'), 0, 100),
            'app_url' => rtrim($value('app_url'), '/'),
            'db_host' => $value('db_host', '127.0.0.1'),
            'db_port' => $value('db_port', '3306'),
            'db_name' => $value('db_name'),
            'db_user' => $value('db_user'),
            'db_pass' => (string) ($input['db_pass'] ?? ''),
            'admin_username' => $value('admin_username', 'admin'),
            'admin_email' => $value('admin_email'),
            'admin_password' => (string) ($input['admin_password'] ?? ''),
            'admin_password_confirm' => (string) ($input['admin_password_confirm'] ?? ''),
            'cache_driver' => $value('cache_driver', 'file'),
            'redis_host' => $value('redis_host', '127.0.0.1'),
            'redis_port' => $value('redis_port', '6379'),
            'redis_password' => (string) ($input['redis_password'] ?? ''),
            'redis_db' => $value('redis_db', '0'),
            'search_driver' => $value('search_driver', 'mysql'),
            'meilisearch_host' => $value('meilisearch_host', 'http://127.0.0.1:7700'),
            'meilisearch_key' => (string) ($input['meilisearch_key'] ?? ''),
        ];

        if ($values['site_name'] === '') throw new RuntimeException('请填写站点名称。');
        if ($values['app_url'] !== '' && filter_var($values['app_url'], FILTER_VALIDATE_URL) === false) throw new RuntimeException('站点地址格式不正确。');
        if ($values['db_host'] === '' || !preg_match('/^\d{1,5}$/', $values['db_port']) || (int) $values['db_port'] > 65535) throw new RuntimeException('数据库主机或端口不正确。');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_name'])) throw new RuntimeException('数据库名只能包含字母、数字和下划线。');
        if ($values['db_user'] === '') throw new RuntimeException('请填写数据库用户名。');
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/', $values['admin_username'])) throw new RuntimeException('管理员用户名需为 3-80 位字母、数字或 _ . @ -。');
        if ($values['admin_email'] !== '' && filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('管理员邮箱格式不正确。');
        if (mb_strlen($values['admin_password']) < 12) throw new RuntimeException('管理员密码至少 12 位。');
        if (!hash_equals($values['admin_password'], $values['admin_password_confirm'])) throw new RuntimeException('两次输入的管理员密码不一致。');
        if (!in_array($values['cache_driver'], ['file', 'redis'], true)) throw new RuntimeException('缓存驱动不正确。');
        if (!in_array($values['search_driver'], ['mysql', 'meilisearch'], true)) throw new RuntimeException('搜索驱动不正确。');
        if (!preg_match('/^\d{1,5}$/', $values['redis_port']) || (int) $values['redis_port'] > 65535) throw new RuntimeException('Redis 端口不正确。');
        if (!preg_match('/^\d+$/', $values['redis_db']) || (int) $values['redis_db'] > 15) throw new RuntimeException('Redis 数据库需为 0-15。');
        return $values;
    }

    /** @param array<string, string> $values */
    private function connect(array $values): PDO
    {
        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['db_host'], (int) $values['db_port']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        try {
            $server = new PDO($serverDsn, $values['db_user'], $values['db_pass'], $options);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $values['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            return new PDO($serverDsn . ';dbname=' . $values['db_name'], $values['db_user'], $values['db_pass'], $options);
        } catch (PDOException $exception) {
            Log::error('安装程序数据库连接或创建失败', ['exception' => $exception->getMessage()]);
            throw new RuntimeException('数据库连接或创建失败，请检查主机、端口、库名和账号权限。', 0, $exception);
        }
    }

    private function rollbackFreshSchema(PDO $pdo): void
    {
        try {
            $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($tables as $table) {
                    if (is_string($table) && preg_match('/^ffx_[a-z0-9_]+$/', $table) === 1) {
                        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                    }
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        } catch (Throwable $rollbackException) {
            Log::critical('安装失败后数据库清理未完成', ['exception' => $rollbackException->getMessage()]);
        }
    }

    private function executeSchema(PDO $pdo): void
    {
        foreach ($this->sql->fromFile(root_path() . 'database/schema-v2.sql') as $statement) $pdo->exec($statement);
        foreach (glob(root_path() . 'database/migrations/015_*.sql') ?: [] as $migration) {
            foreach ($this->sql->fromFile($migration) as $statement) $pdo->exec($statement);
        }
    }

    /** @param array<string, string> $values */
    private function createAdministrator(PDO $pdo, array $values): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $pdo->prepare('INSERT INTO ffx_admins (username,password_hash,email,status,created_at,updated_at) VALUES (?,?,?,?,?,?)');
        $statement->execute([$values['admin_username'], $this->passwordHasher->hash($values['admin_password']), $values['admin_email'] ?: null, 'active', $now, $now]);
        $adminId = (int) $pdo->lastInsertId();
        $roleId = (int) $pdo->query("SELECT id FROM ffx_roles WHERE role_key = 'super_admin'")->fetchColumn();
        $assignment = $pdo->prepare('INSERT INTO ffx_admin_roles (admin_id, role_id) VALUES (?, ?)');
        $assignment->execute([$adminId, $roleId]);
    }

    /** @param array<string, string> $values */
    private function saveSiteSettings(PDO $pdo, array $values): void
    {
        $statement = $pdo->prepare('INSERT INTO ffx_site_settings (setting_key,setting_value,is_public) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_public=VALUES(is_public)');
        foreach ([['site.name', $values['site_name'], 1], ['admin.base.site_name', $values['site_name'], 0]] as [$key, $setting, $public]) {
            $statement->execute([$key, json_encode($setting, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $public]);
        }
    }

    /** @param array<string, string> $values */
    private function buildEnvironment(array $values): string
    {
        $cacheIsRedis = $values['cache_driver'] === 'redis';
        $lines = [
            'APP_DEBUG' => 'false', 'APP_TRACE' => 'false', 'APP_KEY' => bin2hex(random_bytes(32)), 'APP_URL' => $values['app_url'],
            'SITE_NAME' => $values['site_name'], 'DB_DRIVER' => 'mysql', 'DB_TYPE' => 'mysql', 'DB_HOST' => $values['db_host'],
            'DB_NAME' => $values['db_name'], 'DB_USER' => $values['db_user'], 'DB_PASS' => $values['db_pass'], 'DB_PORT' => $values['db_port'],
            'DB_CHARSET' => 'utf8mb4', 'DB_PREFIX' => 'ffx_', 'CACHE_DRIVER' => $values['cache_driver'], 'REDIS_HOST' => $values['redis_host'],
            'REDIS_PORT' => $values['redis_port'], 'REDIS_PASSWORD' => $values['redis_password'], 'REDIS_DB' => $values['redis_db'], 'REDIS_PREFIX' => 'ff85:',
            'SEARCH_DRIVER' => $values['search_driver'], 'SEARCH_FALLBACK' => 'mysql', 'MEILISEARCH_HOST' => $values['meilisearch_host'],
            'MEILISEARCH_KEY' => $values['meilisearch_key'], 'MEILISEARCH_INDEX' => 'feifeicms_media', 'SESSION_DRIVER' => $cacheIsRedis ? 'cache' : 'file',
            'SESSION_STORE' => $cacheIsRedis ? 'redis' : '', 'SESSION_NAME' => 'FFSESSID', 'COOKIE_SECURE' => str_starts_with(strtolower($values['app_url']), 'https://') ? 'true' : 'false',
            'DEFAULT_LANG' => 'zh-cn', 'INSTALL_ENABLED' => 'false',
        ];
        $output = '';
        foreach ($lines as $key => $value) $output .= $key . ' = ' . $this->quoteEnvironmentValue($value) . PHP_EOL;
        return $output;
    }

    private function quoteEnvironmentValue(string $value): string
    {
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('配置值不能包含换行或空字节。');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function writeLock(string $databaseVersion): void
    {
        $payload = json_encode([
            'installed_at' => gmdate(DATE_ATOM),
            'version' => (string) config('feifei.version_id'),
            'database' => $databaseVersion,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->lockPath(), $payload . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('无法写入安装锁 runtime/install.lock。');
        }
        @chmod($this->lockPath(), 0600);
    }

    private function lockPath(): string
    {
        return runtime_path() . 'install.lock';
    }
}
