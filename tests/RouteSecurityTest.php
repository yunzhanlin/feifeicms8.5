<?php
declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the real router without any reachable database or remote service. */
final class RouteSecurityTest extends TestCase
{
    private static mixed $process = null;
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !extension_loaded('curl')) self::markTestSkipped('Local HTTP regression requires proc_open and curl');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) self::markTestSkipped('Cannot open a local test listener');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $address;
        $root = dirname(__DIR__);
        $env = getenv();
        // ThinkPHP uses PHP_* and gives .env precedence. A nonexistent env name
        // keeps the real site's credentials/services out of this test process.
        $env['PHP_ENV_NAME'] = 'isolated-http-' . bin2hex(random_bytes(8));
        $env['PHP_DB_HOST'] = '127.0.0.1'; $env['PHP_DB_PORT'] = '1'; $env['PHP_DB_NAME'] = 'audit_unavailable';
        $env['PHP_REDIS_HOST'] = '127.0.0.1'; $env['PHP_REDIS_PORT'] = '1'; $env['PHP_CACHE_DRIVER'] = 'file';
        self::$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/public/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
        if (!is_resource(self::$process)) self::markTestSkipped('Cannot launch isolated HTTP server');
        for ($i = 0; $i < 60; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($probe !== false) { fclose($probe); return; }
            usleep(50_000);
        }
        self::tearDownAfterClass();
        self::fail('Local HTTP server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) { proc_terminate(self::$process); proc_close(self::$process); }
        self::$process = null;
    }

    #[DataProvider('blockedRequests')]
    public function testControllerBypassAndTemplateDownloadAreClosed(string $method, string $path): void
    {
        [$code, $body] = $this->request($method, $path);
        // ThinkPHP terminates OPTIONS with an empty 204 before dispatch; that
        // must not be confused with controller execution or source disclosure.
        $expected = $method === 'OPTIONS' && str_contains($path, 'admin.') ? 204 : 404;
        self::assertSame($expected, $code, $method . ' ' . $path . ': ' . substr($body, 0, 120));
        if ($expected === 204) self::assertSame('', $body);
        self::assertStringNotContainsString('{include file=', $body);
        self::assertStringNotContainsString('CREATE TABLE', $body);
    }

    public static function blockedRequests(): array
    {
        $cases = [];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $cases[] = [$method, '/admin.Tools/templates?file=mxone/index/index.html'];
            $cases[] = [$method, '/admin.Database/index'];
            $cases[] = [$method, '/index.php?s=/admin.Tools/templates&file=mxone/index/index.html'];
        }
        foreach (['GET', 'HEAD', 'POST'] as $method) {
            foreach (['/view/mxone/index/index.html', '/public/view/mxone/index/index.html', '/index.php/view/mxone/index/index.html', '/%76iew/mxone/index/index.html', '/view.zip', '/.env', '/router.php'] as $path) $cases[] = [$method, $path];
        }
        return $cases;
    }

    public function testNormalTemplateManagementStillRequiresLogin(): void
    {
        [$code] = $this->request('GET', '/admin/tools/templates');
        self::assertSame(302, $code, 'Normal management route must reach the authentication guard');
    }

    private function request(string $method, string $path): array
    {
        $curl = curl_init(self::$base . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOBODY => $method === 'HEAD']);
        try {
            $body = curl_exec($curl);
            self::assertIsString($body, curl_error($curl));
            return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
        } finally { unset($curl); }
    }
}
