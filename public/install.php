<?php
declare(strict_types=1);

// Keep the classic FeiFeiCMS /install.php entry while routing all work
// through the modern installer controller.
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$_SERVER['REQUEST_URI'] = '/install' . ($query !== '' ? '?' . $query : '');
$_SERVER['PATH_INFO'] = '/install';

require __DIR__ . '/index.php';
