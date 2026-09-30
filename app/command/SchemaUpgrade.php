<?php
declare(strict_types=1);

namespace app\command;

use app\service\SqlStatementStream;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class SchemaUpgrade extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:schema:upgrade')
            ->setDescription('按版本安全升级已存在的 FeiFeiCMS 数据库结构');
    }

    protected function execute(Input $input, Output $output): int
    {
        $version = (string) (Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
        if (!preg_match('/^8\./', $version)) {
            $output->writeln('[FAIL] 新结构要求 MySQL 8.x，当前版本：' . ($version ?: '未知'));
            return 1;
        }
        $locked = (int) (Db::query("SELECT GET_LOCK('feifeicms_schema_upgrade', 0) AS acquired")[0]['acquired'] ?? 0) === 1;
        if (!$locked) {
            $output->writeln('[FAIL] 另一个数据库升级正在执行');
            return 1;
        }
        try {
            $exists = (int) (Db::query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ffx_schema_versions'")[0]['total'] ?? 0);
            if ($exists !== 1) {
                $output->writeln('[FAIL] 未找到 ffx_schema_versions，请先执行 feifei:schema:install');
                return 1;
            }
            $current = (int) Db::table('ffx_schema_versions')->max('version');
            $migrations = [];
            foreach (glob(root_path() . 'database/migrations/[0-9][0-9][0-9]_*.sql') ?: [] as $path) {
                if (preg_match('/\/(\d{3})_[^\/]+\.sql$/', $path, $matches) !== 1) continue;
                $migrationVersion = (int) $matches[1];
                if ($migrationVersion > max(1, $current)) $migrations[$migrationVersion] = $path;
            }
            ksort($migrations, SORT_NUMERIC);
            if ($migrations === []) {
                $output->writeln('[OK] 数据库已是最新结构，当前版本 ' . $current);
                return 0;
            }
            foreach ($migrations as $migrationVersion => $path) {
                $count = 0;
                foreach ((new SqlStatementStream())->fromFile($path) as $statement) {
                    Db::execute($statement);
                    $count++;
                }
                $recorded = (int) Db::table('ffx_schema_versions')->where('version', $migrationVersion)->count();
                if ($recorded !== 1) throw new \RuntimeException('迁移未写入版本记录：' . basename($path));
                $output->writeln(sprintf('[OK] %s，%d 条 SQL', basename($path), $count));
            }
            $latest = (int) Db::table('ffx_schema_versions')->max('version');
            $output->writeln('[OK] 数据库结构已升级至版本 ' . $latest);
            return 0;
        } finally {
            Db::query("SELECT RELEASE_LOCK('feifeicms_schema_upgrade')");
        }
    }
}
