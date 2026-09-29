<?php
declare(strict_types=1);

namespace app\command;

use app\service\CronSchedule;
use DateTimeImmutable;
use DateTimeZone;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class CollectionSchedule extends Command
{
    protected function configure(): void { $this->setName('feifei:collection:schedule')->setDescription('把到期的定时采集计划加入队列'); }
    protected function execute(Input $input, Output $output): int
    {
        $now = gmdate('Y-m-d H:i:s'); $count = 0;
        $schedule = new CronSchedule();
        $tasks = Db::table('ffx_cron_tasks')->where('status', 'enabled')->whereNotNull('source_id')->where('next_run_at', '<=', $now)->order('id')->select()->toArray();
        foreach ($tasks as $task) {
            Db::transaction(function () use ($task, $now, $schedule, &$count): void {
                $scheduledAt = new DateTimeImmutable((string) $task['next_run_at'], new DateTimeZone('UTC'));
                $key = 'cron-' . $task['id'] . '-' . $scheduledAt->format('YmdHi');
                if (Db::table('ffx_collection_jobs')->where('idempotency_key', $key)->count() < 1) {
                    Db::table('ffx_collection_jobs')->insert(['source_id' => $task['source_id'], 'idempotency_key' => $key, 'mode' => 'scheduled', 'state' => 'queued', 'cursor_value' => $task['payload'], 'created_at' => $now]); $count++;
                }
                $nowAt = new DateTimeImmutable($now, new DateTimeZone('UTC'));
                $next = $schedule->nextRun($task, $nowAt, $scheduledAt)->format('Y-m-d H:i:s');
                Db::table('ffx_cron_tasks')->where('id', $task['id'])->update(['last_run_at' => $now, 'next_run_at' => $next, 'updated_at' => $now]);
            });
        }
        $output->writeln('[OK] queued=' . $count); return 0;
    }
}
