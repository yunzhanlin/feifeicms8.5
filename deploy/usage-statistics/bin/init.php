<?php
declare(strict_types=1);
umask(0077);
$directory = getenv('STATS_DATA') ?: '/data';
$url = getenv('STATS_PUBLIC_URL') ?: 'https://tj.shankan.cc';
$parts = parse_url($url);
if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https','http'], true) || empty($parts['host'])
    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
    || !in_array($parts['path'] ?? '', ['', '/'], true)) throw new RuntimeException('STATS_PUBLIC_URL must be an HTTP(S) origin');
if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create data directory');
if (!is_dir($directory . '/sessions') && !mkdir($directory . '/sessions', 0700)) throw new RuntimeException('Cannot create sessions');
$file = $directory . '/config.json';
if (is_file($file)) {
    $config = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !isset($config['password_hash'], $config['secret'], $config['public_url'])) throw new RuntimeException('Existing config is invalid; refusing overwrite');
    if (rtrim($config['public_url'], '/') !== rtrim($url, '/')) throw new RuntimeException('Existing public URL differs; update config explicitly after backup');
    echo "Existing credentials and data preserved.\n";
    exit(0);
}
$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$config = ['username' => 'admin', 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'secret' => bin2hex(random_bytes(32)), 'public_url' => rtrim($url, '/')];
$handle = fopen($file, 'x');
if ($handle === false) throw new RuntimeException('Cannot create private configuration');
fwrite($handle, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
fclose($handle);
file_put_contents($directory . '/admin-login.txt', "管理地址：" . rtrim($url, '/') . "/\n账号：admin\n密码：" . $password . "\n请妥善保管，不要提交到代码仓库。\n", LOCK_EX);
echo "Initialized. Credentials: data/admin-login.txt (mode 0600).\n";
