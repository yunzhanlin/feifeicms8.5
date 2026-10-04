<?php
/**
 * FeiFeiCMS 4.3 数据升级 v1.0.1
 * 将本文件复制到旧站根目录，以旧站管理员身份访问 /ff43-upgrade.php。
 * 本文件兼容旧站 PHP 5.6；目标 FeiFeiCMS 8.5 必须已在同一服务器独立安装。
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function ff43up_value($values, $key, $default = null)
{
    return isset($values[$key]) ? $values[$key] : $default;
}

function ff43up_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ff43up_fail($message, $status = 400)
{
    http_response_code($status);
    throw new RuntimeException($message);
}

function ff43up_config($root)
{
    $path = $root . '/Runtime/Conf/config.php';
    if (!is_file($path)) ff43up_fail('找不到旧站 Runtime/Conf/config.php，请将插件放在 FeiFeiCMS 4.3 网站根目录。');
    $config = require $path;
    if (!is_array($config)) ff43up_fail('旧站数据库配置无效。');
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $key) {
        if (empty($config[$key])) ff43up_fail('旧站数据库配置缺少 ' . $key . '。');
    }
    $prefix = (string) ff43up_value($config, 'DB_PREFIX', 'ff_');
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix) || strtolower($prefix) === 'ffx_') ff43up_fail('旧表前缀无效。');
    $config['DB_PREFIX'] = $prefix;
    return $config;
}

function ff43up_admin(array $config, $password)
{
    $adminId = (int) ff43up_value($_SESSION, 'feifeicms', 0);
    if ($adminId < 1 || empty($_SESSION['AdminLogin'])) ff43up_fail('请先登录旧站管理后台。', 403);
    if ($password === '') ff43up_fail('请输入当前旧站管理员密码。');
    $charset = strtolower((string) ff43up_value($config, 'DB_CHARSET', 'utf8')) === 'utf8mb4' ? 'utf8mb4' : 'utf8';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', (string) $config['DB_HOST'], (int) ff43up_value($config, 'DB_PORT', 3306), (string) $config['DB_NAME'], $charset);
    $pdo = new PDO($dsn, (string) $config['DB_USER'], (string) ff43up_value($config, 'DB_PWD', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statement = $pdo->prepare('SELECT admin_pwd,admin_del FROM `' . $config['DB_PREFIX'] . 'admin` WHERE admin_id=?');
    $statement->execute([$adminId]);
    $admin = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$admin || (int) $admin['admin_del'] !== 0 || !hash_equals(strtolower((string) $admin['admin_pwd']), md5($password))) {
        ff43up_fail('管理员密码不正确，或账号已停用。', 403);
    }
}

/** @return array{exit:int,output:string,data:array|null} */
function ff43up_command($php, $target, array $arguments, $sourcePassword)
{
    if (!function_exists('proc_open')) ff43up_fail('当前 PHP 禁用了 proc_open，无法从旧站网页启动迁移。');
    $command = array_merge([$php, $target . '/think', 'feifei:legacy43:upgrade'], $arguments);
    // Do not inherit old-site DB_* or APP_* environment variables: they could
    // override the destination site's .env and point writes at the wrong DB.
    $environment = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
        'LANG' => (string) (getenv('LANG') ?: 'C.UTF-8'),
        'FF43_DB_PASS' => $sourcePassword,
    ];
    $pipes = [];
    // PHP 5.6 does not accept an argv array here. Every argument is quoted
    // separately; the legacy database password remains only in the environment.
    $shellCommand = implode(' ', array_map('escapeshellarg', $command));
    $process = proc_open($shellCommand . ' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $target, $environment);
    if (!is_resource($process)) ff43up_fail('无法启动目标程序的迁移命令。');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $output = '';
    $started = time();
    $exit = -1;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = [];
        @stream_select($read, $write, $except, 1);
        if ($read) $output .= (string) fread($pipes[1], 65536);
        if (strlen($output) > 2097152 || time() - $started > 120) {
            proc_terminate($process);
            fclose($pipes[1]);
            proc_close($process);
            ff43up_fail('迁移命令超时或输出过大。请缩小每批条数，并查看目标站运行日志。');
        }
        $status = proc_get_status($process);
        if (!$status['running']) { $exit = (int) $status['exitcode']; break; }
    }
    $output .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $closed = proc_close($process);
    if ($exit < 0) $exit = $closed;
    $data = null;
    if (preg_match('/^FF43_JSON:(.*)$/m', $output, $matches)) {
        $decoded = json_decode($matches[1], true);
        if (is_array($decoded)) $data = $decoded;
    }
    return ['exit' => $exit, 'output' => $output, 'data' => $data];
}

