<?php
declare(strict_types=1);

namespace app\command;

use app\service\SqlStatementStream;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class SchemaInstall extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:schema:install')
            ->setDescription('幂等安装 FeiFeiCMS 新版规范化数据结构');
    }

    protected function execute(Input $input, Output $output): int
    {
        $version = (string) (Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
        if (!preg_match('/^(8\.[0-9]+\.[0-9]+)/', $version, $matches)) {
            $output->writeln('[FAIL] 新结构要求 MySQL 8.x，当前版本：' . ($version ?: '未知'));
            return 1;
        }

        $path = root_path() . 'database/schema-v2.sql';
        if (!is_file($path)) {
            $output->writeln('[FAIL] 无法读取结构文件：' . $path);
            return 1;
        }

        $executed = 0;
        foreach ((new SqlStatementStream())->fromFile($path) as $statement) {
            Db::execute($statement);
            $executed++;
        }

        $tableCount = (int) (Db::query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'")[0]['total'] ?? 0);
        $output->writeln(sprintf('[OK] MySQL %s，执行 %d 条语句，当前 %d 张 ffx_ 表', $matches[1], $executed, $tableCount));
        return 0;
    }
}
