<?php
declare(strict_types=1);

namespace tests;

use app\service\AdminAuthorization;
use app\service\DatabaseFieldReplacer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseFieldReplacerTest extends TestCase
{
    #[DataProvider('conditions')]
    public function testConditionsUseValidatedIdentifiersAndBoundValues(string $input, string $sql, array $values): void
    {
        $result = (new DatabaseFieldReplacer())->condition($input, [['name' => 'id'], ['name' => 'status'], ['name' => 'title']]);
        self::assertSame(['sql' => $sql, 'values' => $values], $result);
    }

    public static function conditions(): array
    {
        return [
            ['', '', []], ['  ', '', []],
            ['id=888', ' AND (`id` = ?)', ['888']],
            ["id>=100 AND status='published'", ' AND (`id` >= ? AND `status` = ?)', ['100', 'published']],
            ['id < 100 AND id != -1', ' AND (`id` < ? AND `id` != ?)', ['100', '-1']],
            ['id<=3.5', ' AND (`id` <= ?)', ['3.5']],
            ['title IS NULL', ' AND (`title` IS NULL)', []],
            ['title is not null', ' AND (`title` IS NOT NULL)', []],
            ["title='it''s ordinary'", ' AND (`title` = ?)', ["it's ordinary"]],
            ['title="中文"', ' AND (`title` = ?)', ['中文']],
            ["title='; DROP TABLE ffx_media; --'", ' AND (`title` = ?)', ['; DROP TABLE ffx_media; --']],
            ["title=''", ' AND (`title` = ?)', ['']],
        ];
    }

    #[DataProvider('invalidConditions')]
    public function testSqlExpressionsAndUnknownFieldsAreRejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DatabaseFieldReplacer())->condition($input, [['name' => 'id'], ['name' => 'title'], ['name' => 'password_hash']]);
    }

    public static function invalidConditions(): array
    {
        return array_map(static fn (string $value): array => [$value], [
            'id=1 OR 1=1', 'id=1; DROP TABLE ffx_media', 'id=(SELECT 1)', 'SLEEP(5)',
            'unknown=1', 'password_hash=1', 'id IN (1,2)', 'id=1 --', 'id=1 #',
            '`id`=1', 'id=1 AND', "title='unclosed", 'id=1 AND (id=2)', 'id LIKE 1',
            'id=1 UNION SELECT 1', implode(' AND ', array_fill(0, 9, 'id=1')), str_repeat('x', 4097),
        ]);
    }

    public function testDatabaseReplacementKeepsAuthCsrfPreviewAndClassicForm(): void
    {
        self::assertNull(AdminAuthorization::permissionFor('admin.Database'));
        $root = dirname(__DIR__);
        $routes = (string) file_get_contents($root . '/route/app.php');
        foreach (["Route::get('database/replace'", "Route::get('database/replace/fields'", "Route::post('database/replace/preview'", "Route::post('database/replace'"] as $route) self::assertStringContainsString($route, $routes);
        $controller = (string) file_get_contents($root . '/app/controller/admin/Database.php');
        self::assertStringContainsString("$" . "this->guardCsrf();", $controller);
        self::assertStringContainsString("database.replace_preview", $controller);
        self::assertStringContainsString('hash_equals', $controller);
        $view = (string) file_get_contents($root . '/view/admin/database/replace.html');
        foreach (['id="exptable"', 'id="rpfield"', 'id="rpstring"', 'id="tostring"', 'id="condition"', 'data-replace-preview', 'name="confirm" value="REPLACE"'] as $marker) self::assertStringContainsString($marker, $view);
        self::assertStringContainsString('字段内容替换', (string) file_get_contents($root . '/view/admin/layout/header.html'));
    }
}
