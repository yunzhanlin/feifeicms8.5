<?php
declare(strict_types=1);

namespace app\command;

use app\plugin\Legacy43\Legacy43Migrator;
use app\plugin\Legacy43\Legacy43Source;
use app\service\SearchIndexer;
use app\service\SiteSettings;
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
            ->addOption('cursor', null, Option::VALUE_OPTIONAL, '单批迁移起点', '0')
            ->addOption('preflight', null, Option::VALUE_NONE, '输出旧站预检结果')
            ->addOption('once', null, Option::VALUE_NONE, '仅执行指定模块的一批并输出 JSON')
            ->addOption('finish', null, Option::VALUE_NONE, '迁移完成后同步搜索索引')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只验证，不写入');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            if ((bool) $input->getOption('finish')) {
                $settings = $this->app->make(SiteSettings::class);
                $count = 0;
                if ($settings->string('admin.cache.search_driver', (string) config('feifei.search.driver', 'mysql')) === 'meilisearch') {
                    $count = $this->app->make(SearchIndexer::class)->sync();
                }
                $output->writeln('FF43_JSON:' . json_encode(['search_synced' => $count], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                return 0;
            }
            /** @var Legacy43Migrator $migrator */
            $migrator = $this->app->make(Legacy43Migrator::class);
            $source = new Legacy43Source([
                'host' => $input->getOption('host'), 'port' => $input->getOption('port'),
                'database' => $input->getOption('database'), 'username' => $input->getOption('user'),
                'password' => getenv('FF43_DB_PASS') ?: '', 'prefix' => $input->getOption('prefix'),
                'charset' => $input->getOption('charset'),
            ]);
            $modules = array_keys($migrator->modules());
            $selected = trim((string) $input->getOption('module'));
            if ($selected !== '') {
                if (!in_array($selected, $modules, true)) throw new \RuntimeException('未知模块：' . $selected);
                $modules = [$selected];
            }
            $limit = max(10, min(500, (int) $input->getOption('batch')));
            $dryRun = (bool) $input->getOption('dry-run');
            if ((bool) $input->getOption('once')) {
                if ($selected === '') throw new \RuntimeException('单批迁移必须指定模块。');
                $definition = $migrator->modules()[$selected];
                if (!$source->exists($definition['table'])) throw new \RuntimeException('旧表不存在：' . $selected);
                $result = $migrator->migrateBatch($source, $selected, max(0, (int) $input->getOption('cursor')), $limit, $dryRun);
                $output->writeln('FF43_JSON:' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                return $result['errors'] > 0 ? 2 : 0;
            }
            $preflight = $migrator->preflight($source);
            if (!$preflight['compatible']) throw new \RuntimeException('未检测到完整的 4.3 核心表。');
            if ((bool) $input->getOption('preflight')) {
                $output->writeln('FF43_JSON:' . json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                return 0;
            }
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
