<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'feifei:doctor' => app\command\Doctor::class,
        'feifei:schema:install' => app\command\SchemaInstall::class,
        'feifei:schema:upgrade' => app\command\SchemaUpgrade::class,
        'feifei:admin:create' => app\command\AdminCreate::class,
        'feifei:search:sync' => app\command\SearchSync::class,
        'feifei:search:work' => app\command\SearchWork::class,
        'feifei:update:apply' => app\command\UpdateApply::class,
        'feifei:collection:work' => app\command\CollectionWork::class,
        'feifei:collection:schedule' => app\command\CollectionSchedule::class,
        'feifei:collection:repair-metadata' => app\command\CollectionRepairMetadata::class,
        'feifei:legacy43:upgrade' => app\command\Legacy43Upgrade::class,
    ],
];
