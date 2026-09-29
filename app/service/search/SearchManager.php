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
            return $this->meilisearch->search($keyword, $page, $pageSize);
        } catch (Throwable $exception) {
            if ($this->settings->string('admin.cache.search_fallback', (string) config('feifei.search.fallback', 'mysql')) !== 'mysql') {
                throw $exception;
            }
            return $this->mysql->search($keyword, $page, $pageSize);
        }
    }
}
