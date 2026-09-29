<?php
declare(strict_types=1);

namespace app\command;

use app\service\CollectionRunner;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

final class CollectionWork extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:collection:work')
            ->addOption('max', null, Option::VALUE_OPTIONAL, '本次最多执行批次', '20')
            ->setDescription('执行排队中的采集任务，支持采集全部的断点续页');
    }

    protected function execute(Input $input, Output $output): int
    {
        $max = max(1, min(1000, (int) $input->getOption('max')));
        /** @var CollectionRunner $runner */
        $runner = $this->app->make(CollectionRunner::class);
        $executed = 0;
        while ($executed < $max) {
            $job = Db::table('ffx_collection_jobs')->where('state', 'queued')->order('id')->find();
            if ($job === null) break;
            try {
                $result = $runner->run((int) $job['id']);
                $output->writeln(sprintf('[OK] #%d processed=%d next=%d', $job['id'], $result['processed'], $result['next_page'] ?? 0));
            } catch (Throwable $exception) {
                $output->writeln(sprintf('[FAIL] #%d %s', $job['id'], $exception->getMessage()));
            }
            $executed++;
        }
        $output->writeln('[DONE] batches=' . $executed);
        return 0;
    }
}
