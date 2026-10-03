<?php
declare(strict_types=1);
namespace app\listener;

use app\service\SearchIndexer;
use app\service\SiteSettings;

final class SearchOutbox
{
    public function __construct(private readonly SiteSettings $settings, private readonly SearchIndexer $indexer) {}

    public function handle(): void
    {
        if (!request()->isPost() || !str_starts_with(request()->pathinfo(), 'admin/')) return;
        if ($this->settings->string('admin.cache.search_driver', (string) config('feifei.search.driver', 'mysql')) !== 'meilisearch') return;
        try { $this->indexer->flushPending(); } catch (\Throwable $e) {
            trace('搜索增量同步稍后重试：' . $e->getMessage(), 'warning');
        }
    }
}
