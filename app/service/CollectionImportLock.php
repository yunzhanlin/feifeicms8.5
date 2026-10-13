<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

/** Cross-source imports serialize on all strong IDs and normalized title. */
final class CollectionImportLock
{
    public static function run(array $identities, callable $operation): mixed
    {
        $connection = (string) config('database.default', 'mysql');
        if (!in_array((string) config('database.connections.' . $connection . '.type', 'mysql'), ['mysql', \app\db\Mysql::class], true)) return $operation();
        $database = (string) config('database.connections.' . $connection . '.database');
        $locks = array_values(array_unique(array_map(static fn (string $id): string => 'ff_import_' . substr(hash('sha256', $database . '/' . $id), 0, 48), $identities)));
        sort($locks, SORT_STRING);
        $acquired = [];
        try {
            foreach ($locks as $lock) {
                if ((int) (Db::query('SELECT GET_LOCK(?, 30) AS acquired', [$lock])[0]['acquired'] ?? 0) !== 1) throw new \RuntimeException('同一影片正在采集，请稍后重试');
                $acquired[] = $lock;
            }
            return $operation();
        } finally {
            foreach (array_reverse($acquired) as $lock) Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
