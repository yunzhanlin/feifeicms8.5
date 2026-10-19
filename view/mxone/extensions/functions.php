<?php
declare(strict_types=1);

// Theme-local display helpers. Keep authentication, billing and database writes in app/.
return [
    'duration' => static fn (mixed $minutes): string => (int) $minutes > 0 ? (int) $minutes . '分钟' : '暂无',
];
