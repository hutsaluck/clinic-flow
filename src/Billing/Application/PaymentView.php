<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Payment;

final class PaymentView
{
    /** @return array<string,scalar|null> */
    public static function from(Payment $p): array
    {
        return ['id' => (string) $p->id, 'clinic_id' => (string) $p->clinic, 'appointment_id' => (string) $p->appointment, 'amount_minor' => $p->amountMinor, 'currency' => $p->currency, 'method' => $p->method->value, 'type' => $p->isReversal() ? 'REVERSAL' : 'PAYMENT', 'reverses_payment_id' => $p->reverses ? (string) $p->reverses : null, 'created_by_user_id' => (string) $p->actor, 'comment' => $p->comment];
    }
}
