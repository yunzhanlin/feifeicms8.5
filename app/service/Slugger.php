<?php
declare(strict_types=1);

namespace app\service;

final class Slugger
{
    public function make(string $value, string $prefix): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            $value = $prefix . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        }
        return mb_substr($value, 0, 220);
    }
}
