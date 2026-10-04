<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhpCliLauncherTest extends TestCase
{
    public function testCliProbeAndWorkerSpawnWorkWithRestrictedOpenBasedir(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) self::markTestSkipped('需要 Unix proc_open');

        $root = dirname(__DIR__);
        $log = sys_get_temp_dir() . '/ff-cli-launcher-' . bin2hex(random_bytes(8)) . '.log';
        $script = 'require ' . var_export($root . '/app/service/PhpCliLauncher.php', true) . '; '
            . '$launcher = new \\app\\service\\PhpCliLauncher(); '
            . '$launcher->spawn([$launcher->executable(), "-r", "echo \'worker-ready\';"], '
            . var_export($root, true) . ', ' . var_export($log, true) . ');';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'open_basedir=' . $root . PATH_SEPARATOR . sys_get_temp_dir(), '-r', $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        try {
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            $output = '';
            for ($attempt = 0; $attempt < 40; $attempt++) {
                if (is_file($log)) $output = (string) file_get_contents($log);
                if ($output !== '') break;
                usleep(50_000);
            }
            self::assertSame('worker-ready', $output);
        } finally {
            if (is_file($log)) unlink($log);
        }
    }
}
