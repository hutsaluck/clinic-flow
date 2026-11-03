<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final readonly class TimeInterval
{
    public \DateTimeImmutable $start;
    public \DateTimeImmutable $end;

    public function __construct(\DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($start >= $end) {
            throw new DomainViolation('INVALID_INTERVAL', 'Кінець має бути пізніше початку.');
        }
        $utc = new \DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end = $end->setTimezone($utc);
    }

    public static function lasting(\DateTimeImmutable $start, int $minutes): self
    {
        if ($minutes < 1 || $minutes > 480) {
            throw new DomainViolation('INVALID_DURATION', 'Некоректна тривалість.');
        }

        return new self($start, $start->setTimestamp($start->getTimestamp() + $minutes * 60));
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $this->end >= $other->end;
    }
}
