<?php

declare(strict_types=1);

namespace App\Appointment\Application;

use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Uuid;

final readonly class CreateAppointment
{
    public function __construct(public Uuid $clinic, public Uuid $actor, public Uuid $patient, public Uuid $doctor, public Uuid $service, public \DateTimeImmutable $start, public ?string $idempotencyKey = null)
    {
        if (null !== $idempotencyKey && (strlen($idempotencyKey) > 200 || '' === trim($idempotencyKey))) {
            throw new DomainViolation('INVALID_IDEMPOTENCY_KEY', 'Некоректний ключ запиту.');
        }
    }

    public function hash(): string
    {
        return hash('sha256', implode('|', [(string) $this->patient, (string) $this->doctor, (string) $this->service, $this->start->setTimezone(new \DateTimeZone('UTC'))->format('c')]));
    }
}
