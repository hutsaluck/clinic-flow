<?php

declare(strict_types=1);

namespace App\Shared;

final class Input
{
    public static function text(array $d, string $key, int $max = 255, bool $required = true): ?string
    {
        $v = $d[$key] ?? null;
        if (null === $v && !$required) {
            return null;
        }
        if (!is_string($v) || ($required && '' === trim($v)) || mb_strlen($v) > $max) {
            throw new ApiError(422, 'VALIDATION_ERROR', "Некоректне поле: $key", ['field' => $key]);
        }

        return trim($v);
    }

    public static function integer(array $d, string $key, int $min, int $max): int
    {
        $v = $d[$key] ?? null;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new ApiError(422, 'VALIDATION_ERROR', "Некоректне поле: $key");
        }

        return $v;
    }

    public static function instant(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new ApiError(422, 'INVALID_DATETIME', 'Потрібна дата ISO 8601 із часовим поясом.');
        }
        try {
            $d = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new ApiError(422, 'INVALID_DATETIME', 'Некоректна дата.');
        }
        if (false !== \DateTimeImmutable::getLastErrors()) {
            throw new ApiError(422, 'INVALID_DATETIME', 'Некоректна дата.');
        }

        return $d->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function date(mixed $v): string
    {
        if (!is_string($v)) {
            throw new ApiError(422, 'INVALID_DATE', 'Некоректна дата.');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new ApiError(422, 'INVALID_DATE', 'Некоректна дата.');
        }

        return $v;
    }

    public static function uuid(mixed $v): string
    {
        if (!is_string($v) || !\Symfony\Component\Uid\Uuid::isValid($v)) {
            throw new ApiError(422, 'INVALID_ID', 'Некоректний ідентифікатор.');
        }

        return $v;
    }
}
