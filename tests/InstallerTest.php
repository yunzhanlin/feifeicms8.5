<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testReleaseVersionHasOneCanonicalSource(): void
    {
        $release = require $this->root . '/config/version.php';
        self::assertSame('8.5.261003-beta2', $release['version']);
        self::assertSame('8.5.261003 Beta2', $release['display']);

        $feifei = file_get_contents($this->root . '/config/feifei.php');
        self::assertIsString($feifei);
        self::assertStringContainsString("require __DIR__ . '/version.php'", $feifei);
        self::assertStringNotContainsString("'version' => '8.", $feifei);
    }

    public function testClassicInstallerRoutesAndLockingArePresent(): void
    {
        $routes = file_get_contents($this->root . '/route/app.php');
        $service = file_get_contents($this->root . '/app/service/WebInstaller.php');
        self::assertIsString($routes);
        self::assertIsString($service);
        self::assertStringContainsString("Route::get('install'", $routes);
        self::assertStringContainsString("Route::post('install'", $routes);
        self::assertFileExists($this->root . '/public/install.php');
        self::assertStringContainsString("runtime_path() . 'install.lock'", $service);
        self::assertStringContainsString("目标数据库已存在 ffx_ 数据表", $service);
    }

    public function testInstallerNeverRepublishesSubmittedSecrets(): void
    {
        $controller = file_get_contents($this->root . '/app/controller/Install.php');
        self::assertIsString($controller);
        foreach (['db_pass', 'admin_password', 'admin_password_confirm', 'redis_password', 'meilisearch_key'] as $secret) {
            self::assertStringContainsString("unset(\$values['db_pass']", $controller);
            self::assertStringContainsString($secret, $controller);
        }
    }

    public function testInstallerAndEnvironmentFilesCannotBeCommitted(): void
    {
        $ignore = file_get_contents($this->root . '/.gitignore');
        self::assertIsString($ignore);
        self::assertStringContainsString(".env.integration", $ignore);
        self::assertStringContainsString(".env.installing", $ignore);
    }
}
