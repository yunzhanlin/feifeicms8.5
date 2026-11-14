<?php
declare(strict_types=1);

namespace app\command;

use app\service\ActorProfileCompletion;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class ActorProfileComplete extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:people:complete-profiles')
            ->setDescription('从已绑定的 TVZN 或 TMDB 人物 ID 补全明星的空白资料')
            ->addOption('id', null, Option::VALUE_REQUIRED, '只处理指定明星 ID')
            ->addOption('after', null, Option::VALUE_REQUIRED, '批量扫描时从该 ID 之后继续', '0')
            ->addOption('limit', null, Option::VALUE_REQUIRED, '每批最多处理人数', '30');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = $this->app->make(ActorProfileCompletion::class);
        $id = max(0, (int) $input->getOption('id'));
        $cursor = max(0, (int) $input->getOption('after'));
        $ids = $id > 0 ? [$id] : $service->candidates($cursor, max(1, min(100, (int) $input->getOption('limit'))));
        $updated = $failed = 0;
        foreach ($ids as $personId) {
            $cursor = $personId;
            try {
                $fields = $service->complete($personId);
                if ($fields === []) continue;
                ++$updated;
                $output->writeln('#' . $personId . ' ' . implode(', ', $fields));
            } catch (\Throwable) {
                ++$failed;
                $output->writeln('[FAIL] #' . $personId . ' 明星资料补全失败');
            }
        }
        $output->writeln(sprintf('处理 %d 人，补全 %d 人，失败 %d 人，最后处理 ID %d', count($ids), $updated, $failed, $cursor));
        return $failed === 0 ? 0 : 1;
    }
}
