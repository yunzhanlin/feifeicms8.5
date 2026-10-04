<?php
declare(strict_types=1);

namespace app\service;

/** Starts update workers without probing PHP executables through open_basedir. */
final class PhpCliLauncher
{
    public function executable(): string
    {
        if (!function_exists('proc_open')) throw new \RuntimeException('服务器已禁用 proc_open，无法启动后台更新');

        // Panel PHP binaries commonly live outside the site's open_basedir.
        // is_file()/is_executable() would reject them even when the web user
        // can execute the matching CLI binary.
        foreach (array_unique([PHP_BINDIR . DIRECTORY_SEPARATOR . 'php', 'php']) as $candidate) {
            if ($this->isMatchingCli($candidate)) return $candidate;
        }
        throw new \RuntimeException('未找到与网站 PHP 版本匹配且可执行的 PHP CLI，请检查面板 PHP 安装和 proc_open 权限');
    }

    /** @param list<string> $command */
    public function spawn(array $command, string $cwd, string $log): void
    {
        if ($command === []) throw new \InvalidArgumentException('后台命令不能为空');
        $shell = implode(' ', array_map('escapeshellarg', $command))
            . ' > ' . escapeshellarg($log) . ' 2>&1 </dev/null &';
        // Descriptor paths are checked against the web request's open_basedir.
        // Pipes are safe here; the detached worker redirects its own streams.
        $pipes = [];
        try {
            $process = @proc_open(['/bin/sh', '-c', $shell], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd);
        } catch (\Throwable $e) {
            throw new \RuntimeException('无法启动后台更新进程：' . $e->getMessage(), 0, $e);
        }
        if (!is_resource($process)) throw new \RuntimeException('无法启动后台更新进程');
        foreach ($pipes as $pipe) fclose($pipe);
        if (proc_close($process) !== 0) throw new \RuntimeException('后台更新进程启动失败');
    }

    private function isMatchingCli(string $candidate): bool
    {
        $pipes = [];
        try {
            $process = @proc_open(
                [$candidate, '-r', 'echo PHP_SAPI, ":", PHP_VERSION_ID;'],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes
            );
        } catch (\Throwable) {
            return false;
        }
        if (!is_resource($process)) return false;
        fclose($pipes[0]);
        $output = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !preg_match('/^cli:(\d+)$/', $output, $match)) return false;
        return intdiv((int) $match[1], 100) === intdiv(PHP_VERSION_ID, 100);
    }
}
