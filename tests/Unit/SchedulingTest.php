<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Appointment\Domain\Appointment;
use App\Appointment\Domain\AppointmentAction;
use App\Appointment\Domain\AppointmentStatus;
use App\Scheduling\Availability;
use App\Shared\Domain\DomainViolation;
use PHPUnit\Framework\TestCase;

final class SchedulingTest extends TestCase
{
    private function hours(int $day = 1, string $start = '09:00:00', string $end = '13:00:00'): array
    {
        return [['day_of_week' => $day, 'start_local_time' => $start, 'end_local_time' => $end]];
    }

    public function testBreaksBusyAndAdjacentHalfOpenSlots(): void
    {
        $slots = Availability::slots('2030-01-07', 'Europe/Kyiv', 30, $this->hours(), [['type' => 'UNAVAILABLE', 'starts_at' => '2030-01-07T11:00:00+02:00', 'ends_at' => '2030-01-07T11:30:00+02:00']], [['starts_at' => '2030-01-07T10:00:00+02:00', 'ends_at' => '2030-01-07T10:30:00+02:00']], new \DateTimeImmutable('2030-01-01'));
        $times = array_map(fn ($s) => substr($s['local_start'], 11, 5), $slots);
        self::assertContains('09:30', $times);
        self::assertContains('10:30', $times);
        self::assertContains('11:30', $times);
        self::assertNotContains('09:45', $times);
        self::assertNotContains('10:00', $times);
        self::assertNotContains('10:45', $times);
        self::assertNotContains('11:00', $times);
        self::assertNotContains('12:45', $times);
    }

    public function testAbsenceHasPriorityOverOverride(): void
    {
        $slots = Availability::slots('2030-01-07', 'UTC', 30, [], [['type' => 'AVAILABLE_OVERRIDE', 'starts_at' => '2030-01-07T09:00:00Z', 'ends_at' => '2030-01-07T10:00:00Z'], ['type' => 'UNAVAILABLE', 'starts_at' => '2030-01-07T09:30:00Z', 'ends_at' => '2030-01-07T10:00:00Z']], [], new \DateTimeImmutable('2030-01-01'));
        self::assertCount(1, $slots);
        self::assertSame('2030-01-07T09:00:00Z', $slots[0]['starts_at']);
    }

    public function testSpringDstNeverCreatesNonexistentTimes(): void
    {
        $slots = Availability::slots('2027-03-28', 'Europe/Kyiv', 30, $this->hours(7, '00:00:00', '06:00:00'), [], [], new \DateTimeImmutable('2027-01-01'));
        self::assertNotEmpty($slots);
        foreach ($slots as $s) {
            self::assertNotSame('03', substr($s['local_start'], 11, 2));
            self::assertSame(1800, strtotime($s['ends_at']) - strtotime($s['starts_at']));
        }
    }

    public function testFallDstRetainsDistinctOffsetsForRepeatedHour(): void
    {
        $slots = Availability::slots('2027-10-31', 'Europe/Kyiv', 30, $this->hours(7, '00:00:00', '06:00:00'), [], [], new \DateTimeImmutable('2027-01-01'));
        $repeated = array_values(array_filter($slots, fn ($s) => '03:00' === substr($s['local_start'], 11, 5)));
        self::assertCount(2, $repeated);
        self::assertNotSame($repeated[0]['starts_at'], $repeated[1]['starts_at']);
        self::assertNotSame(substr($repeated[0]['local_start'], -6), substr($repeated[1]['local_start'], -6));
    }

    public function testFinalStateIsImmutable(): void
    {
        $this->expectException(DomainViolation::class);
        Appointment::nextStatus(AppointmentStatus::Completed, AppointmentAction::Cancel, new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable());
    }

    public function testCannotCompleteFutureVisit(): void
    {
        $this->expectException(DomainViolation::class);
        Appointment::nextStatus(AppointmentStatus::Confirmed, AppointmentAction::Complete, new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable());
    }

    public function testAllowedTransition(): void
    {
        self::assertSame(AppointmentStatus::Completed, Appointment::nextStatus(AppointmentStatus::Confirmed, AppointmentAction::Complete, new \DateTimeImmutable('-1 minute'), new \DateTimeImmutable()));
    }
}
