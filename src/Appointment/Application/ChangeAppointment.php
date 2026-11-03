<?php

declare(strict_types=1);

namespace App\Appointment\Application;

use App\Appointment\Domain\AppointmentAction;
use App\Shared\Domain\Uuid;

final readonly class ChangeAppointment
{
    public function __construct(public Uuid $clinic, public Uuid $actor, public Uuid $id, public AppointmentAction $action, public int $version, public ?\DateTimeImmutable $start = null, public ?string $reason = null)
    {
    }
}
