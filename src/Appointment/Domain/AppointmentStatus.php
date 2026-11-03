<?php

declare(strict_types=1);

namespace App\Appointment\Domain;

enum AppointmentStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Confirmed = 'CONFIRMED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public function isFinal(): bool
    {
        return !in_array($this, [self::Scheduled, self::Confirmed], true);
    }
}
