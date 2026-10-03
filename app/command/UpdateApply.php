<?php
declare(strict_types=1);
namespace app\command;

use app\service\ProgramUpdater;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

final class UpdateApply extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:update:apply')->addArgument('job', Argument::REQUIRED, '已确认的更新任务 ID')
            ->setDescription('后台确认后执行 GitHub Release 更新');
    }

    protected function execute(Input $input, Output $output): int
    {
        $job = (string) $input->getArgument('job');
        if (!preg_match('/^[a-f0-9]{24}$/', $job)) throw new \RuntimeException('更新任务 ID 无效');
        $this->app->make(ProgramUpdater::class)->run($job);
        $output->writeln('[OK] 程序更新完成');
        return 0;
    }
}
