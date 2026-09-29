<?php
declare(strict_types=1);

namespace app\command;

use app\service\SearchIndexer;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class SearchSync extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:search:sync')->setDescription('将 ffx_media 全量同步到 Meilisearch');
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var SearchIndexer $indexer */
        $indexer = $this->app->make(SearchIndexer::class);
        $count = $indexer->sync();
        $output->writeln(sprintf('[OK] 已提交 %d 条影片到搜索索引', $count));
        return 0;
    }
}
