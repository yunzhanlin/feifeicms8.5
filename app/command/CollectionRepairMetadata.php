<?php
declare(strict_types=1);

namespace app\command;

use app\service\CollectionMetadataRepair;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class CollectionRepairMetadata extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:collection:repair-metadata')
            ->addOption('apply', null, Option::VALUE_NONE, '备份原值后补全缺失字段；不加此选项只预览')
            ->setDescription('从已保存的采集快照补全主演、导演、关键词和上映日期');
    }

    protected function execute(Input $input, Output $output): int
    {
        $result = $this->app->make(CollectionMetadataRepair::class)->run($this->app, (bool) $input->getOption('apply'));
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    }
}