function ff43up_args(array $config)
{
    return [
        '--host=' . (string) $config['DB_HOST'],
        '--port=' . (int) ff43up_value($config, 'DB_PORT', 3306),
        '--database=' . (string) $config['DB_NAME'],
        '--user=' . (string) $config['DB_USER'],
        '--prefix=' . (string) $config['DB_PREFIX'],
        '--charset=' . (strtolower((string) ff43up_value($config, 'DB_CHARSET', 'utf8')) === 'utf8mb4' ? 'utf8mb4' : 'utf8'),
    ];
}

function ff43up_check_php($binary)
{
    if (!function_exists('proc_open')) ff43up_fail('当前 PHP 禁用了 proc_open，无法从旧站网页启动迁移。');
    $pipes = [];
    $process = proc_open(escapeshellarg($binary) . " -r 'echo PHP_VERSION_ID;' 2>&1", [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) ff43up_fail('无法启动 PHP 8 CLI。');
    fclose($pipes[0]);
    $version = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || !ctype_digit($version) || (int) $version < 80200 || (int) $version >= 80600) {
        ff43up_fail('目标 PHP CLI 必须为 8.2～8.5。');
    }
}

function ff43up_json(array $payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$error = '';
$root = __DIR__;
try {
    if (PHP_VERSION_ID < 50600) ff43up_fail('旧站插件要求 PHP 5.6+；新站要求 PHP 8.2+。');
    if (!extension_loaded('pdo_mysql')) ff43up_fail('旧站 PHP 缺少 pdo_mysql 扩展。');
    if (!function_exists('openssl_random_pseudo_bytes')) ff43up_fail('旧站 PHP 缺少 OpenSSL 扩展。');
    if (!is_file($root . '/Lib/Conf/config.php') || !is_file($root . '/admin.php')) ff43up_fail('请将本文件复制到 FeiFeiCMS 4.3 网站根目录。');
    session_start();
    if ((int) ff43up_value($_SESSION, 'feifeicms', 0) < 1 || empty($_SESSION['AdminLogin'])) ff43up_fail('请先登录旧站管理后台，再打开本文件。', 403);
    if (!isset($_SESSION['ff43up_csrf'])) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(24, $strong);
        if ($bytes === false || !$strong) ff43up_fail('无法生成安全令牌，请检查 OpenSSL 扩展。', 500);
        $_SESSION['ff43up_csrf'] = bin2hex($bytes);
    }
    $config = ff43up_config($root);
    $action = (string) ff43up_value($_POST, 'action', '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['ff43up_csrf'], (string) ff43up_value($_POST, '_token', ''))) ff43up_fail('页面已过期，请刷新后重试。', 419);
        if ($action === 'prepare') {
            ff43up_admin($config, (string) ff43up_value($_POST, 'admin_password', ''));
            $target = realpath(trim((string) ff43up_value($_POST, 'target_root', '')));
            $php = realpath(trim((string) ff43up_value($_POST, 'php_binary', '')));
            if ($target === false || !is_file($target . '/think') || !is_file($target . '/vendor/autoload.php') || !is_file($target . '/app/plugin/Legacy43/Legacy43Migrator.php') || !is_file($target . '/runtime/install.lock')) ff43up_fail('目标路径必须是已安装的 FeiFeiCMS 8.5 项目根目录。');
            if ($php === false || !is_file($php) || !is_executable($php) || !preg_match('/^php(?:[0-9.]*)?$/i', basename($php))) ff43up_fail('请填写可执行的 PHP 8.2～8.5 CLI 绝对路径。');
            ff43up_check_php($php);
            // Re-run with the source connection, never passing its password in process arguments.
            $check = ff43up_command($php, $target, array_merge(ff43up_args($config), ['--preflight']), (string) ff43up_value($config, 'DB_PWD', ''));
            if ($check['exit'] !== 0 || !is_array($check['data']) || empty($check['data']['compatible'])) ff43up_fail('预检失败：' . substr($check['output'], -600));
            $_SESSION['ff43up_job'] = [
                'target' => $target, 'php' => $php, 'preflight' => $check['data'],
                'module_index' => 0, 'cursor' => 0, 'batch' => max(10, min(100, (int) ff43up_value($_POST, 'batch', 50))),
                'started' => false, 'done' => false, 'updated' => time(), 'processed' => 0,
            ];
        } elseif ($action === 'reset') {
            unset($_SESSION['ff43up_job']);
            ff43up_json(['ok' => true]);
        } elseif ($action === 'start' || $action === 'batch') {
            $job = ff43up_value($_SESSION, 'ff43up_job');
            if (!is_array($job) || time() - (int) ff43up_value($job, 'updated', 0) > 1800) ff43up_fail('迁移会话已过期，请重新预检。');
            if ($action === 'start') {
                $job['started'] = true;
                $job['updated'] = time();
                $_SESSION['ff43up_job'] = $job;
                ff43up_json(['ok' => true]);
            }
            if (empty($job['started'])) ff43up_fail('请先点击开始迁移。');
            $modules = (array) ff43up_value($job['preflight'], 'modules', []);
            $keys = array_keys($modules);
            while (isset($keys[$job['module_index']]) && empty($modules[$keys[$job['module_index']]]['exists'])) {
                $job['module_index']++;
            }
            if (!isset($keys[$job['module_index']])) {
                if (empty($job['done'])) {
                    try {
                        $finish = ff43up_command($job['php'], $job['target'], ['--finish'], (string) ff43up_value($config, 'DB_PWD', ''));
                        $job['search'] = $finish['exit'] === 0 ? '搜索索引同步完成' : '搜索索引同步失败，请在新站后台手动同步';
                    } catch (Exception $exception) {
                        $job['search'] = '搜索索引同步失败，请在新站后台手动同步';
                    }
                    $job['done'] = true;
                }
                $_SESSION['ff43up_job'] = $job;
                ff43up_json(['ok' => true, 'done' => true, 'processed' => $job['processed'], 'search' => $job['search']]);
            }
            $module = $keys[$job['module_index']];
            $arguments = array_merge(ff43up_args($config), [
                '--module=' . $module, '--cursor=' . (int) $job['cursor'], '--batch=' . (int) $job['batch'], '--once',
            ]);
            $result = ff43up_command($job['php'], $job['target'], $arguments, (string) ff43up_value($config, 'DB_PWD', ''));
            if (!is_array($result['data'])) ff43up_fail('迁移失败：' . substr($result['output'], -600));
            $batch = $result['data'];
            if ((int) ff43up_value($batch, 'errors', 0) > 0 || $result['exit'] !== 0) {
                ff43up_json(['ok' => false, 'message' => '本批有错误，游标未前进；处理原因后可重试。', 'batch' => $batch], 422);
            }
            $job['cursor'] = (int) ff43up_value($batch, 'cursor', $job['cursor']);
            $job['processed'] += (int) ff43up_value($batch, 'processed', 0);
            if (!empty($batch['done'])) { $job['module_index']++; $job['cursor'] = 0; }
            $job['updated'] = time();
            $_SESSION['ff43up_job'] = $job;
            ff43up_json(['ok' => true, 'done' => false, 'module' => $module, 'module_index' => $job['module_index'], 'module_total' => count($keys), 'batch' => $batch, 'processed' => $job['processed']]);
        } else ff43up_fail('未知操作。');
    }
} catch (Exception $exception) {
    $error = $exception->getMessage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (ff43up_value($_POST, 'action', '') !== 'prepare')) ff43up_json(['ok' => false, 'message' => $error], http_response_code() >= 400 ? http_response_code() : 500);
}
$job = ff43up_value($_SESSION, 'ff43up_job');
$token = ff43up_value($_SESSION, 'ff43up_csrf', '');
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FeiFeiCMS 4.3 数据升级 v1.0.1</title>
<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Microsoft YaHei",sans-serif;background:#f3f6fb;color:#25344a;margin:0}main{max-width:940px;margin:35px auto;background:#fff;border:1px solid #cbdaf0;box-shadow:0 8px 30px #233b5b15}h1{margin:0;padding:17px 22px;background:linear-gradient(#4168a7,#25467f);color:#fff;font-size:21px}section{padding:20px 24px;border-top:1px solid #d8e3f2}h2{color:#15518e;font-size:17px;margin:0 0 12px}label{display:block;margin:10px 0;color:#174779}input{box-sizing:border-box;display:block;width:100%;max-width:650px;height:38px;margin-top:4px;border:1px solid #a8b8c8;padding:5px 9px;font:inherit}button{border:1px solid #9fb8d2;background:#d9edff;color:#174b80;padding:8px 17px;margin:8px 8px 0 0;cursor:pointer;font:inherit}button.primary{background:#31558f;color:#fff}.error{background:#fff1ef;color:#ad251d;padding:10px}.note{background:#fff9e9;border:1px solid #ecd591;padding:11px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #c9d8ec;padding:7px;text-align:left}th{background:#d9e9fb}#progress{white-space:pre-wrap;background:#f4f8fd;padding:12px;min-height:55px}</style></head><body><main><h1>FeiFeiCMS 4.3 数据升级 v1.0.1</h1>
<section><div class="note">先在独立目录安装 FeiFeiCMS 8.5，并备份新旧数据库。本插件只从 4.3 读取数据，向 8.5 的 ffx_ 表分批写入；不会替换旧站程序、切换 PHP 或删除旧库。升级完成并验收后，再单独切换站点入口。</div><?php if ($error !== ''): ?><p class="error"><?= ff43up_escape($error) ?></p><?php endif; ?></section>
<?php if (!is_array($job)): ?><section><h2>连接目标程序并预检</h2><form method="post"><input type="hidden" name="action" value="prepare"><input type="hidden" name="_token" value="<?= ff43up_escape($token) ?>"><label>已安装的 FeiFeiCMS 8.5 项目根目录<input name="target_root" placeholder="/www/wwwroot/feifeicms85" required></label><label>PHP 8.2～8.5 CLI 绝对路径<input name="php_binary" placeholder="/www/server/php/84/bin/php" required></label><label>每批条数<input name="batch" type="number" min="10" max="100" value="50"></label><label>旧站管理员密码（仅本次验证，不保存）<input name="admin_password" type="password" autocomplete="current-password" required></label><button class="primary" type="submit">验证身份并预检</button></form></section>
<?php else: ?><section><h2>预检结果</h2><p>目标：<?= ff43up_escape($job['target']) ?>　批量：<?= (int) $job['batch'] ?> 条　已处理：<?= (int) $job['processed'] ?> 条</p><table><thead><tr><th>模块</th><th>旧表</th><th>旧站条数</th><th>已迁移</th></tr></thead><tbody><?php foreach ($job['preflight']['modules'] as $module): ?><tr><td><?= ff43up_escape($module['label']) ?></td><td><?= ff43up_escape($module['table']) ?></td><td><?= (int) $module['count'] ?></td><td><?= (int) $module['migrated'] ?></td></tr><?php endforeach; ?></tbody></table><button class="primary" id="start" type="button"><?= !empty($job['started']) ? '继续迁移' : '开始迁移' ?></button><button id="reset" type="button">重新预检</button><p id="progress"><?= !empty($job['done']) ? '数据迁移已完成。请到 8.5 后台检查分类、影片、分集、会员和搜索索引。' : '等待开始。迁移期间请勿关闭此页面；中断后可刷新继续。' ?></p></section><?php endif; ?>
<section>安全提示：迁移完成后，请从旧站删除 <code>ff43-upgrade.php</code>。目标程序的管理后台和旧站应分别限制访问。</section></main>
<?php if (is_array($job)): ?><script>
const csrf=<?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, progress=document.getElementById('progress');
async function send(action){const body=new URLSearchParams({action,_token:csrf});const response=await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const data=await response.json();if(!response.ok||!data.ok)throw new Error((data.message||'请求失败')+(data.batch&&data.batch.messages?'\n'+data.batch.messages.join('\n'):''));return data;}
document.getElementById('start').onclick=async()=>{document.getElementById('start').disabled=true;try{await send('start');for(;;){const data=await send('batch');if(data.done){progress.textContent='迁移完成，累计处理 '+data.processed+' 条。'+data.search+'。请到 8.5 后台抽查数据。';break;}progress.textContent='模块 '+data.module+'（'+data.module_index+'/'+data.module_total+'），本批 '+data.batch.processed+' 条，累计 '+data.processed+' 条。';}}catch(error){progress.textContent='已暂停：'+error.message+'\n排查后点击“继续迁移”。';}finally{document.getElementById('start').disabled=false;}};
document.getElementById('reset').onclick=async()=>{if(confirm('重新预检？现有迁移数据不会删除。')){await send('reset');location.reload();}};
</script><?php endif; ?></body></html>
