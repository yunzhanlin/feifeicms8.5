<?php
declare(strict_types=1);

use app\service\LegacyRewriteRules;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!in_array('--force', $argv, true)) {
    fwrite(STDERR, "为避免覆盖站长自定义规则，请显式传入 --force。\n");
    exit(2);
}

$app = new App();
$app->initialize();
$catalogue = config('admin_settings');
$definition = (string) ($catalogue['rewrite']['groups']['URL优化、伪静态配置']['rewrite_route']['default'] ?? '');
$compiled = $app->make(LegacyRewriteRules::class)->compiled($definition);
$values = [
    'admin.rewrite.url_rewrite' => '1',
    'admin.rewrite.url_router_on' => '1',
    'admin.rewrite.url_html_suffix' => '.html',
    'admin.rewrite.rewrite_route' => $definition,
    'admin.rewrite.url_rewrite_rules' => $compiled['rewrite_rules'],
    'admin.rewrite.url_route_rules' => $compiled['route_rules'],
];
$now = gmdate('Y-m-d H:i:s');
Db::transaction(static function () use ($values, $now): void {
    foreach ($values as $key => $value) {
        $payload = ['setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_public' => 0, 'updated_at' => $now];
        $exists = Db::table('ffx_site_settings')->where('setting_key', $key)->count() > 0;
        $exists ? Db::table('ffx_site_settings')->where('setting_key', $key)->update($payload) : Db::table('ffx_site_settings')->insert(['setting_key' => $key] + $payload);
    }
});
echo json_encode(['rules' => count($compiled['route_rules']), 'suffix' => '.html', 'router' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
