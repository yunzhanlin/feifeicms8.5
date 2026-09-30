<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SchemaV2Test extends TestCase
{
    private string $schema;

    protected function setUp(): void
    {
        $contents = file_get_contents(dirname(__DIR__) . '/database/schema-v2.sql');
        self::assertIsString($contents);
        $this->schema = $contents;
    }

    public function testDockerBootstrapAndCanonicalSchemaStayIdentical(): void
    {
        self::assertSame(
            $this->schema,
            file_get_contents(dirname(__DIR__) . '/database/schema.sql')
        );
    }

    public function testPlaybackAndDiscoveryDataAreNormalized(): void
    {
        foreach ([
            'ffx_media', 'ffx_media_categories', 'ffx_seasons', 'ffx_play_sources',
            'ffx_episodes', 'ffx_scenarios', 'ffx_media_assets', 'ffx_external_refs', 'ffx_audit_logs',
            'ffx_cron_tasks', 'ffx_danmaku', 'ffx_media_entitlements',
        ] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $this->schema);
        }
        self::assertStringNotContainsString('vod_play', $this->schema);
        self::assertStringNotContainsString('vod_url', $this->schema);
        self::assertStringNotContainsString('CREATE TABLE IF NOT EXISTS ffx_visit_logs', $this->schema);
        self::assertStringContainsString('DROP TABLE IF EXISTS ffx_visit_logs', $this->schema);
    }

    public function testFreshInstallRecordsTheCompleteSchemaBaseline(): void
    {
        self::assertStringContainsString(
            "VALUES (14, 'indexed cached frontend queries')",
            $this->schema
        );
        self::assertStringContainsString("new_data_policy VARCHAR(20) NOT NULL DEFAULT 'published'", $this->schema);
        self::assertStringContainsString("resource_type VARCHAR(20) NOT NULL DEFAULT 'video'", $this->schema);
        self::assertStringContainsString('media_source_id BIGINT UNSIGNED NULL', $this->schema);
        self::assertStringContainsString('UNIQUE KEY uk_ffx_collection_sources_resource_endpoint (resource_type, endpoint(255))', $this->schema);
        self::assertStringContainsString('FOREIGN KEY (media_source_id) REFERENCES ffx_collection_sources(id) ON DELETE SET NULL', $this->schema);
        foreach (['admin_weekday', 'admin_state', 'admin_series', 'admin_inputer', 'idx_ffx_media_updated'] as $filterIndex) {
            self::assertStringContainsString($filterIndex, $this->schema);
        }
    }

    public function testIncrementalUpgradeCommandAndVersionTwelveMigrationAreRegistered(): void
    {
        $console = file_get_contents(dirname(__DIR__) . '/config/console.php');
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/012_media_admin_filter_indexes.sql');
        self::assertIsString($console);
        self::assertIsString($migration);
        self::assertStringContainsString("'feifei:schema:upgrade'", $console);
        self::assertStringContainsString("GET_LOCK('feifeicms_schema_upgrade'", file_get_contents(dirname(__DIR__) . '/app/command/SchemaUpgrade.php'));
        self::assertStringContainsString("VALUES (12, 'indexed media administration filters')", $migration);
    }

    public function testScenarioCollectionSourceMigrationIsRegistered(): void
    {
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/013_scenario_collection_sources.sql');
        self::assertIsString($migration);
        foreach (['resource_type', 'media_source_id', 'uk_ffx_collection_sources_resource_endpoint', 'fk_ffx_collection_sources_media_source', "VALUES (13, 'independent scenario collection sources')"] as $marker) {
            self::assertStringContainsString($marker, $migration);
        }
    }

    public function testFrontendQueryIndexesMigrationIsRegistered(): void
    {
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/014_frontend_query_indexes.sql');
        self::assertIsString($migration);
        foreach (['idx_ffx_categories_frontend', 'idx_ffx_media_frontend_popular', 'idx_ffx_media_frontend_category', "VALUES (14, 'indexed cached frontend queries')"] as $marker) {
            self::assertStringContainsString($marker, $migration);
            self::assertStringContainsString($marker, $this->schema);
        }
    }

    public function testCronTasksSupportIntervalHourlyAndDailySchedules(): void
    {
        self::assertStringContainsString("schedule_type VARCHAR(20) NOT NULL DEFAULT 'daily'", $this->schema);
        self::assertStringContainsString('interval_minutes SMALLINT UNSIGNED NULL', $this->schema);
    }

    public function testCollectedPlaybackTracksItsOwningSource(): void
    {
        self::assertStringContainsString('collection_source_id BIGINT UNSIGNED NULL', $this->schema);
        self::assertStringContainsString('idx_ffx_play_source_collection (collection_source_id, media_id)', $this->schema);
    }

    public function testScenariosAreStronglyRelatedToMedia(): void
    {
        self::assertStringContainsString('UNIQUE KEY uk_ffx_scenarios_media_episode (media_id, episode_no)', $this->schema);
        self::assertStringContainsString('FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE', $this->schema);
        self::assertStringContainsString('douban_id VARCHAR(32)', $this->schema);
        self::assertStringContainsString('imdb_id VARCHAR(32)', $this->schema);
    }

    public function testLegacyIdsAreKeptOnlyInTheOptionalImportMap(): void
    {
        self::assertStringNotContainsString('legacy_id BIGINT UNSIGNED NULL', $this->schema);
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS ffx_legacy_map \([\s\S]*legacy_id BIGINT UNSIGNED NOT NULL/',
            $this->schema
        );
    }
}
