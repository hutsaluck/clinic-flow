<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency)
    {
        if ($minor < 0) {
            throw new DomainViolation('INVALID_AMOUNT', 'Сума не може бути від’ємною.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainViolation('INVALID_CURRENCY', 'Некоректна валюта.');
        }
    }

    public function add(self $other): self
    {
        $this->assertCurrency($other);
        if ($other->minor > PHP_INT_MAX - $this->minor) {
            throw new DomainViolation('INVALID_AMOUNT', 'Сума перевищує допустиме значення.');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function assertCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new DomainViolation('CURRENCY_MISMATCH', 'Валюта оплати має відповідати прийому.');
        }
    }
}
