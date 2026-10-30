<?php
declare(strict_types=1);

/** Real receiver HTTP/authentication and optional disposable CMS/MySQL acceptance. */
$root = dirname(__DIR__);
require $root . '/deploy/usage-statistics/src/Statistics.php';
use FeifeiUsage\Statistics;

$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks[] = $label;
};
$work = sys_get_temp_dir() . '/feifei-usage-verify-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$process = null;
$cmsProcess = null;
$run = static function (array $command, array $env, string $cwd) use ($check): string {
    $p = proc_open($command, [0 => ['file','/dev/null','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $cwd, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($p) === 0, 'Command completed: ' . basename($command[1] ?? '') . ' ' . ($command[2] ?? '') . ($err !== '' ? ' (stderr present)' : ''));
    return $out;
};
$env = getenv(); $env['STATS_DATA'] = $work; $env['STATS_PUBLIC_URL'] = 'http://127.0.0.1'; unset($env['STATS_TRUST_PROXY']);
try {
    $run([PHP_BINARY, $root . '/deploy/usage-statistics/bin/init.php'], $env, $root);
    $before = hash_file('sha256', $work . '/config.json');
    $run([PHP_BINARY, $root . '/deploy/usage-statistics/bin/init.php'], $env, $root);
    $check(hash_equals($before, hash_file('sha256', $work . '/config.json')), 'Repeated initialization preserves credentials');
    $check((fileperms($work . '/config.json') & 0777) === 0600, 'Private configuration permissions');
    $config = json_decode(file_get_contents($work . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $store = new Statistics($work . '/statistics.sqlite', $config['secret']);
    $now = time();
    $id = '11111111-1111-4111-8111-111111111111';
    $payload = ['schema'=>1,'installation_id'=>$id,'version'=>'8.5.261029','source'=>'web','php'=>'8.5'];
    $check($store->receive($payload, '192.0.2.1', $now - 40 * 86400), 'Old installation accepted');
    $check($store->receive($payload, '192.0.2.1', $now - 10 * 86400), 'Recurring activity accepted');
    $payload['version'] = '8.5.261030';
    $check($store->receive($payload, '192.0.2.1', $now), 'Version change accepted');
    $check($store->receive($payload, '192.0.2.1', $now), 'Duplicate heartbeat accepted');
    $summary = $store->summary($now);
    $check($summary['counts']['total'] === 1 && $summary['counts']['active_7d'] === 1 && $summary['counts']['new_7d'] === 0, 'Stable ID deduplication and first-seen preservation');
    $check(count($summary['days']) === 30 && $summary['days'][29]['count'] === 1, 'Daily activity deduplication');
    $check($summary['versions'][0]['label'] === '8.5.261030' && (int) $summary['versions'][0]['count'] === 1, 'Latest version distribution');
    unset($payload['php']); $store->receive($payload, '192.0.2.1', $now);
    $check($store->summary($now)['php'][0]['label'] === '未提供', 'Optional environment data can be withdrawn');
    $payload['domain'] = 'must-not-be-stored.example';
    try { Statistics::validate($payload); $valid = true; } catch (InvalidArgumentException) { $valid = false; }
    $check(!$valid, 'Domain and all unknown fields rejected'); unset($payload['domain']);
    $all = json_encode($store->db->query('SELECT * FROM installations')->fetchAll());
    $check(!str_contains($all, '192.0.2.1') && !str_contains($all, 'example'), 'No raw address/domain fields retained');
    for ($i = 0; $i < 31; $i++) {
        $p = $payload; $p['installation_id'] = sprintf('%08x-1111-4111-8111-111111111111', $i + 100);
        $accepted = $store->receive($p, '192.0.2.200', $now);
    }
    $check(!$accepted, 'New-ID abuse limited per hourly address HMAC');

    $socket = stream_socket_server('tcp://127.0.0.1:0'); $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address;
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/deploy/usage-statistics/public', $root . '/deploy/usage-statistics/public/index.php'],
        [0=>['file','/dev/null','r'],1=>['file',$work.'/http.log','a'],2=>['file',$work.'/http.log','a']], $pipes, $root, $env);
    $check(is_resource($process), 'Receiver HTTP process started');
    for ($i = 0; $i < 100; $i++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $error, .1); if ($probe) { fclose($probe); break; } usleep(20000); }
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEFILE=>'', CURLOPT_TIMEOUT=>5]);
    $request = static function (string $path, ?string $body = null, string $type = 'application/json') use ($curl, $base): array {
        curl_setopt($curl, CURLOPT_URL, $base . $path); curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: ' . $type]);
        if ($body === null) curl_setopt($curl, CURLOPT_HTTPGET, true);
        else { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
        $out = curl_exec($curl);
        if (!is_string($out)) throw new RuntimeException('HTTP failed');
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $out];
    };
    $check($request('/health')[0] === 200, 'Health endpoint');
    $check($request('/api/summary')[0] === 401, 'Summary requires login');
    $check($request('/config.json')[0] === 404 && $request('/src/Statistics.php')[0] === 404, 'Private code and credentials unavailable over HTTP');
    $check($request('/v1/heartbeat')[0] === 405, 'Heartbeat rejects GET');
    $check($request('/v1/heartbeat', '{}', 'text/plain')[0] === 415, 'Heartbeat content type enforced');
    $check($request('/v1/heartbeat', '{')[0] === 422, 'Malformed JSON rejected');
    $check($request('/v1/heartbeat', json_encode($payload + ['domain'=>'example.test']))[0] === 422, 'Domain rejected over HTTP');
    $check($request('/v1/heartbeat', str_repeat('x', 2049))[0] === 413, 'Oversized body rejected');
    $check($request('/v1/heartbeat', json_encode($payload))[0] === 200, 'Real HTTP heartbeat acknowledged');
    [$status,$page] = $request('/');
    preg_match('/name="_token" value="([^"]+)"/', $page, $match); $token = $match[1] ?? '';
    $check($status === 200 && $token !== '', 'Login form and CSRF token');
    $check($request('/login', http_build_query(['username'=>'admin','password'=>'wrong']), 'application/x-www-form-urlencoded')[0] === 419, 'Login CSRF enforced');
    $credentials = file_get_contents($work . '/admin-login.txt');
    preg_match('/密码：([^\n]+)/u', $credentials, $match); $password = $match[1];
    $check($request('/login', http_build_query(['_token'=>$token,'username'=>'admin','password'=>$password]), 'application/x-www-form-urlencoded')[0] === 303, 'Real administrator login');
    [$status,$page] = $request('/');
    $check($status === 200 && str_contains($page, '近 30 天活跃') && !str_contains($page, $id), 'Dashboard shows aggregate data only');
    $check($request('/api/summary')[0] === 200, 'Authenticated summary available');
    $check($request('/logout', http_build_query(['_token'=>$token]), 'application/x-www-form-urlencoded')[0] === 303, 'Logout succeeds');
    $check($request('/api/summary')[0] === 401, 'Logout revokes access');

    $cms = getenv('FEIFEI_USAGE_TEST_ROOT');
    $dsn = getenv('FEIFEI_USAGE_MYSQL_DSN');
    if ($cms && $dsn) {
        $cms = realpath($cms);
        $check(is_string($cms) && str_starts_with($cms, '/tmp/feifei-usage-') && preg_match('/;dbname=audit_usage_[a-z0-9_]+(?:;|$)/D', $dsn) === 1, 'Disposable CMS and database guard');
        $db = new PDO($dsn, getenv('FEIFEI_USAGE_MYSQL_USER') ?: 'root', getenv('FEIFEI_USAGE_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec("DELETE FROM ffx_site_settings WHERE setting_key='internal.usage_reporting.v1'");
        if (is_file($cms.'/runtime/usage-reporting.next')) unlink($cms.'/runtime/usage-reporting.next');
        $clientEnv = getenv(); $clientEnv['PHP_USAGE_ENDPOINT'] = $base . '/v1/heartbeat'; $clientEnv['PHP_USAGE_REPORTING'] = 'true';
        $send = static fn(array $e): string => $run([PHP_BINARY, $cms.'/think', 'feifei:usage:report'], $e, $cms);
        $check(str_contains($send($clientEnv), 'sent'), 'CMS command sends real receiver heartbeat');
        $state = json_decode($db->query("SELECT setting_value FROM ffx_site_settings WHERE setting_key='internal.usage_reporting.v1'")->fetchColumn(), true);
        $check($state['last_success'] > 0 && preg_match('/^[a-f0-9-]{36}$/D', $state['id']) === 1, 'Installation ID and successful heartbeat persisted privately');
        $check(str_contains($send($clientEnv), 'not_due'), 'Daily reporting throttle');
        unlink($cms . '/runtime/usage-reporting.next');
        $check(str_contains($send($clientEnv), 'not_due'), 'Cache/stamp loss does not bypass database throttle');
        $clientEnv['PHP_USAGE_REPORTING'] = 'false';
        $check(str_contains($send($clientEnv), 'disabled'), 'Environment opt-out honored');
        $clientEnv['PHP_USAGE_REPORTING'] = 'true';
        $clientEnv['PHP_USAGE_ENDPOINT'] = 'http://external.example/v1/heartbeat';
        $p = proc_open([PHP_BINARY,$cms.'/think','feifei:usage:report'],[1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,$cms,$clientEnv);
        $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $check(proc_close($p) === 1 && str_contains($out, 'invalid_endpoint'), 'Unencrypted public receiver rejected');
        $check(json_decode($db->query("SELECT setting_value FROM ffx_site_settings WHERE setting_key='internal.usage_reporting.v1'")->fetchColumn(), true) === $state, 'Opt-out and validation preserve reporting state');

        $expire = static function () use ($db, $cms): void {
            $state = json_decode($db->query("SELECT setting_value FROM ffx_site_settings WHERE setting_key='internal.usage_reporting.v1'")->fetchColumn(), true);
            $state['next_attempt'] = 0;
            $db->prepare("UPDATE ffx_site_settings SET setting_value=? WHERE setting_key='internal.usage_reporting.v1'")->execute([json_encode($state)]);
            if (is_file($cms.'/runtime/usage-reporting.next')) unlink($cms.'/runtime/usage-reporting.next');
        };
        $expire();
        $clientEnv['PHP_USAGE_ENDPOINT'] = $base . '/v1/heartbeat';
        $socket=stream_socket_server('tcp://127.0.0.1:0');$cmsAddress=stream_socket_get_name($socket,false);fclose($socket);
        $cmsProcess=proc_open([PHP_BINARY,'-S',$cmsAddress,'-t',$cms.'/public',$cms.'/public/router.php'],
            [0=>['file','/dev/null','r'],1=>['file',$work.'/cms.log','a'],2=>['file',$work.'/cms.log','a']],$pipes,$cms,$clientEnv);
        for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://'.$cmsAddress,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(20000);}
        $cmsCurl=curl_init();curl_setopt_array($cmsCurl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
        curl_setopt($cmsCurl,CURLOPT_URL,'http://'.$cmsAddress.'/health');curl_exec($cmsCurl);
        $unchanged=json_decode($db->query("SELECT setting_value FROM ffx_site_settings WHERE setting_key='internal.usage_reporting.v1'")->fetchColumn(),true);
        $check($unchanged['next_attempt']===0, 'CMS health checks do not report activity');
        curl_setopt($cmsCurl,CURLOPT_URL,'http://'.$cmsAddress.'/admin.php');$html=curl_exec($cmsCurl);
        $check(curl_getinfo($cmsCurl,CURLINFO_RESPONSE_CODE)===200 && is_string($html), 'CMS login page renders with heartbeat hook');
        $query=$store->db->prepare('SELECT source FROM installations WHERE id=?');$query->execute([$state['id']]);
        $check($query->fetchColumn()==='web', 'Real CMS HTTP completion sends anonymous heartbeat');
        proc_terminate($cmsProcess);proc_close($cmsProcess);$cmsProcess=null;

        $expire();
        $socket=stream_socket_server('tcp://127.0.0.1:0');$closedAddress=stream_socket_get_name($socket,false);fclose($socket);
        $clientEnv['PHP_USAGE_ENDPOINT']='http://'.$closedAddress.'/v1/heartbeat';
        $started=microtime(true);
        $p=proc_open([PHP_BINARY,$cms.'/think','feifei:usage:report'],[1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,$cms,$clientEnv);
        $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($p);
        $check($exit===1 && str_contains($out,'failed') && microtime(true)-$started<2.5, 'Receiver failure is bounded and reported without exceptions');
        $failure=json_decode($db->query("SELECT setting_value FROM ffx_site_settings WHERE setting_key='internal.usage_reporting.v1'")->fetchColumn(),true);
        $check($failure['id']===$state['id'] && $failure['next_attempt']>time() && $failure['failures']===1, 'Failure preserves identity and applies retry backoff');
    }
    echo json_encode(['ok'=>true,'checks'=>count($checks),'passed'=>$checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (is_resource($cmsProcess)) { proc_terminate($cmsProcess); proc_close($cmsProcess); }
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($work);
}
