<?php

declare(strict_types=1);

namespace App\Appointment\Application;

use App\Appointment\Domain\Appointment;

interface BookingAvailability
{
    public function assertAvailable(Appointment $appointment): void;
}
