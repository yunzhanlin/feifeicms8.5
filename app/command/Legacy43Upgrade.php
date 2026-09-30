<?php
declare(strict_types=1);

namespace app\command;

use app\plugin\Legacy43\Legacy43Migrator;
use app\plugin\Legacy43\Legacy43Source;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

final class Legacy43Upgrade extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:legacy43:upgrade')->setDescription('分批迁移 FeiFeiCMS 4.3 数据到 8.5')
            ->addOption('host', null, Option::VALUE_OPTIONAL, '旧库主机', '127.0.0.1')
            ->addOption('port', null, Option::VALUE_OPTIONAL, '旧库端口', '3306')
            ->addOption('database', null, Option::VALUE_REQUIRED, '旧数据库名')
            ->addOption('user', null, Option::VALUE_REQUIRED, '旧库用户名')
            ->addOption('prefix', null, Option::VALUE_OPTIONAL, '旧表前缀', 'ff_')
            ->addOption('charset', null, Option::VALUE_OPTIONAL, '旧库字符集', 'utf8mb4')
            ->addOption('module', null, Option::VALUE_OPTIONAL, '仅迁移指定模块；省略则全部')
            ->addOption('batch', null, Option::VALUE_OPTIONAL, '每批条数 10-500', '100')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只验证，不写入');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            /** @var Legacy43Migrator $migrator */
            $migrator = $this->app->make(Legacy43Migrator::class);
            $source = new Legacy43Source([
                'host' => $input->getOption('host'), 'port' => $input->getOption('port'),
                'database' => $input->getOption('database'), 'username' => $input->getOption('user'),
                'password' => getenv('FF43_DB_PASS') ?: '', 'prefix' => $input->getOption('prefix'),
                'charset' => $input->getOption('charset'),
            ]);
            $preflight = $migrator->preflight($source);
            if (!$preflight['compatible']) throw new \RuntimeException('未检测到完整的 4.3 核心表。');
            $modules = array_keys($migrator->modules());
            $selected = trim((string) $input->getOption('module'));
            if ($selected !== '') {
                if (!in_array($selected, $modules, true)) throw new \RuntimeException('未知模块：' . $selected);
                $modules = [$selected];
            }
            $limit = max(10, min(500, (int) $input->getOption('batch')));
            $dryRun = (bool) $input->getOption('dry-run');
            $errors = 0;
            foreach ($modules as $module) {
                if (!($preflight['modules'][$module]['exists'] ?? false)) { $output->writeln('[SKIP] ' . $module . ' 旧表不存在'); continue; }
                $cursor = 0;
                do {
                    $result = $migrator->migrateBatch($source, $module, $cursor, $limit, $dryRun);
                    $cursor = $result['cursor']; $errors += $result['errors'];
                    $output->writeln(sprintf('[%s] %s cursor=%d processed=%d created=%d updated=%d skipped=%d errors=%d', $result['done'] ? 'OK' : 'RUN', $result['label'], $cursor, $result['processed'], $result['created'], $result['updated'], $result['skipped'], $result['errors']));
                    foreach ($result['messages'] as $message) $output->writeln('  ' . $message);
                } while (!$result['done']);
            }
            $output->writeln($dryRun ? '[OK] 只读验证完成，未写入数据。' : '[OK] 4.3 数据升级完成。');
            return $errors > 0 ? 2 : 0;
        } catch (Throwable $exception) {
            $output->writeln('[FAIL] ' . $exception->getMessage());
            return 1;
        }
    }
}
