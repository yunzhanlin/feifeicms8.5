<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Legacy43UpgradePluginTest extends TestCase
{
    public function testPluginShipsManifestAdminRoutesViewAndCliCommand(): void
    {
        $root = dirname(__DIR__);
        $manifest = require $root . '/app/plugin/Legacy43/manifest.php';
        self::assertSame('feifeicms-legacy43-upgrade', $manifest['id']);
        self::assertSame('FeiFeiCMS 8.5', $manifest['target']);

        $routes = (string) file_get_contents($root . '/route/app.php');
        foreach (['tools/legacy-upgrade', 'tools/legacy-upgrade/preflight', 'tools/legacy-upgrade/batch', 'tools/legacy-upgrade/finish'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        $console = (string) file_get_contents($root . '/config/console.php');
        self::assertStringContainsString('feifei:legacy43:upgrade', $console);
        self::assertFileExists($root . '/view/admin/tools/legacy_upgrade.html');
    }

    public function testMigrationCoversLegacyBusinessTablesAndNeverWritesSource(): void
    {
        $root = dirname(__DIR__);
        $migrator = (string) file_get_contents($root . '/app/plugin/Legacy43/Legacy43Migrator.php');
        foreach (['list', 'user', 'vod', 'cj', 'news', 'person', 'special', 'tag', 'forum', 'nav', 'slide', 'link', 'ads', 'player', 'orders', 'card', 'record', 'score'] as $table) {
            self::assertStringContainsString("'table' => '" . $table . "'", $migrator);
        }
        foreach (['ffx_categories', 'ffx_media', 'ffx_play_sources', 'ffx_episodes', 'ffx_scenarios', 'ffx_users', 'ffx_collection_sources', 'ffx_articles', 'ffx_people', 'ffx_topics', 'ffx_comments', 'ffx_legacy_map'] as $table) {
            self::assertStringContainsString($table, $migrator);
        }
        self::assertStringContainsString("'sources' => ['label' => '采集源'", $migrator);
        self::assertStringContainsString("'status' => 'disabled'", $migrator);
        self::assertStringContainsString('$this->saveScenarios(', $migrator);
        self::assertStringContainsString('$this->normalizer->normalizeArea(', $migrator);
        self::assertStringContainsString('$this->normalizer->normalizeLanguage(', $migrator);
        self::assertStringContainsString("Db::table('ffx_favorites')->where('user_id', \$userId)->where('media_id', \$mediaId)->update(\$favorite)", $migrator);
        $source = (string) file_get_contents($root . '/app/plugin/Legacy43/Legacy43Source.php');
        self::assertStringNotContainsString('INSERT ', $source);
        self::assertStringNotContainsString('UPDATE ', $source);
        self::assertStringNotContainsString('DELETE ', $source);
        self::assertStringContainsString('ATTR_MULTI_STATEMENTS', $source);
        self::assertStringContainsString("defined('Pdo\\\\Mysql::ATTR_MULTI_STATEMENTS')", $source);
    }

    public function testFrontendLoginUpgradesLegacyMd5PasswordAfterSuccessfulVerification(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controller/User.php');
        self::assertStringContainsString('$this->passwordHasher->verify', $controller);
        self::assertStringContainsString('$this->passwordHasher->needsRehash', $controller);
        self::assertStringContainsString('$loginUpdate[\'password_hash\']', $controller);
    }

    public function testOldSiteEntrypointUsesPhp56SyntaxAndBoundedCliBatches(): void
    {
        $root = dirname(__DIR__);
        $path = $root . '/plugins/legacy43/ff43-upgrade.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        $parser = (new \PhpParser\ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromString('5.6'));
        self::assertIsArray($parser->parse($source));
        foreach (['ff43up_admin', "hash_equals(", "'FF43_DB_PASS'", "'--once'", "'--preflight'", "'--finish'", 'runtime/install.lock', "'ff43up_csrf'"] as $marker) {
            self::assertTrue(str_contains($source, $marker), $marker);
        }
        self::assertStringNotContainsString("'--password=", $source);
        $command = (string) file_get_contents($root . '/app/command/Legacy43Upgrade.php');
        foreach (["->addOption('once'", "->addOption('preflight'", "->addOption('finish'", "->addOption('cursor'", 'FF43_JSON:'] as $marker) {
            self::assertStringContainsString($marker, $command);
        }
    }
}
