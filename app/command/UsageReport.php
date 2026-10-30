<?php
declare(strict_types=1);
namespace app\command;

use app\service\UsageReporting;
use think\console\{Command, Input, Output};

final class UsageReport extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:usage:report')->setDescription('每天最多一次匿名程序版本统计；USAGE_REPORTING=false 可关闭');
    }

    protected function execute(Input $input, Output $output): int
    {
        $result = $this->app->make(UsageReporting::class)->report('cron');
        $output->writeln('[usage] ' . $result['status']);
        return in_array($result['status'], ['failed', 'unavailable', 'invalid_endpoint'], true) ? 1 : 0;
    }
}
