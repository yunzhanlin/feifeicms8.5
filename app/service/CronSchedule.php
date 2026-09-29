<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CronSchedule
{
    private const LOCAL_TIMEZONE = 'Asia/Shanghai';

    /**
     * @return array{schedule_type:string,schedule_time:string,interval_minutes:?int}
     */
    public function normalize(string $type, string $dailyTime, int $hourlyMinute, int $intervalMinutes): array
    {
        return match ($type) {
            'interval' => $this->normalizeInterval($intervalMinutes),
            'hourly' => $this->normalizeHourly($hourlyMinute),
            'daily' => $this->normalizeDaily($dailyTime),
            default => throw new InvalidArgumentException('执行周期无效'),
        };
    }

    /**
     * Calculate the first run after $now. When $scheduledAt is supplied, interval
     * tasks keep their original cadence and skip missed slots instead of flooding
     * the collection queue after downtime.
     */
    public function nextRun(array $task, DateTimeImmutable $now, ?DateTimeImmutable $scheduledAt = null): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone(self::LOCAL_TIMEZONE);
        $nowLocal = $now->setTimezone($local);
        $type = (string) ($task['schedule_type'] ?? 'daily');

        if ($type === 'interval') {
            $minutes = max(1, min(1440, (int) ($task['interval_minutes'] ?? 60)));
            $base = ($scheduledAt ?? $now)->setTimezone($local);
            $next = $base->modify('+' . $minutes . ' minutes');
            if ($next <= $nowLocal) {
                $secondsBehind = $nowLocal->getTimestamp() - $next->getTimestamp();
                $slots = intdiv($secondsBehind, $minutes * 60) + 1;
                $next = $next->modify('+' . ($slots * $minutes) . ' minutes');
            }

            return $next->setTimezone($utc);
        }

        $time = (string) ($task['schedule_time'] ?? '02:00');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '02:00';
        }

        if ($type === 'hourly') {
            $minute = (int) substr($time, 3, 2);
            $next = $nowLocal->setTime((int) $nowLocal->format('H'), $minute, 0);
            if ($next <= $nowLocal) {
                $next = $next->modify('+1 hour');
            }

            return $next->setTimezone($utc);
        }

        $next = new DateTimeImmutable($nowLocal->format('Y-m-d') . ' ' . $time . ':00', $local);
        if ($next <= $nowLocal) {
            $next = $next->modify('+1 day');
        }

        return $next->setTimezone($utc);
    }

    public function label(array $task): string
    {
        return match ((string) ($task['schedule_type'] ?? 'daily')) {
            'interval' => '每隔 ' . max(1, (int) ($task['interval_minutes'] ?? 60)) . ' 分钟',
            'hourly' => '每小时第 ' . (int) substr((string) ($task['schedule_time'] ?? '00:00'), 3, 2) . ' 分钟',
            default => '每天 ' . (string) ($task['schedule_time'] ?? '02:00'),
        };
    }

    /** @return array{schedule_type:string,schedule_time:string,interval_minutes:int} */
    private function normalizeInterval(int $minutes): array
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw new InvalidArgumentException('间隔分钟必须在 1 到 1440 之间');
        }

        return ['schedule_type' => 'interval', 'schedule_time' => '00:00', 'interval_minutes' => $minutes];
    }

    /** @return array{schedule_type:string,schedule_time:string,interval_minutes:null} */
    private function normalizeHourly(int $minute): array
    {
        if ($minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('每小时执行分钟必须在 0 到 59 之间');
        }

        return ['schedule_type' => 'hourly', 'schedule_time' => '00:' . str_pad((string) $minute, 2, '0', STR_PAD_LEFT), 'interval_minutes' => null];
    }

    /** @return array{schedule_type:string,schedule_time:string,interval_minutes:null} */
    private function normalizeDaily(string $time): array
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('每日执行时间无效');
        }

        return ['schedule_type' => 'daily', 'schedule_time' => $time, 'interval_minutes' => null];
    }
}
