<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/** Minimal SMTP client used by the classic backend's mail-test action. */
final class SmtpMailer
{
    /** @param array<string, mixed> $config */
    public function sendTest(array $config, string $recipient): void
    {
        $host = trim((string) ($config['email_host'] ?? ''));
        $port = max(1, min(65535, (int) ($config['email_port'] ?? 465)));
        $secure = (string) ($config['email_secure'] ?? 'ssl');
        $username = trim((string) ($config['email_username'] ?? ''));
        $password = (string) ($config['email_password'] ?? '');
        $fromName = trim((string) ($config['from_name'] ?? 'FeiFeiCMS'));
        if ($host === '' || preg_match('/[\s\x00-\x1f]/', $host) === 1) throw new RuntimeException('SMTP 服务器地址无效');
        if (!in_array($secure, ['ssl', 'tls', 'none'], true)) throw new RuntimeException('SMTP 加密方式无效');
        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP 账号必须是有效发件邮箱');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('测试收件邮箱无效');

        $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
            'allow_self_signed' => false, 'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new RuntimeException('连接 SMTP 服务器失败：' . ($error !== '' ? $error : '未知错误'));
        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, [220]);
            $hello = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
            $this->command($socket, 'EHLO ' . $hello, [250]);
            if ($secure === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS 握手失败');
                $this->command($socket, 'EHLO ' . $hello, [250]);
            }
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $username . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $subject = '=?UTF-8?B?' . base64_encode('FeiFeiCMS 邮件配置测试') . '?=';
            $encodedName = '=?UTF-8?B?' . base64_encode($fromName !== '' ? $fromName : 'FeiFeiCMS') . '?=';
            $body = "这是一封来自 FeiFeiCMS 管理后台的 SMTP 测试邮件。\r\n发送时间：" . gmdate('Y-m-d H:i:s') . " UTC\r\n";
            $headers = [
                'Date: ' . gmdate(DATE_RFC2822), 'From: ' . $encodedName . ' <' . $username . '>',
                'To: <' . $recipient . '>', 'Subject: ' . $subject, 'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $hello . '>',
            ];
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/(?m)^\./', '..', $body) . "\r\n.";
            $this->command($socket, $payload, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<int> $codes */
    private function command($socket, string $command, array $codes): void
    {
        if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('SMTP 写入失败');
        $this->expect($socket, $codes);
    }

    /** @param resource $socket @param list<int> $codes */
    private function expect($socket, array $codes): void
    {
        $response = '';
        while (($line = fgets($socket, 4096)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
            if (strlen($response) > 65536) break;
        }
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) throw new RuntimeException('SMTP 响应超时');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $safe = trim((string) preg_replace('/[\r\n]+/', ' ', $response));
            throw new RuntimeException('SMTP 返回错误 ' . $code . '：' . mb_substr($safe, 0, 300));
        }
    }
}
