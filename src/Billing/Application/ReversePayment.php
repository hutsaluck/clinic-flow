<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Shared\Domain\Uuid;

final readonly class ReversePayment
{
    public function __construct(public Uuid $clinic, public Uuid $actor, public Uuid $payment, public ?string $comment = null)
    {
    }
}
