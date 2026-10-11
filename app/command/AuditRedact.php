<?php
declare(strict_types=1);
namespace app\command;

use think\console\{Command,Input,Output};
use think\console\input\Option;

final class AuditRedact extends Command
{
    protected function configure(): void
    {
        $this->setName('feifei:audit:redact')->addOption('apply',null,Option::VALUE_NONE,'脱敏历史审计日志；不带此参数只统计，不输出敏感值')->setDescription('清理旧版本审计日志中的密码、密钥和 Cookie');
    }
    protected function execute(Input $input,Output $output): int
    {
        $output->writeln(json_encode((new \app\service\AuditLogRedactor())->run((bool)$input->getOption('apply')),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return 0;
    }
}
