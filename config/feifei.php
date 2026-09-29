<?php

$release = require __DIR__ . '/version.php';

return [
    'version' => $release['display'],
    'version_id' => $release['version'],
    'release_channel' => $release['channel'],
    'source_reference' => 'FeiFeiCMS 4.3.201206',
    'site_name' => env('SITE_NAME', '飞飞影视'),
    'site_description' => env('SITE_DESCRIPTION', '发现值得观看的影视内容'),
    'page_size' => (int) env('SITE_PAGE_SIZE', 24),
    'search' => [
        'driver' => env('SEARCH_DRIVER', 'mysql'),
        'fallback' => env('SEARCH_FALLBACK', 'mysql'),
        'meilisearch' => [
            'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
            'key' => env('MEILISEARCH_KEY', ''),
            'index' => env('MEILISEARCH_INDEX', 'feifeicms_media'),
        ],
    ],
    'compatibility' => [
        'legacy_query_routes' => env('ENABLE_LEGACY_ROUTES', true),
        'legacy_api' => env('ENABLE_LEGACY_API', true),
        'template_mode' => env('TEMPLATE_MODE', 'modern'),
    ],
];
