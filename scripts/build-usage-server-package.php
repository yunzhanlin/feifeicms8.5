<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$target = $argv[1] ?? $root . '/feifeicms-usage-server.zip';
$version = (require $root . '/config/version.php')['version'];
$paths = ['.dockerignore', '.gitignore', 'Dockerfile', 'README.md', 'apache.conf', 'compose.yml',
    'entrypoint.sh', 'install.sh', 'bin/init.php', 'public/index.php', 'src/Statistics.php'];
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create server package');
$files = [];
foreach ($paths as $path) {
    $source = $root . '/deploy/usage-statistics/' . $path;
    if (!is_file($source) || is_link($source)) throw new RuntimeException('Missing receiver source: ' . $path);
    $files[$path] = hash_file('sha256', $source);
    $zip->addFile($source, $path);
    $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, (str_ends_with($path, '.sh') ? 0100755 : 0100644) << 16);
}
ksort($files);
$zip->addFromString('manifest.json', json_encode(['version'=>$version, 'package_type'=>'usage-server', 'files'=>$files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$zip->close();
file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n");
echo '[OK] receiver package: ' . count($files) . " source files; no runtime data or credentials\n";
