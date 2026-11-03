<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\PaymentMethod;
use App\Shared\Domain\Money;
use App\Shared\Domain\Uuid;

final readonly class RecordPayment
{
    public function __construct(public Uuid $clinic, public Uuid $actor, public Uuid $appointment, public Money $amount, public PaymentMethod $method, public ?string $comment = null)
    {
    }
}
