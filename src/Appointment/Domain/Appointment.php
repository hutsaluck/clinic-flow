<?php

declare(strict_types=1);

namespace App\Appointment\Domain;

use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\TimeInterval;
use App\Shared\Domain\Uuid;

final class Appointment
{
    public function __construct(
        public readonly Uuid $id, public readonly Uuid $clinicId, public readonly Uuid $patientId,
        public readonly Uuid $doctorId, public readonly Uuid $serviceId, public readonly ServiceSnapshot $service,
        public readonly Uuid $createdBy, private TimeInterval $interval, private AppointmentStatus $status = AppointmentStatus::Scheduled,
        private int $version = 1, private ?string $cancellationReason = null,
    ) {
        if ($version < 1 || $interval->end->getTimestamp() - $interval->start->getTimestamp() !== $service->durationMinutes * 60) {
            throw new DomainViolation('INVALID_APPOINTMENT', 'Некоректна версія або тривалість прийому.');
        }
    }

    public function status(): AppointmentStatus
    {
        return $this->status;
    }

    public function interval(): TimeInterval
    {
        return $this->interval;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function cancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function change(AppointmentAction $action, int $expectedVersion, \DateTimeImmutable $now, ?\DateTimeImmutable $newStart = null, ?string $reason = null, int $cancellationHours = 0): void
    {
        if ($this->version !== $expectedVersion) {
            throw new DomainViolation('VERSION_CONFLICT', 'Запис змінено. Оновіть дані.');
        }
        $status = self::nextStatus($this->status, $action, $this->interval->start, $now);
        if (AppointmentAction::Reschedule === $action) {
            if (!$newStart || $newStart <= $now) {
                throw new DomainViolation('SLOT_UNAVAILABLE', 'Початок прийому має бути в майбутньому.');
            }
            $this->interval = TimeInterval::lasting($newStart, $this->service->durationMinutes);
        }
        if (AppointmentAction::Cancel === $action) {
            if ($cancellationHours > 0 && $this->interval->start->getTimestamp() < $now->getTimestamp() + $cancellationHours * 3600) {
                throw new DomainViolation('CANCELLATION_POLICY', 'Минув дозволений термін скасування.');
            }
            $this->cancellationReason = $reason;
        }
        $this->status = $status;
        ++$this->version;
    }

    public static function nextStatus(AppointmentStatus $status, AppointmentAction $action, \DateTimeImmutable $start, \DateTimeImmutable $now): AppointmentStatus
    {
        if ($status->isFinal()) {
            throw new DomainViolation('INVALID_TRANSITION', 'Фінальний запис змінювати не можна.');
        }
        if (AppointmentAction::Confirm === $action && AppointmentStatus::Scheduled !== $status) {
            throw new DomainViolation('INVALID_TRANSITION', 'Запис уже підтверджений.');
        }
        if (in_array($action, [AppointmentAction::Complete, AppointmentAction::NoShow], true) && $start > $now) {
            throw new DomainViolation('VISIT_NOT_STARTED', 'Час прийому ще не настав.');
        }

        return match ($action) {
            AppointmentAction::Confirm => AppointmentStatus::Confirmed,AppointmentAction::Cancel => AppointmentStatus::Cancelled,AppointmentAction::Complete => AppointmentStatus::Completed,AppointmentAction::NoShow => AppointmentStatus::NoShow,AppointmentAction::Reschedule => $status
        };
    }
}
