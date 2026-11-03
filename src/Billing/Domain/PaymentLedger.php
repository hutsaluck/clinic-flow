<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Money;
use App\Shared\Domain\Uuid;

final readonly class PaymentLedger
{
    public function __construct(public Uuid $clinic, public Uuid $appointment, public Money $cost, public int $paidMinor)
    {
        if ($paidMinor < 0 || $paidMinor > $cost->minor) {
            throw new DomainViolation('INVALID_AMOUNT', 'Некоректний баланс прийому.');
        }
    }

    public function record(Uuid $actor, Money $amount, PaymentMethod $method, ?string $comment = null): Payment
    {
        $this->cost->assertCurrency($amount);
        if (0 === $amount->minor) {
            throw new DomainViolation('INVALID_AMOUNT', 'Сума оплати має бути додатною.');
        }
        if ($amount->minor > $this->cost->minor - $this->paidMinor) {
            throw new DomainViolation('OVERPAYMENT', 'Сума перевищує залишок до сплати.');
        }

        return new Payment(Uuid::generate(), $this->clinic, $this->appointment, $amount->minor, $amount->currency, $method, $actor, null, $comment);
    }

    public function reverse(Uuid $actor, Payment $original, bool $alreadyReversed, ?string $comment = null): Payment
    {
        if (!$original->clinic->equals($this->clinic) || !$original->appointment->equals($this->appointment)) {
            throw new DomainViolation('NOT_FOUND', 'Оплату не знайдено.');
        }
        if ($original->isReversal() || $alreadyReversed) {
            throw new DomainViolation('ALREADY_REVERSED', 'Оплату вже сторновано або вона є сторнуванням.');
        }
        if ($original->amountMinor > $this->paidMinor) {
            throw new DomainViolation('INVALID_AMOUNT', 'Сума сторнування перевищує баланс.');
        }

        return new Payment(Uuid::generate(), $this->clinic, $this->appointment, -$original->amountMinor, $original->currency, $original->method, $actor, $original->id, $comment);
    }

    public function outstanding(): Money
    {
        return new Money($this->cost->minor - $this->paidMinor,$this->cost->currency);
    }
}
