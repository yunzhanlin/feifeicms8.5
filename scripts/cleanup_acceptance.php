<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['marker:', 'media:', 'category:', 'article:', 'topic:', 'person:', 'user:', 'card:', 'upload:']);
$marker = (string) ($options['marker'] ?? '');
if (!preg_match('/^ACCEPTANCE-[0-9]+$/', $marker)) {
    fwrite(STDERR, "拒绝清理：marker 必须是 ACCEPTANCE-时间戳。\n");
    exit(2);
}

$app = new App();
$app->initialize();

$targets = [
    'ffx_media' => (int) ($options['media'] ?? 0),
    'ffx_categories' => (int) ($options['category'] ?? 0),
    'ffx_articles' => (int) ($options['article'] ?? 0),
    'ffx_topics' => (int) ($options['topic'] ?? 0),
    'ffx_people' => (int) ($options['person'] ?? 0),
    'ffx_users' => (int) ($options['user'] ?? 0),
    'ffx_cards' => (int) ($options['card'] ?? 0),
];

Db::transaction(static function () use ($targets, $marker): void {
    foreach ($targets as $table => $id) {
        if ($id > 0) Db::table($table)->where('id', $id)->delete();
    }
    Db::table('ffx_audit_logs')->whereLike('after_data', '%' . $marker . '%')->delete();
    Db::table('ffx_audit_logs')->whereLike('before_data', '%' . $marker . '%')->delete();
    // Article/tag and manual media-tag acceptance paths may create the same
    // marker in different scopes. Remove every exact acceptance marker.
    Db::table('ffx_tags')->where('name', '[' . $marker . ']')->delete();
});

$upload = basename((string) ($options['upload'] ?? ''));
if ($upload !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $upload)) {
    $path = public_path() . 'uploads/' . $upload;
    if (is_file($path)) unlink($path);
}

echo 'acceptance_cleanup=ok marker=' . $marker . PHP_EOL;
