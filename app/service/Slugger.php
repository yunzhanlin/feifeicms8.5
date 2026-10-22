<?php
declare(strict_types=1);

namespace app\service;

final class Slugger
{
    /** Chinese titles use concatenated, tone-free pinyin; Latin titles stay readable. */
    public function fromTitle(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/\p{Han}/u', $value)) $value = \Overtrue\Pinyin\Pinyin::permalink($value, '');
        if (preg_match('/[^\x00-\x7F]/', $value) && class_exists(\Transliterator::class)) {
            $value = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($value) ?? $value;
        }
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $value));
        return substr($slug !== '' ? $slug : 'video-' . substr(hash('sha256', $value), 0, 16), 0, 200);
    }

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
