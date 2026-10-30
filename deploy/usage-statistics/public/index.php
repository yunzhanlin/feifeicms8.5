<?php
declare(strict_types=1);
use FeifeiUsage\Statistics;

require dirname(__DIR__) . '/src/Statistics.php';
umask(0077);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');
function jsonReply(int $status, array $body): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
function escape(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirectHome(): never { header('Location: /', true, 303); exit; }

try {
    $data = getenv('STATS_DATA') ?: '/data';
    $config = json_decode((string) file_get_contents($data . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !isset($config['secret'], $config['password_hash'], $config['public_url'])) throw new RuntimeException('Not initialized');
    $store = new Statistics($data . '/statistics.sqlite', $config['secret']);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Enable only behind the dedicated loopback-bound reverse proxy, which overwrites this header.
    if (getenv('STATS_TRUST_PROXY') === '1' && filter_var($_SERVER['HTTP_X_REAL_IP'] ?? '', FILTER_VALIDATE_IP)) $address = $_SERVER['HTTP_X_REAL_IP'];
    if ($path === '/health' && $method === 'GET') jsonReply(200, ['ok' => true]);
    if ($path === '/v1/heartbeat') {
        if ($method !== 'POST') { header('Allow: POST'); jsonReply(405, ['ok' => false]); }
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') jsonReply(415, ['ok' => false]);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) jsonReply(413, ['ok' => false]);
        $body = file_get_contents('php://input', false, null, 0, 2049);
        if (!is_string($body) || strlen($body) > 2048) jsonReply(413, ['ok' => false]);
        try { $payload = Statistics::validate(json_decode($body, true, 16, JSON_THROW_ON_ERROR)); }
        catch (\InvalidArgumentException | \JsonException) { jsonReply(422, ['ok' => false]); }
        if (!$store->receive($payload, $address)) { header('Retry-After: 3600'); jsonReply(429, ['ok' => false]); }
        jsonReply(200, ['ok' => true, 'schema' => 1]);
    }
    if (!in_array($path, ['/', '/login', '/logout', '/api/summary'], true)) jsonReply(404, ['ok' => false]);
    if (!in_array($method, ['GET','POST'], true) || ($path !== '/' && $path !== '/api/summary' && $method !== 'POST') || (($path === '/' || $path === '/api/summary') && $method !== 'GET')) jsonReply(405, ['ok' => false]);
    session_save_path($data . '/sessions');
    session_name('FEIFEI_USAGE_ADMIN');
    session_start(['use_strict_mode' => 1, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict',
        'cookie_secure' => str_starts_with($config['public_url'], 'https://'), 'gc_maxlifetime' => 3600]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    $verifier = hash('sha256', $config['password_hash']);
    $authenticated = hash_equals($verifier, (string) ($_SESSION['verifier'] ?? '')) && (int) ($_SESSION['expires'] ?? 0) > time();
    $error = '';
    if ($method === 'POST') {
        if (!is_string($_POST['_token'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['_token'])) jsonReply(419, ['ok' => false]);
        if ($path === '/logout') { $_SESSION = []; session_destroy(); redirectHome(); }
        if (!$store->allow('login', $address, 20)) { http_response_code(429); $error = '尝试过于频繁，请稍后再试。'; }
        elseif (is_string($_POST['username'] ?? null) && is_string($_POST['password'] ?? null)
            && strlen($_POST['password']) <= 200 && hash_equals($config['username'], $_POST['username'])
            && password_verify($_POST['password'], $config['password_hash'])) {
            session_regenerate_id(true); $_SESSION['verifier'] = $verifier; $_SESSION['expires'] = time() + 3600; redirectHome();
        } else { http_response_code(401); $error = '账号或密码不正确。'; }
    }
    if ($path === '/api/summary') {
        if (!$authenticated) jsonReply(401, ['ok' => false]);
        jsonReply(200, ['ok' => true, 'data' => $store->summary()]);
    }
    $summary = $authenticated ? $store->summary() : null;
} catch (\Throwable) { jsonReply(503, ['ok' => false, 'error' => 'Service unavailable']); }
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FeiFeiCMS 程序使用统计</title>
<style>
:root{font:15px/1.6 system-ui,-apple-system,sans-serif;color:#172b4d;background:#f3f6fb}*{box-sizing:border-box}body{max-width:1120px;margin:0 auto;padding:32px 24px}header{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:24px}h1{font-size:25px;margin:0}p{color:#5b6b82;margin:8px 0}button{background:#245dcc;color:white;border:0;border-radius:8px;padding:10px 18px;cursor:pointer;font:inherit}input{font:inherit;width:100%;padding:10px;border:1px solid #c4cfdf;border-radius:6px;margin:6px 0 16px}label{display:block}.login{max-width:400px;margin:10vh auto}.card,section{background:white;border:1px solid #e0e7f0;border-radius:12px;padding:24px}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}.number{display:block;font-size:34px;font-weight:650;color:#153e80}.muted{font-size:13px;color:#62758e}.columns{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px}h2{font-size:18px;margin:0 0 16px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 4px;border-bottom:1px solid #edf0f6}th:last-child,td:last-child{text-align:right}.chart{height:190px;display:flex;gap:5px;align-items:end;border-bottom:1px solid #ccd7e7;padding-top:18px}.bar{flex:1;background:#4675df;border-radius:4px 4px 0 0;min-height:2px;position:relative}.bar span{position:absolute;bottom:100%;width:100%;text-align:center;font-size:10px;color:#667c9a}.dates{display:flex;justify-content:space-between;color:#62758e;font-size:12px;margin-top:8px}.error{color:#b52a35}footer{font-size:13px;color:#62758e;margin:24px 0}@media(max-width:700px){body{padding:20px 14px}.cards{grid-template-columns:1fr 1fr}.columns{grid-template-columns:1fr}.card,section{padding:18px}.bar span{display:none}header{align-items:start}h1{font-size:21px}}
</style>
<?php if (!$authenticated): ?>
<section class="login"><h1>程序使用统计</h1><p>仅管理者可查看汇总数据。</p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
<form method="post" action="/login"><input type="hidden" name="_token" value="<?= escape($_SESSION['csrf']) ?>">
<label>账号<input name="username" autocomplete="username" required></label><label>密码<input name="password" type="password" autocomplete="current-password" required></label><button>登录统计面板</button></form></section>
<?php else: ?>
<header><div><h1>FeiFeiCMS 程序使用统计</h1><p>匿名安装实例与版本分布</p></div><form method="post" action="/logout"><input type="hidden" name="_token" value="<?= escape($_SESSION['csrf']) ?>"><button>退出</button></form></header>
<div class="cards"><?php foreach (['total'=>'累计使用实例','active_7d'=>'近 7 天活跃','active_30d'=>'近 30 天活跃','new_7d'=>'近 7 天新增'] as $key=>$label): ?><div class="card"><span class="muted"><?= escape($label) ?></span><strong class="number"><?= number_format($summary['counts'][$key]) ?></strong></div><?php endforeach; ?></div>
<section><h2>最近 30 天每日活跃实例 <span class="muted">UTC</span></h2><div class="chart" role="img" aria-label="最近 30 天每日活跃安装数柱状图">
<?php $max = max(1, ...array_column($summary['days'], 'count')); foreach ($summary['days'] as $day): ?><div class="bar" style="height:<?= max(1, (int) round($day['count'] / $max * 100)) ?>%" title="<?= escape($day['day'] . '：' . $day['count']) ?>"><span><?= $day['count'] ?></span></div><?php endforeach; ?></div><div class="dates"><span><?= escape($summary['days'][0]['day']) ?></span><span><?= escape($summary['days'][29]['day']) ?></span></div></section>
<div class="columns"><?php foreach (['versions'=>'程序版本','php'=>'PHP 版本'] as $key=>$label): ?><section><h2><?= escape($label) ?> <span class="muted">近 30 天活跃</span></h2><table><thead><tr><th>版本</th><th>实例数</th></tr></thead><tbody><?php foreach ($summary[$key] as $row): ?><tr><td><?= escape($row['label']) ?></td><td><?= (int) $row['count'] ?></td></tr><?php endforeach; ?><?php if ($summary[$key] === []): ?><tr><td colspan="2">暂无数据</td></tr><?php endif; ?></tbody></table></section><?php endforeach; ?></div>
<footer>按随机安装 ID 去重，不代表独立人数。同一人的多个安装分别计数；关闭上报、未升级或离线的实例无法纳入。不采集域名、站名、账号、内容或访客信息。页面数据生成于 <?= escape($summary['generated_at']) ?>。</footer>
<?php endif; ?></html>
