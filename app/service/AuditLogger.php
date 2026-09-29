<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Session;

final class AuditLogger
{
    /** @param array<string, mixed>|null $before
     *  @param array<string, mixed>|null $after
     */
    public function record(string $action, string $targetType, int|string $targetId, ?array $before = null, ?array $after = null): void
    {
        Db::table('ffx_audit_logs')->insert([
            'admin_id' => (int) Session::get('admin_id', 0) ?: null,
            'action' => mb_substr($action, 0, 120),
            'target_type' => mb_substr($targetType, 0, 80),
            'target_id' => mb_substr((string) $targetId, 0, 120),
            'request_id' => bin2hex(random_bytes(12)),
            'ip_address' => request()->ip(),
            'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result' => 'success',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
