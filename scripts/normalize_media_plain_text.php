<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$apply = in_array('--apply', $argv, true);
$changed = 0;
$rows = 0;

$app = new App();
$app->initialize();

$normalize = static function (?string $value): string {
    $value = trim((string) $value);
    for ($pass = 0; $pass < 8; $pass++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }
    return trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
};

Db::table('ffx_media')->whereNull('deleted_at')->field('id,title,original_title,subtitle')->order('id')->chunk(200, function ($items) use (&$changed, &$rows, $normalize, $apply): void {
    foreach ($items as $item) {
        $rows++;
        $data = [];
        foreach (['title', 'original_title', 'subtitle'] as $field) {
            $normalized = $normalize((string) ($item[$field] ?? ''));
            if ($normalized !== (string) ($item[$field] ?? '')) $data[$field] = $normalized;
        }
        if ($data === []) continue;
        $changed++;
        if ($apply) Db::table('ffx_media')->where('id', (int) $item['id'])->update($data + ['updated_at' => gmdate('Y-m-d H:i:s')]);
    }
});

echo json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'rows' => $rows, 'changed' => $changed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
