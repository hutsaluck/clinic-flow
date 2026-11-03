<?php

declare(strict_types=1);

namespace App\Appointment\Application;

use App\Appointment\Domain\Appointment;
use App\Shared\Domain\Uuid;

interface AppointmentEvents
{
    /** @param array<string,scalar|null> $before */
    public function record(Appointment $appointment, Uuid $actor, string $event, array $before): void;
}
