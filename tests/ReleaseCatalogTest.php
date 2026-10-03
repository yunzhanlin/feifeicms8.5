<?php
declare(strict_types=1);

use app\service\ProgramUpdater;
use app\service\ReleaseCatalog;
use PHPUnit\Framework\TestCase;

final class ReleaseCatalogTest extends TestCase
{
    public function testPrereleaseFeedUsesVersionOrderAndValidatesAssetTargets(): void
    {
        $feed = <<<'XML'
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry><id>tag:github.com,2008:Repository/1/v8.5.260930-beta1</id><title>Beta 1</title></entry>
  <entry><id>tag:github.com,2008:Repository/1/v8.5.261003-beta2</id><title>Beta 2</title></entry>
  <entry><id>tag:github.com,2008:Repository/1/v8.5.261003-beta2.1</id><title>Beta 2.1</title></entry>
</feed>
XML;
        $latest = ReleaseCatalog::parseFeed($feed, '8.5.260930-beta1');
        self::assertSame('v8.5.261003-beta2.1', $latest['tag']);
        self::assertTrue($latest['available']);
        self::assertFalse(ReleaseCatalog::parseFeed($feed, '8.5.261003-beta2.1')['available']);
        self::assertStringEndsWith('/v8.5.261003-beta2.1/feifeicms-update.zip', ReleaseCatalog::assetUrl($latest['tag'], ReleaseCatalog::PACKAGE));
        $this->expectException(InvalidArgumentException::class);
        ReleaseCatalog::assetUrl('../evil', ReleaseCatalog::PACKAGE);
    }

    public function testUpdaterRejectsArchivePathTraversal(): void
    {
        $directory = sys_get_temp_dir() . '/ff-update-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $archive = $directory . '/invalid.zip';
        $stage = $directory . '/stage';
        mkdir($stage, 0700);
        try {
            $files = [
                '../outside.php' => str_repeat('a', 64),
                'config/version.php' => str_repeat('b', 64),
                'vendor/autoload.php' => str_repeat('c', 64),
                'app/service/ProgramUpdater.php' => str_repeat('d', 64),
            ];
            for ($i = 0; $i < 6; $i++) $files['app/example' . $i . '.php'] = str_repeat('e', 64);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive, ZipArchive::CREATE));
            $zip->addFromString('manifest.json', json_encode(['format' => 1, 'version' => '8.5.261003-beta2.1', 'files' => $files], JSON_THROW_ON_ERROR));
            $zip->addFromString('../outside.php', '<?php');
            $zip->close();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('非法文件');
            (new ProgramUpdater())->unpack($archive, $stage, '8.5.261003-beta2.1');
        } finally {
            unlink($archive);
            rmdir($stage);
            rmdir($directory);
        }
    }
}
