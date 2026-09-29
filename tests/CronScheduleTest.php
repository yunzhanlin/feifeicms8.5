<?php
declare(strict_types=1);

use app\service\CronSchedule;
use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase
{
    private CronSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new CronSchedule();
    }

    public function testItNormalizesAllThreeScheduleModes(): void
    {
        self::assertSame(
            ['schedule_type' => 'interval', 'schedule_time' => '00:00', 'interval_minutes' => 15],
            $this->schedule->normalize('interval', '02:00', 0, 15)
        );
        self::assertSame(
            ['schedule_type' => 'hourly', 'schedule_time' => '00:05', 'interval_minutes' => null],
            $this->schedule->normalize('hourly', '02:00', 5, 30)
        );
        self::assertSame(
            ['schedule_type' => 'daily', 'schedule_time' => '02:30', 'interval_minutes' => null],
            $this->schedule->normalize('daily', '02:30', 0, 30)
        );
    }

    public function testIntervalScheduleKeepsCadenceAndSkipsMissedSlots(): void
    {
        $now = new DateTimeImmutable('2026-09-29 02:44:00', new DateTimeZone('UTC'));
        $scheduled = new DateTimeImmutable('2026-09-29 02:00:00', new DateTimeZone('UTC'));
        $next = $this->schedule->nextRun(['schedule_type' => 'interval', 'interval_minutes' => 15], $now, $scheduled);

        self::assertSame('2026-09-29 02:45:00', $next->format('Y-m-d H:i:s'));
    }

    public function testHourlyScheduleUsesConfiguredMinute(): void
    {
        $now = new DateTimeImmutable('2026-09-29 02:21:00', new DateTimeZone('UTC'));
        $next = $this->schedule->nextRun(['schedule_type' => 'hourly', 'schedule_time' => '00:20'], $now);

        self::assertSame('2026-09-29 03:20:00', $next->format('Y-m-d H:i:s'));
    }

    public function testDailyScheduleUsesShanghaiWallClock(): void
    {
        $now = new DateTimeImmutable('2026-09-29 02:00:00', new DateTimeZone('UTC'));
        $next = $this->schedule->nextRun(['schedule_type' => 'daily', 'schedule_time' => '11:30'], $now);

        self::assertSame('2026-09-29 03:30:00', $next->format('Y-m-d H:i:s'));
    }
}
