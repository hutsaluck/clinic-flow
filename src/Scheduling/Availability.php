<?php

declare(strict_types=1);

namespace App\Scheduling;

use App\Shared\ApiError;
use App\Shared\Input;
use App\Shared\Store;

final class Availability implements \App\Appointment\Application\BookingAvailability
{
    public function __construct(private Store $s, private \App\Shared\Domain\Clock $clock)
    {
    }

    public static function slots(string $date, string $timezone, int $duration, array $hours, array $exceptions, array $busy, \DateTimeImmutable $now): array
    {
        return Domain\SlotCalculator::calculate($date, $timezone, $duration, $hours, $exceptions, $busy, $now);
    }

    public function assertAvailable(\App\Appointment\Domain\Appointment $appointment): void
    {
        $this->assertSlot((string) $appointment->clinicId, \App\Appointment\Application\AppointmentView::from($appointment), $appointment->interval()->start, (string) $appointment->id);
    }

    public function get(string $clinic, string $doctor, string $service, string $date, ?string $ignore = null, ?int $duration = null, bool $includeBusy = true): array
    {
        Input::date($date);
        $d = $this->s->one('doctor_profiles', $clinic, $doctor);
        $sv = $this->s->one('services', $clinic, $service);
        $c = $this->s->db->fetchAssociative('SELECT * FROM clinics WHERE id=?', [$clinic]);
        if (!$d['is_active'] || !$sv['is_active'] || !$this->s->db->fetchOne('SELECT 1 FROM doctor_services WHERE clinic_id=? AND doctor_id=? AND service_id=?', [$clinic, $doctor, $service])) {
            throw new ApiError(422, 'SERVICE_NOT_AVAILABLE', 'Лікар не надає цю послугу або профіль неактивний.');
        }
        $now = $this->clock->now();
        $day = new \DateTimeImmutable($date, new \DateTimeZone($c['timezone']));
        if ($day->format('Y-m-d') < $now->setTimezone($day->getTimezone())->format('Y-m-d') || $day > $now->modify('+'.$c['booking_horizon_days'].' days')) {
            throw new ApiError(422, 'BOOKING_HORIZON', 'Дата поза горизонтом бронювання.');
        }
        $hours = $this->s->db->fetchAllAssociative('SELECT * FROM doctor_working_hours WHERE clinic_id=? AND doctor_id=?', [$clinic, $doctor]);
        $exceptions = $this->s->db->fetchAllAssociative('SELECT * FROM doctor_schedule_exceptions WHERE clinic_id=? AND doctor_id=? AND starts_at<? AND ends_at>?', [$clinic, $doctor, $day->modify('+1 day')->format('c'), $day->format('c')]);
        $busy = $includeBusy ? $this->s->db->fetchAllAssociative("SELECT starts_at,ends_at FROM appointments WHERE clinic_id=? AND doctor_id=? AND status IN ('SCHEDULED','CONFIRMED') AND starts_at<? AND ends_at>? AND id<>?", [$clinic, $doctor, $day->modify('+1 day')->format('c'), $day->format('c'), $ignore ?? '00000000-0000-4000-8000-000000000000']) : [];

        return ['date' => $date, 'timezone' => $c['timezone'], 'doctor_id' => $doctor, 'service_id' => $service, 'slots' => self::slots($date, $c['timezone'], $duration ?? (int) $sv['duration_minutes'], $hours, $exceptions, $busy, $now)];
    }

    public function assertSlot(string $clinic, array $appointment, \DateTimeImmutable $start, ?string $ignore = null): void
    {
        $tz = $this->s->db->fetchOne('SELECT timezone FROM clinics WHERE id=?', [$clinic]);
        // Occupancy is enforced independently by the database exclusion constraint.
        $slots = $this->get($clinic, $appointment['doctor_id'], $appointment['service_id'], $start->setTimezone(new \DateTimeZone($tz))->format('Y-m-d'), $ignore, (int) $appointment['duration_minutes_snapshot'], false)['slots'];
        foreach ($slots as $slot) {
            if (strtotime($slot['starts_at']) === $start->getTimestamp()) {
                return;
            }
        }
        throw new ApiError(422, 'SLOT_UNAVAILABLE', 'Час не відповідає робочому графіку або правилам бронювання.');
    }

    public function conflicts(string $clinic, string $doctor): array
    {
        $appointments = $this->s->db->fetchAllAssociative("SELECT * FROM appointments WHERE clinic_id=? AND doctor_id=? AND starts_at>now() AND status IN ('SCHEDULED','CONFIRMED')", [$clinic, $doctor]);
        $conflicts = [];
        foreach ($appointments as $a) {
            try {
                $this->assertSlot($clinic, $a, new \DateTimeImmutable($a['starts_at']), $a['id']);
            } catch (ApiError) {
                $conflicts[] = $a['id'];
            }
        }

        return $conflicts;
    }
}
