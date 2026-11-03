<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Shared\Domain\DomainViolation;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case CardTerminal = 'CARD_TERMINAL';
    case BankTransfer = 'BANK_TRANSFER';
    case Other = 'OTHER';

    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw new DomainViolation('INVALID_METHOD', 'Некоректний метод оплати.');
    }
}
