<?php
declare(strict_types=1);

namespace tests;

use app\service\ProgramUpdater;
use PHPUnit\Framework\TestCase;

final class UpdaterStateLockTest extends TestCase
{
    public function testLateLauncherFailureCannotOverwriteAWorkerHoldingTheLock(): void
    {
        $previous = \think\Container::getInstance();
        $reporting = error_reporting();
        $displayErrors = ini_get('display_errors');
        $temporary = sys_get_temp_dir() . '/feifei-update-lock-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        $app = new \think\App(dirname(__DIR__));
        $app->setEnvName('isolated-lock-test');
        $app->setRuntimePath($temporary . DIRECTORY_SEPARATOR);
        $app->initialize();
        $lock = null;
        try {
            $updater = new ProgramUpdater();
            $directory = $updater->directory();
            $state = ['id' => 'fixture-job', 'status' => 'queued', 'message' => 'waiting'];
            file_put_contents($directory . '/job.json', json_encode($state));
            $lock = fopen($directory . '/update.lock', 'c');
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            $updater->failQueued('fixture-job', 'late launcher failure');
            self::assertSame($state, $updater->state());
            flock($lock, LOCK_UN);
            $updater->failQueued('other-job', 'unrelated failure');
            self::assertSame($state, $updater->state());
            $updater->failQueued('fixture-job', 'launcher did not start');
            self::assertSame('failed', $updater->state()['status']);
            self::assertSame('launcher did not start', $updater->state()['message']);
        } finally {
            if (is_resource($lock)) fclose($lock);
            \think\Container::setInstance($previous);
            restore_error_handler();
            restore_exception_handler();
            error_reporting($reporting);
            ini_set('display_errors', $displayErrors);
            foreach (glob($temporary . '/updates/*') ?: [] as $file) unlink($file);
            if (is_dir($temporary . '/updates')) rmdir($temporary . '/updates');
            rmdir($temporary);
        }
    }
}
