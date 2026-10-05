<?php
declare(strict_types=1);

use app\service\CollectionMetadataRepair;
use think\App;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
$app = new App();
$app->initialize();
$apply = in_array('--apply', $argv, true);
$result = $app->make(CollectionMetadataRepair::class)->run($app, $apply);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
