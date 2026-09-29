<?php
declare(strict_types=1);

namespace app\command;

use Meilisearch\Client;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use think\facade\Db;
use Throwable;

final class Doctor extends Command
{
    private const TABLES = [
        'ffx_schema_versions', 'ffx_site_settings', 'ffx_categories', 'ffx_media',
        'ffx_media_categories', 'ffx_seasons', 'ffx_play_sources', 'ffx_episodes', 'ffx_scenarios',
        'ffx_media_assets', 'ffx_people', 'ffx_media_people',
        'ffx_articles', 'ffx_topics', 'ffx_topic_media', 'ffx_tags', 'ffx_media_tags',
        'ffx_article_tags', 'ffx_users', 'ffx_comments', 'ffx_watch_history',
        'ffx_favorites', 'ffx_media_entitlements', 'ffx_ratings', 'ffx_danmaku', 'ffx_admins', 'ffx_roles', 'ffx_permissions',
        'ffx_admin_roles', 'ffx_role_permissions', 'ffx_orders', 'ffx_cards',
        'ffx_collection_sources', 'ffx_external_refs', 'ffx_collection_jobs', 'ffx_cron_tasks', 'ffx_players', 'ffx_navigation',
        'ffx_slides', 'ffx_links', 'ffx_ads', 'ffx_jobs', 'ffx_audit_logs',
        'ffx_search_state', 'ffx_legacy_map',
    ];

    protected function configure(): void
    {
        $this->setName('feifei:doctor')->setDescription('检查 FeiFeiCMS 数据库、缓存与搜索运行条件');
    }

    protected function execute(Input $input, Output $output): int
    {
        $failed = false;
        try {
            $rows = Db::query('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()');
            $tables = [];
            foreach ($rows as $row) {
                $tables[(string) $row['name']] = true;
            }
            foreach (self::TABLES as $name) {
                $ok = isset($tables[$name]);
                $failed = $failed || !$ok;
                $output->writeln(sprintf('%s database %s', $ok ? '[OK]' : '[FAIL]', $name));
            }
        } catch (Throwable $exception) {
            $failed = true;
            $output->writeln('[FAIL] database ' . $exception->getMessage());
        }

        try {
            $cacheKey = 'doctor:' . bin2hex(random_bytes(6));
            $marker = 'ok:' . bin2hex(random_bytes(6));
            $written = Cache::set($cacheKey, $marker, 10);
            $read = Cache::get($cacheKey, '__cache_miss__');
            Cache::delete($cacheKey);
            $ok = $written && is_string($read) && hash_equals($marker, $read);
            $failed = $failed || !$ok;
            $driver = (string) config('cache.default', 'unknown');
            $output->writeln($ok
                ? '[OK] cache ' . $driver
                : sprintf('[FAIL] cache %s read/write mismatch (received %s)', $driver, get_debug_type($read)));
        } catch (Throwable $exception) {
            $failed = true;
            $output->writeln('[FAIL] cache ' . $exception->getMessage());
        }

        $searchDriver = (string) config('feifei.search.driver', 'mysql');
        if ($searchDriver !== 'meilisearch') {
            $output->writeln('[OK] search ' . $searchDriver);
        } else {
            try {
                $settings = config('feifei.search.meilisearch');
                $health = (new Client((string) $settings['host'], (string) $settings['key']))->health();
                $output->writeln(($health['status'] ?? null) === 'available' ? '[OK] search meilisearch' : '[WARN] search unavailable');
            } catch (Throwable $exception) {
                $output->writeln('[WARN] search unavailable; MySQL fallback remains active: ' . $exception->getMessage());
            }
        }

        return $failed ? 1 : 0;
    }
}
