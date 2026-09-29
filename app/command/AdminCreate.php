<?php
declare(strict_types=1);

namespace app\command;

use app\service\PasswordHasher;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

final class AdminCreate extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:admin:create')
            ->addArgument('username', Argument::REQUIRED, '管理员用户名')
            ->setDescription('从 CMS_ADMIN_PASSWORD 环境变量安全创建或更新管理员');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hasher = new PasswordHasher();
        $username = mb_substr(trim((string) $input->getArgument('username')), 0, 80);
        $password = (string) getenv('CMS_ADMIN_PASSWORD');
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/', $username)) {
            $output->writeln('[FAIL] 用户名需为 3-80 位字母、数字或 _ . @ -');
            return 1;
        }
        if (mb_strlen($password) < 12) {
            $output->writeln('[FAIL] 请通过 CMS_ADMIN_PASSWORD 提供至少 12 位密码');
            return 1;
        }

        Db::transaction(function () use ($username, $password, $hasher): void {
            $now = gmdate('Y-m-d H:i:s');
            $existing = Db::table('ffx_admins')->where('username', $username)->find();
            if ($existing === null) {
                $adminId = Db::table('ffx_admins')->insertGetId([
                    'username' => $username,
                    'password_hash' => $hasher->hash($password),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $adminId = (int) $existing['id'];
                Db::table('ffx_admins')->where('id', $adminId)->update([
                    'password_hash' => $hasher->hash($password),
                    'status' => 'active',
                    'updated_at' => $now,
                ]);
            }
            $roleId = (int) Db::table('ffx_roles')->where('role_key', 'super_admin')->value('id');
            $assigned = Db::table('ffx_admin_roles')->where('admin_id', $adminId)->where('role_id', $roleId)->find();
            if ($assigned === null) {
                Db::table('ffx_admin_roles')->insert(['admin_id' => $adminId, 'role_id' => $roleId]);
            }
        });

        $output->writeln('[OK] 管理员已创建或更新，并授予 super_admin；密码未写入日志');
        return 0;
    }
}
