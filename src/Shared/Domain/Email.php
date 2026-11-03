<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class Email implements \Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim($value));
        if (strlen($value) > 255 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new DomainViolation('INVALID_EMAIL', 'Некоректний email.');
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
