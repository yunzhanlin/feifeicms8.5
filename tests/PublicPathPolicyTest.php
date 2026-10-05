<?php
declare(strict_types=1);

namespace tests;

use app\service\PublicPathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicPathPolicyTest extends TestCase
{
    #[DataProvider('privatePaths')]
    public function testPrivateSourcesCannotBeRequested(string $path): void
    {
        self::assertTrue(PublicPathPolicy::isPrivate($path), $path);
    }

    public static function privatePaths(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/view/mxone/index/index.html', '/VIEW/mxone/index.html', '/view.zip', '/Tpl/default/index.html',
            '/templates/theme.css', '/public/view/mxone/index.html', '/index.php/view/mxone/index.html',
            '/api.php/public/view/mxone/index.html', '/%76iew/mxone/index.html', '/%2576iew/mxone/index.html',
            '/view%2fmxone/index.html', '/view%252fmxone/index.html', '/view\\mxone\\index.html',
            '/.env', '/.git/config', '/composer.lock', '/runtime/backups/backup.sql', '/app/common.php',
            '/vendor/autoload.php', '/database/schema.sql', '/docs/模板标签.md', '/legacy/Tpl/index.html',
            '/uploads/file.php', '/uploads/file.php/foo', '/uploads/file.phtml', '/static/evil.php5',
            '/new-entry.php', '/phpinfo.php', '/router.php', '/../view/test.html', '/%2e%2e/view/test.html',
            '/qa/template-manager-comparison.html', "/view/file\0.html",
        ]);
    }

    #[DataProvider('publicPaths')]
    public function testPublicPagesAndStaticAssetsRemainAvailable(string $path): void
    {
        self::assertFalse(PublicPathPolicy::isPrivate($path), $path);
    }

    public static function publicPaths(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/', '/index.php', '/admin.php', '/install.php', '/api.php', '/admin/tools/templates',
            '/admin/tools/templates?file=mxone/index/index.html', '/index.php/admin/tools/templates',
            '/public/static/admin.css', '/static/admin.css', '/mxstatic/js/app.js', '/uploads/photo.jpg',
            '/generated/index.html', '/features', '/play/16/1/1.html', '/.well-known/acme-challenge/key',
        ]);
    }
}
