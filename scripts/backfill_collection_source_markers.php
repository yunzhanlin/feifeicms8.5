<?php
declare(strict_types=1);

use app\service\CollectionSourceIdentity;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App();
$app->initialize();
$identity = $app->make(CollectionSourceIdentity::class);
$mediaIds = Db::table('ffx_external_refs')->where('entity_type', 'media')->distinct(true)->column('entity_id');
$updated = 0;
foreach ($mediaIds as $mediaId) {
    $row = Db::table('ffx_media')->where('id', (int) $mediaId)->field('id,metadata,updated_at')->find();
    if ($row === null) continue;
    $metadata = is_string($row['metadata'] ?? null) ? json_decode((string) $row['metadata'], true) : ($row['metadata'] ?? []);
    $metadata = is_array($metadata) ? $metadata : [];
    $sourceRef = $identity->forMedia((int) $mediaId, (string) ($metadata['source_ref'] ?? ''));
    if ($sourceRef === '' || (string) ($metadata['source_ref'] ?? '') === $sourceRef) continue;
    $metadata['source_ref'] = $sourceRef;
    if (empty($metadata['inputer'])) {
        $sourceId = (int) Db::table('ffx_external_refs')->where('entity_type', 'media')->where('entity_id', (int) $mediaId)->order('id')->value('source_id');
        if ($sourceId > 0) $metadata['inputer'] = 'json_' . $sourceId;
    }
    Db::table('ffx_media')->where('id', (int) $mediaId)->update([
        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        // Metadata repair must not make every historical title look newly updated.
        'updated_at' => $row['updated_at'],
    ]);
    $updated++;
}
echo json_encode(['scanned' => count($mediaIds), 'updated' => $updated], JSON_UNESCAPED_UNICODE) . PHP_EOL;
