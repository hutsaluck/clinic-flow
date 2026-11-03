<?php

declare(strict_types=1);

namespace App\Appointment\Infrastructure;

use App\Appointment\Application\AppointmentView;
use App\Appointment\Domain\Appointment;
use App\Appointment\Domain\AppointmentRepository;
use App\Appointment\Domain\AppointmentStatus;
use App\Appointment\Domain\ServiceSnapshot;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Money;
use App\Shared\Domain\TimeInterval;
use App\Shared\Domain\Uuid;
use App\Shared\Store;

final readonly class DoctrineAppointmentRepository implements AppointmentRepository
{
    public function __construct(private Store $store, private Clock $clock)
    {
    }

    public function lockDoctor(Uuid $clinic, Uuid $doctor): void
    {
        if (!$this->store->db->fetchOne('SELECT id FROM doctor_profiles WHERE clinic_id=? AND id=? FOR UPDATE', [(string) $clinic, (string) $doctor])) {
            throw new DomainViolation('NOT_FOUND', 'Лікаря не знайдено.');
        }
    }

    public function requireActivePatient(Uuid $clinic, Uuid $patient): void
    {
        $row = $this->store->one('patients', (string) $clinic, (string) $patient);
        if ($row['archived_at']) {
            throw new DomainViolation('PATIENT_ARCHIVED', 'Пацієнт в архіві.');
        }
    }

    public function service(Uuid $clinic, Uuid $service): ServiceSnapshot
    {
        $row = $this->store->one('services', (string) $clinic, (string) $service);

        return new ServiceSnapshot($row['name'], (int) $row['duration_minutes'], new Money((int) $row['price_minor'], $row['currency']));
    }

    public function get(Uuid $clinic, Uuid $id, bool $lock = false): Appointment
    {
        $row = $this->store->db->fetchAssociative('SELECT * FROM appointments WHERE clinic_id=? AND id=?'.($lock ? ' FOR UPDATE' : ''), [(string) $clinic, (string) $id]);
        if (!$row) {
            throw new DomainViolation('NOT_FOUND', 'Прийом не знайдено.');
        }

        return new Appointment(new Uuid($row['id']), new Uuid($row['clinic_id']), new Uuid($row['patient_id']), new Uuid($row['doctor_id']), new Uuid($row['service_id']), new ServiceSnapshot($row['service_name_snapshot'], (int) $row['duration_minutes_snapshot'], new Money((int) $row['price_minor_snapshot'], $row['currency_snapshot'])), new Uuid($row['created_by_user_id']), new TimeInterval(new \DateTimeImmutable($row['starts_at']), new \DateTimeImmutable($row['ends_at'])), AppointmentStatus::from($row['status']), (int) $row['version'], $row['cancellation_reason']);
    }

    public function save(Appointment $appointment, bool $new = false): void
    {
        $data = AppointmentView::from($appointment);
        if ($new) {
            $this->store->db->insert('appointments', $data);

            return;
        }
        $this->store->db->update('appointments', array_intersect_key($data, array_flip(['starts_at', 'ends_at', 'status', 'version', 'cancellation_reason'])) + ['updated_at' => $this->clock->now()->format('c')], ['clinic_id' => (string) $appointment->clinicId, 'id' => (string) $appointment->id]);
    }

    public function cancellationHours(Uuid $clinic): int
    {
        return (int) $this->store->db->fetchOne('SELECT cancellation_hours FROM clinics WHERE id=?',[(string) $clinic]);
    }
}
