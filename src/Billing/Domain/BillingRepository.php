<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Shared\Domain\Uuid;

interface BillingRepository
{
    public function lockLedger(Uuid $clinic, Uuid $appointment): PaymentLedger;

    public function payment(Uuid $clinic, Uuid $id): Payment;

    public function isReversed(Uuid $payment): bool;

    public function save(Payment $payment): void;
}
