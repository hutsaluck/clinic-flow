<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Uuid;

final readonly class Payment
{
    public function __construct(public Uuid $id, public Uuid $clinic, public Uuid $appointment, public int $amountMinor, public string $currency, public PaymentMethod $method, public Uuid $actor, public ?Uuid $reverses = null, public ?string $comment = null)
    {
        if ((null === $reverses && $amountMinor <= 0) || (null !== $reverses && $amountMinor >= 0)) {
            throw new DomainViolation('INVALID_AMOUNT', 'Некоректна сума фінансової операції.');
        }
    }

    public function isReversal(): bool
    {
        return null !== $this->reverses;
    }
}
