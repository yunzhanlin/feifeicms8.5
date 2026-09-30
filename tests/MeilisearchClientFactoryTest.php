<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MeilisearchClientFactoryTest extends TestCase
{
    public function testSearchClientsUseOneCentralizedEffectiveConfiguration(): void
    {
        $factory = file_get_contents(dirname(__DIR__) . '/app/service/MeilisearchClientFactory.php');
        self::assertIsString($factory);
        self::assertStringContainsString("\$host = trim(\$this->settings->string('admin.cache.search_host', ''));", $factory);
        self::assertStringContainsString("if (\$host === '') {", $factory);
        self::assertStringContainsString("\$host = 'http://' . \$host;", $factory);
        self::assertStringContainsString('new Client($this->host(), $this->key())', $factory);

        foreach ([
            dirname(__DIR__) . '/app/service/SearchIndexer.php',
            dirname(__DIR__) . '/app/service/search/MeilisearchVodSearch.php',
            dirname(__DIR__) . '/app/controller/Health.php',
            dirname(__DIR__) . '/app/controller/admin/System.php',
            dirname(__DIR__) . '/app/controller/admin/Tools.php',
        ] as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertStringContainsString('MeilisearchClientFactory', $source, $file);
            self::assertStringNotContainsString('new MeilisearchClient(', $source, $file);
        }
    }
}
