<?php

declare(strict_types=1);

namespace App\Appointment\Domain;

use App\Shared\Domain\Uuid;

interface AppointmentRepository
{
    public function lockDoctor(Uuid $clinic, Uuid $doctor): void;

    public function requireActivePatient(Uuid $clinic, Uuid $patient): void;

    public function service(Uuid $clinic, Uuid $service): ServiceSnapshot;

    public function get(Uuid $clinic, Uuid $id, bool $lock = false): Appointment;

    public function save(Appointment $appointment, bool $new = false): void;

    public function cancellationHours(Uuid $clinic): int;
}
