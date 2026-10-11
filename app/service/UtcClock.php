<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

/** Stored program DATETIME values are UTC; datetime-local inputs are local. */
final class UtcClock
{
    public static function timestamp(?string $value): int
    {
        if ($value === null || trim($value) === '') return 0;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) ? $date->getTimestamp() : 0;
    }

    public static function fromLocalInput(string $value): ?string
    {
        if (trim($value) === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new DateTimeZone(date_default_timezone_get()))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone(date_default_timezone_get()));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new \think\exception\HttpException(422, '日期时间无效');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function localInput(?string $value): string
    {
        $time = self::timestamp($value);
        return $time > 0 ? date('Y-m-d\TH:i', $time) : '';
    }
}
