<?php
declare(strict_types=1);
namespace app\command;

use app\service\{ProgramUpdater, UpdateMaintenance};
use think\console\{Command, Input, Output};
use think\facade\Db;

/** Explicit operator recovery after checking files and completing schema repair. */
final class UpdateResume extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:update:resume')->setDescription('人工修复失败更新并完成结构升级后解除维护');
    }

    protected function execute(Input $input, Output $output): int
    {
        $updater = new ProgramUpdater();
        $lock = fopen($updater->directory() . '/update.lock', 'c');
        if ($lock === false) throw new \RuntimeException('无法打开更新锁');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new \RuntimeException('更新进程仍在运行，不能解除维护'); }
        try {
            $job = $updater->state();
            if (in_array($job['status'] ?? '', ['queued', 'running'], true)) throw new \RuntimeException('更新任务尚未结束');
            $latest = 0;
            foreach (glob(root_path() . 'database/migrations/[0-9][0-9][0-9]_*.sql') ?: [] as $file) $latest = max($latest, (int) substr(basename($file), 0, 3));
            if ((int) Db::table('ffx_schema_versions')->max('version') < $latest) throw new \RuntimeException('请先执行 feifei:schema:upgrade 完成数据库修复');
            $path = root_path() . 'runtime/updates/maintenance.lock';
            $lease = fopen($path, 'c');
            if ($lease === false) throw new \RuntimeException('无法打开维护锁');
            if (!flock($lease, LOCK_EX | LOCK_NB)) { fclose($lease); throw new \RuntimeException('请求仍在运行，不能解除维护'); }
            UpdateMaintenance::finish(root_path(), $lease, true);
            $output->writeln('[OK] 维护已解除，请验证前后台功能');
            return 0;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
