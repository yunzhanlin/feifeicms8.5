<?php
declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;

final class ReleaseContentsTest extends TestCase
{
    public function testOnlyDefaultAndSystemTemplatesEnterTheUpdatePackage(): void
    {
        $builder = (string) file_get_contents(dirname(__DIR__) . '/scripts/build-update-package.php');
        preg_match('/\$roots\s*=\s*\[(.*?)\];/s', $builder, $match);
        self::assertArrayHasKey(1, $match);
        foreach (['view/admin', 'view/install', 'view/mxone'] as $theme) self::assertStringContainsString("'" . $theme . "'", $match[1]);
        foreach (['view', 'legacy', 'runtime', 'public/uploads', 'public/qa', 'tests', 'view/qinxin', 'view/qingxin'] as $private) self::assertStringNotContainsString("'" . $private . "'", $match[1]);
        self::assertStringNotContainsString("'.env'", $builder);
    }

    public function testBuildExcludesLocalThemesAndPrivateSiteFiles(): void
    {
        if (!class_exists(\ZipArchive::class)) self::markTestSkipped('ZipArchive is required');
        $root = sys_get_temp_dir() . '/feifei-package-' . bin2hex(random_bytes(8));
        mkdir($root . '/scripts', 0700, true);
        $files = [
            'app/service/ReleaseCatalog.php', 'vendor/autoload.php',
            'view/admin/index.html', 'view/install/index.html', 'view/mxone/index.html',
            'view/qinxin/index.html', 'view/qingxin/index.html',
            'public/static/admin.css', 'public/static/qinxin/style.css',
            'public/static/Qingxin/logo.svg', 'public/static/qinxin-extra.js',
            'public/uploads/private.txt', 'runtime/private.txt', 'legacy/index.php',
            'tests/fixture.sql', '.env',
        ];
        $zip = new \ZipArchive();
        try {
            copy(dirname(__DIR__) . '/scripts/build-update-package.php', $root . '/scripts/build-update-package.php');
            mkdir($root . '/config', 0700, true);
            copy(dirname(__DIR__) . '/config/version.php', $root . '/config/version.php');
            foreach ($files as $file) {
                if (!is_dir(dirname($root . '/' . $file))) mkdir(dirname($root . '/' . $file), 0700, true);
                file_put_contents($root . '/' . $file, 'package fixture');
            }
            $release = require $root . '/config/version.php';
            $process = proc_open([PHP_BINARY, $root . '/scripts/build-update-package.php', 'v' . $release['version'], $root . '/program.zip'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
            self::assertTrue($zip->open($root . '/program.zip'));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach (['view/admin/index.html', 'view/install/index.html', 'view/mxone/index.html', 'public/static/admin.css'] as $required) self::assertArrayHasKey($required, $manifest['files']);
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                self::assertDoesNotMatchRegularExpression('~qinxin|qingxin|^(?:runtime|tests|legacy|public/uploads)/|^\\.env$~i', $name);
                if ($name !== 'manifest.json') self::assertSame($manifest['files'][$name], hash('sha256', $zip->getFromIndex($index)));
            }
        } finally {
            $zip->close();
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($root);
        }
    }
}
