<?php

declare(strict_types=1);

namespace App\Appointment\Domain;

enum AppointmentAction: string
{
    case Confirm = 'confirm';
    case Cancel = 'cancel';
    case Complete = 'complete';
    case NoShow = 'mark-no-show';
    case Reschedule = 'reschedule';

    public function eventType(): string
    {
        return strtoupper(str_replace('-', '_', $this->value));
    }
}
