<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CronSchedule;
use app\service\CsrfToken;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Crontab extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly CronSchedule $schedule) { parent::__construct($app); }

    public function index(): Response
    {
        $tasks = Db::table('ffx_cron_tasks')->alias('c')->leftJoin(['ffx_collection_sources' => 's'], 's.id=c.source_id')->field('c.*,s.name AS source_name')->order('c.id', 'desc')->select()->toArray();
        foreach ($tasks as &$task) {
            $task['schedule_label'] = $this->schedule->label($task);
            $task['last_run_local'] = $this->localTime($task['last_run_at'] ?? null);
            $task['next_run_local'] = $this->localTime($task['next_run_at'] ?? null);
        }
        unset($task);

        return view('/admin/crontab/index', [
            'tasks' => $tasks,
            'sources' => Db::table('ffx_collection_sources')->where('status', 'enabled')->order('name')->select()->toArray(),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $name = mb_substr(trim((string) $this->request->post('name', '')), 0, 255);
        $sourceId = max(1, (int) $this->request->post('source_id', 0));
        if ($name === '') throw new HttpException(422, '任务名不能为空');
        try {
            $schedule = $this->schedule->normalize(
                trim((string) $this->request->post('schedule_type', 'daily')),
                trim((string) $this->request->post('schedule_time', '02:00')),
                (int) $this->request->post('hourly_minute', 0),
                (int) $this->request->post('interval_minutes', 30),
            );
        } catch (InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        if (Db::table('ffx_collection_sources')->where('id', $sourceId)->where('status', 'enabled')->count() < 1) throw new HttpException(422, '资源库不存在或未启用');
        $scope = (string) $this->request->post('scope', 'today');
        $payload = match ($scope) {
            'week' => ['page' => 1, 'limit' => 20, 'h' => 168, 'page_end' => 100000, 'max_pages_per_run' => 5],
            'all' => ['page' => 1, 'limit' => 20, 'page_end' => 100000, 'max_pages_per_run' => 5],
            default => ['page' => 1, 'limit' => 20, 'h' => 24, 'page_end' => 100000, 'max_pages_per_run' => 5],
        };
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $next = $this->schedule->nextRun($schedule, $now);
        $id = Db::table('ffx_cron_tasks')->insertGetId([
            'name' => $name, 'task_type' => 'collection', 'source_id' => $sourceId,
            'schedule_type' => $schedule['schedule_type'], 'schedule_time' => $schedule['schedule_time'], 'interval_minutes' => $schedule['interval_minutes'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'enabled',
            'next_run_at' => $next->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->record('crontab.create', 'crontab', $id, null, ['name' => $name, 'source_id' => $sourceId, 'schedule' => $schedule, 'scope' => $scope]);
        return redirect('/admin/crontab');
    }

    public function toggle(int $id): Response
    {
        $this->guardCsrf();
        $task = $this->task($id); $status = $task['status'] === 'enabled' ? 'disabled' : 'enabled';
        $updates = ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($status === 'enabled') {
            $updates['next_run_at'] = $this->schedule->nextRun($task, new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
        Db::table('ffx_cron_tasks')->where('id', $id)->update($updates);
        $this->audit->record('crontab.toggle', 'crontab', $id, ['status' => $task['status']], ['status' => $status]);
        return redirect('/admin/crontab');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf(); $task = $this->task($id); Db::table('ffx_cron_tasks')->where('id', $id)->delete();
        $this->audit->record('crontab.delete', 'crontab', $id, $task, null); return redirect('/admin/crontab');
    }

    private function task(int $id): array { $row = Db::table('ffx_cron_tasks')->where('id', $id)->find(); if ($row === null) throw new HttpException(404, '计划任务不存在'); return $row; }
    private function localTime(mixed $value): string { if (!is_string($value) || $value === '') return '-'; return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'); }
    private function guardCsrf(): void { if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期'); }
}
