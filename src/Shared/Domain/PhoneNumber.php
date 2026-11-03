<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class PhoneNumber implements \Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $value = preg_replace('/[\s()\-]/', '', trim($value)) ?? '';
        if (preg_match('/^0\d{9}$/', $value)) {
            $value = '+38'.$value;
        }
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
            throw new DomainViolation('INVALID_PHONE', 'Телефон має бути у форматі +380…');
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
