<?php

declare(strict_types=1);

namespace App\Appointment\Domain;

use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Money;

final readonly class ServiceSnapshot
{
    public function __construct(public string $name, public int $durationMinutes, public Money $price)
    {
        if ('' === trim($name) || $durationMinutes < 5 || $durationMinutes > 480) {
            throw new DomainViolation('INVALID_SERVICE', 'Некоректні дані послуги.');
        }
    }
}
