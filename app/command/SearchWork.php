<?php
declare(strict_types=1);
namespace app\command;

use app\service\SearchIndexer;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class SearchWork extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:search:work')->setDescription('处理一批搜索增量队列（建议每分钟运行，失败保留队列）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $indexer = $this->app->make(SearchIndexer::class);
        $count = $indexer->flushPending(500, 30000);
        $output->writeln(sprintf('[OK] 同步 %d 条变更，剩余 %d 条', $count, $indexer->pendingCount()));
        return 0;
    }
}
