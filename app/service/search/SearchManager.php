<?php
declare(strict_types=1);

namespace app\service\search;

use app\service\SiteSettings;
use Throwable;

final class SearchManager
{
    public function __construct(
        private readonly MySqlVodSearch $mysql,
        private readonly MeilisearchVodSearch $meilisearch,
        private readonly SiteSettings $settings,
    ) {
    }

    public function search(string $keyword, int $page, int $pageSize): SearchResult
    {
        $driver = $this->settings->string('admin.cache.search_driver', (string) config('feifei.search.driver', 'mysql'));
        if ($driver !== 'meilisearch') {
            return $this->mysql->search($keyword, $page, $pageSize);
        }

        try {
            $result = $this->meilisearch->search($keyword, $page, $pageSize);
            // A healthy but stale/empty external index must not hide records that
            // are already published in MySQL. This also keeps search usable before
            // the first index rebuild on a newly installed or upgraded site.
            if ($result->total > 0 || $this->settings->string('admin.cache.search_fallback', (string) config('feifei.search.fallback', 'mysql')) !== 'mysql') {
                return $result;
            }
            $fallback = $this->mysql->search($keyword, $page, $pageSize);
            return $fallback->total > 0 ? $fallback : $result;
        } catch (Throwable $exception) {
            if ($this->settings->string('admin.cache.search_fallback', (string) config('feifei.search.fallback', 'mysql')) !== 'mysql') {
                throw $exception;
            }
            return $this->mysql->search($keyword, $page, $pageSize);
        }
    }
}
