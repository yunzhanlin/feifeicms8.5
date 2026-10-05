<?php
declare(strict_types=1);

namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

/** Real routing/session/CSRF/rendering, with disposable admins and an audit_* DB only. */
final class DatabaseReplacementHttpTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static mixed $process = null;
    private static string $base;
    private static string $table;
    private static array $admins = [];
    private static string $cookie;
    private static string $password;
    private static string $username;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn || !function_exists('proc_open') || !extension_loaded('curl')) self::markTestSkipped('Disposable MySQL and local HTTP runtime are required');
        if (!preg_match('/;dbname=(audit_[a-z0-9_]+)(?:;|$)/D', $dsn, $database)) self::fail('Refusing to modify a non-audit database');
        preg_match('/host=([^;]+)/', $dsn, $host);
        preg_match('/;port=([0-9]+)/', $dsn, $port);
        self::$pdo = new PDO($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$table = 'ffx_audit_http_' . bin2hex(random_bytes(6));
        self::$pdo->exec('CREATE TABLE `' . self::$table . '` (id INT PRIMARY KEY,body TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        self::$pdo->exec('INSERT INTO `' . self::$table . '` VALUES (1,\'a\'),(2,\'<script>not executable</script>\')');
        self::$username = 'audit_replace_' . bin2hex(random_bytes(6));
        self::$password = 'fixture-' . bin2hex(random_bytes(12));
        $create = self::$pdo->prepare("INSERT INTO ffx_admins (username,password_hash,status) VALUES (?,?,'active')");
        $create->execute([self::$username, password_hash(self::$password, PASSWORD_BCRYPT)]);
        self::$admins[] = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO ffx_admin_roles (admin_id,role_id) SELECT ?,id FROM ffx_roles WHERE role_key='super_admin'")->execute([self::$admins[0]]);
        $create->execute([self::$username . '_staff', password_hash(self::$password, PASSWORD_BCRYPT)]);
        self::$admins[] = (int) self::$pdo->lastInsertId();
        self::$cookie = (string) tempnam(sys_get_temp_dir(), 'replace-cookie-');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false); fclose($socket);
        self::$base = 'http://' . $address;
        $env = getenv();
        $env['PHP_ENV_NAME'] = 'isolated-replace-' . bin2hex(random_bytes(8));
        $env['PHP_DB_HOST'] = $host[1]; $env['PHP_DB_PORT'] = $port[1] ?? '3306'; $env['PHP_DB_NAME'] = $database[1];
        $env['PHP_DB_USER'] = getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root'; $env['PHP_DB_PASS'] = getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '';
        $env['PHP_CACHE_DRIVER'] = 'file'; $env['PHP_SEARCH_DRIVER'] = 'mysql';
        $root = dirname(__DIR__);
        self::$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/public/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
        self::assertIsResource(self::$process);
        for ($i = 0; $i < 80; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($probe !== false) { fclose($probe); return; }
            usleep(50_000);
        }
        self::fail('HTTP regression server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) { proc_terminate(self::$process); proc_close(self::$process); }
        if (self::$pdo === null) return;
        $jobs = self::$pdo->query("SELECT id,payload FROM ffx_jobs WHERE job_type='database.field_replace'")->fetchAll(PDO::FETCH_ASSOC);
        $delete = self::$pdo->prepare('DELETE FROM ffx_jobs WHERE id=?');
        foreach ($jobs as $job) if ((json_decode((string) $job['payload'], true)['table'] ?? '') === self::$table) $delete->execute([$job['id']]);
        self::$pdo->prepare('DELETE FROM ffx_audit_logs WHERE target_id=?')->execute([self::$table . '.body']);
        $deleteAdmin = self::$pdo->prepare('DELETE FROM ffx_admins WHERE id=?');
        foreach (self::$admins as $id) $deleteAdmin->execute([$id]);
        self::$pdo->exec('DROP TABLE IF EXISTS `' . self::$table . '`');
        if (isset(self::$cookie) && is_file(self::$cookie)) unlink(self::$cookie);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $curl = curl_init(self::$base . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => self::$cookie, CURLOPT_COOKIEJAR => self::$cookie, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        try {
            $body = curl_exec($curl); self::assertIsString($body, curl_error($curl));
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status === 500) {
                $error = json_decode($body, true);
                self::fail('HTTP 500 ' . $path . ': ' . (string) ($error['message'] ?? mb_substr(trim(strip_tags($body)), 0, 300)));
            }
            return [$status, $body];
        } finally { unset($curl); }
    }

    private function token(string $html, string $name = '_token'): string
    {
        self::assertSame(1, preg_match('/name="' . $name . '" value="([a-f0-9]+)"/', $html, $match), substr(strip_tags($html), 0, 400));
        return $match[1];
    }

    private function login(string $username): void
    {
        [$status, $body] = $this->request('GET', '/admin.php');
        self::assertSame(200, $status, mb_substr(trim(strip_tags($body)), 0, 600));
        [$status] = $this->request('POST', '/admin.php/login', ['_token' => $this->token($body), 'username' => $username, 'password' => self::$password]);
        self::assertSame(302, $status);
    }

    public function testActualWorkflowRequiresAuthCsrfPreviewAndHandlesReplay(): void
    {
        foreach ([['GET', '/admin/database/replace'], ['GET', '/admin/database/replace/fields?table=ffx_media'], ['POST', '/admin/database/replace/preview'], ['POST', '/admin/database/replace']] as [$method, $path]) {
            self::assertSame(302, $this->request($method, $path)[0]);
        }
        $this->login(self::$username);
        [$status, $html] = $this->request('GET', '/admin/database/replace');
        self::assertSame(200, $status, substr(strip_tags($html), 0, 300));
        self::assertStringContainsString('id="exptable"', $html);
        $token = $this->token($html);
        self::assertSame(419, $this->request('POST', '/admin/database/replace/preview', ['_token' => 'bad'])[0]);
        self::assertSame(422, $this->request('POST', '/admin/database/replace', ['_token' => $token, 'confirm' => 'REPLACE', 'receipt' => str_repeat('a', 64)])[0]);
        [$status, $fields] = $this->request('GET', '/admin/database/replace/fields?table=' . self::$table);
        self::assertSame(200, $status);
        self::assertSame('body', json_decode($fields, true)['fields'][1]['name']);
        $input = ['_token' => $token, 'table' => self::$table, 'field' => 'body', 'search' => 'a', 'replacement' => 'aa', 'condition' => 'id=1'];
        [$status, $preview] = $this->request('POST', '/admin/database/replace/preview', $input);
        self::assertSame(200, $status, substr(strip_tags($preview), 0, 400));
        self::assertStringContainsString('替换预览：匹配 1 条', $preview);
        self::assertSame('a', self::$pdo->query('SELECT body FROM `' . self::$table . '` WHERE id=1')->fetchColumn());
        $receipt = $this->token($preview, 'receipt');
        $confirm = ['_token' => $token, 'confirm' => 'REPLACE', 'receipt' => $receipt];
        self::assertSame(302, $this->request('POST', '/admin/database/replace', $confirm)[0]);
        self::assertSame(302, $this->request('POST', '/admin/database/replace', $confirm)[0]);
        self::assertSame('aa', self::$pdo->query('SELECT body FROM `' . self::$table . '` WHERE id=1')->fetchColumn());
        self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM ffx_audit_logs WHERE target_id='" . self::$table . ".body'")->fetchColumn());
        $input['search'] = '<script>'; $input['replacement'] = '</textarea><script>alert(1)</script>'; $input['condition'] = 'id=2';
        [$status, $preview] = $this->request('POST', '/admin/database/replace/preview', $input);
        self::assertSame(200, $status);
        self::assertStringNotContainsString('<script>alert(1)</script>', $preview);
        self::assertStringContainsString('&lt;script&gt;', $preview);
        self::assertSame(419, $this->request('POST', '/admin/database/replace', ['_token' => 'bad', 'confirm' => 'REPLACE', 'receipt' => $this->token($preview, 'receipt')])[0]);
        self::assertSame(419, $this->request('POST', '/admin/logout', ['_token' => 'bad'])[0]);
        self::assertSame(302, $this->request('POST', '/admin/logout', ['_token' => $token])[0]);
        $this->login(self::$username . '_staff');
        foreach ([['GET', '/admin/database/replace'], ['GET', '/admin/database/replace/fields?table=ffx_media'], ['POST', '/admin/database/replace/preview'], ['POST', '/admin/database/replace']] as [$method, $path]) {
            self::assertSame(403, $this->request($method, $path)[0]);
        }
    }
}
