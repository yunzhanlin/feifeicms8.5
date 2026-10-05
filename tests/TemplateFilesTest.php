<?php
declare(strict_types=1);

namespace tests;

use app\service\TemplateFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\exception\HttpException;

final class TemplateFilesTest extends TestCase
{
    private string $root;
    private TemplateFiles $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/feifei-template-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/mxone/common', 0700, true);
        mkdir($this->root . '/admin', 0700);
        file_put_contents($this->root . '/mxone/common/head.html', '<title>测试</title>');
        file_put_contents($this->root . '/admin/login.html', 'private');
        $this->files = new TemplateFiles($this->root);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->root);
    }

    public function testExistingAndNewTemplatesStayInAnExistingTheme(): void
    {
        self::assertSame($this->root . '/mxone/common/head.html', $this->files->file('mxone/common/head.html'));
        self::assertSame($this->root . '/mxone/new/page.html', $this->files->file('mxone/new/page.html', true));
        self::assertSame($this->root, $this->files->directory(''));
    }

    #[DataProvider('invalidPaths')]
    public function testReadAndCreateBothRejectUnsafePaths(string $path): void
    {
        foreach ([false, true] as $create) {
            try {
                $this->files->file($path, $create);
                self::fail('Unsafe path was accepted: ' . $path);
            } catch (HttpException $exception) {
                self::assertContains($exception->getStatusCode(), [403, 422]);
            }
        }
    }

    public static function invalidPaths(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '../public/evil.html', 'mxone/../../public/evil.html', 'mxone/../admin/login.html',
            'mxone/./common/head.html', 'mxone//common/head.html', '/mxone/common/head.html',
            'mxone\\common\\head.html', 'mxone/%2e%2e/evil.html', 'mxone/%252e%252e/evil.html',
            "mxone/evil\0.html", 'admin/login.html', 'install/index.html', 'ADMIN/login.html',
            'mxone/.hidden.html', 'mxone/evil.php', 'mxone/style.css', 'mxone/app.js', 'mxone/data.json',
            'mxone/file.html.php', 'mxone/file.HTML', 'standalone.html',
        ]);
    }

    public function testMissingThemeAndExistingFileCannotBeCreated(): void
    {
        try {
            $this->files->file('missing/new.html', true);
            self::fail('Unknown theme accepted');
        } catch (HttpException $exception) { self::assertSame(404, $exception->getStatusCode()); }
        $this->expectException(HttpException::class);
        $this->files->file('mxone/common/head.html', true);
    }

    public function testSymlinkFilesAndDirectoriesAreRejected(): void
    {
        symlink($this->root . '/admin', $this->root . '/mxone/link');
        symlink($this->root . '/admin/login.html', $this->root . '/mxone/leak.html');
        foreach (['mxone/link/login.html', 'mxone/leak.html', 'mxone/link/new.html'] as $path) {
            try { $this->files->file($path, true); self::fail('Symlink accepted'); }
            catch (HttpException $exception) { self::assertSame(403, $exception->getStatusCode()); }
        }
    }

    public function testProductionTemplateTreeContainsOnlyHtmlTemplates(): void
    {
        $root = dirname(__DIR__) . '/view';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
            self::assertFalse($item->isLink(), $item->getPathname());
            self::assertSame('html', $item->getExtension(), $item->getPathname());
        }
        // Additional user themes are supported; only the shipped system/sample
        // views are mandatory, not an exact three-directory allowlist.
        foreach (['admin', 'install', 'mxone'] as $directory) self::assertDirectoryExists($root . '/' . $directory);
    }
}
