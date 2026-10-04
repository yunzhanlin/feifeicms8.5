<?php
declare(strict_types=1);
namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\PhpCliLauncher;
use app\service\ProgramUpdater;
use app\service\ReleaseCatalog;
use think\Response;

/** Only super admins reach this controller (AdminAuthorization). */
final class Updater extends BaseController
{
    public function __construct(\think\App $app, private readonly ReleaseCatalog $catalog, private readonly ProgramUpdater $updater,
        private readonly PhpCliLauncher $cli,
        private readonly CsrfToken $csrf, private readonly AuditLogger $audit) { parent::__construct($app); }

    public function status(): Response
    {
        try {
            $release = $this->catalog->latest();
            return json(['ok' => true, 'release' => $release, 'job' => $this->updater->state(), 'current' => (string) config('feifei.version_id')]);
        } catch (\Throwable $e) {
            return json(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 160), 'job' => $this->updater->state()], 503);
        }
    }

    public function start(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'error' => '请求已过期'], 419);
        $started = false;
        try {
            $release = $this->catalog->latest(true);
            if (!$release['available'] || !hash_equals($release['tag'], (string) $this->request->post('tag', ''))) throw new \RuntimeException('发布版本已变化，请刷新后重试');
            $php = $this->cli->executable();
            $job = $this->updater->queue($release);
            $log = $this->updater->directory() . '/worker-' . $job['id'] . '.log';
            $this->cli->spawn([$php, root_path() . 'think', 'feifei:update:apply', $job['id']], root_path(), $log);
            $started = true;
            try { $this->audit->record('program.update.started', 'release', $release['tag'], null, ['job' => $job['id']]); } catch (\Throwable) { /* worker already owns the job */ }
            return json(['ok' => true, 'job' => $job]);
        } catch (\Throwable $e) {
            if (!$started && isset($job['id'])) $this->updater->failQueued((string) $job['id'], '后台更新进程未能启动');
            return json(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 180)], 422);
        }
    }
}
