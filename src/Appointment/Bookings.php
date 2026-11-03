<?php

declare(strict_types=1);

namespace App\Appointment;

use App\Appointment\Application\AppointmentEvents;
use App\Appointment\Application\AppointmentView;
use App\Appointment\Application\BookingAvailability;
use App\Appointment\Application\ChangeAppointment;
use App\Appointment\Application\CreateAppointment;
use App\Appointment\Application\IdempotencyStore;
use App\Appointment\Domain\Appointment;
use App\Appointment\Domain\AppointmentAction;
use App\Appointment\Domain\AppointmentRepository;
use App\Shared\Application\TransactionManager;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TimeInterval;
use App\Shared\Domain\Uuid;

final readonly class Bookings
{
    public function __construct(private AppointmentRepository $appointments, private BookingAvailability $availability, private TransactionManager $transactions, private IdempotencyStore $idempotency, private AppointmentEvents $events, private Clock $clock)
    {
    }

    /** @return array<string,mixed> */
    public function create(CreateAppointment $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $key = $command->idempotencyKey;
            if (null !== $key) {
                $this->idempotency->lock($command->clinic, $command->actor, $key);
                $cached = $this->idempotency->find($command->clinic, $command->actor, $key, $command->hash());
                if (null !== $cached) {
                    return $cached;
                }
            }
            if ($command->start <= $this->clock->now()) {
                throw new \App\Shared\Domain\DomainViolation('SLOT_UNAVAILABLE', 'Початок прийому має бути в майбутньому.');
            }
            $this->appointments->lockDoctor($command->clinic, $command->doctor);
            $this->appointments->requireActivePatient($command->clinic, $command->patient);
            $service = $this->appointments->service($command->clinic, $command->service);
            $appointment = new Appointment(Uuid::generate(), $command->clinic, $command->patient, $command->doctor, $command->service, $service, $command->actor, TimeInterval::lasting($command->start, $service->durationMinutes));
            $this->availability->assertAvailable($appointment);
            $this->appointments->save($appointment, true);
            $this->events->record($appointment, $command->actor, 'CREATED', []);
            $response = AppointmentView::from($appointment);
            if (null !== $key) {
                $this->idempotency->save($command->clinic, $command->actor, $key, $command->hash(), $response);
            }

            return $response;
        });
    }

    /** @return array<string,mixed> */
    public function action(ChangeAppointment $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $initial = $this->appointments->get($command->clinic, $command->id);
            $this->appointments->lockDoctor($command->clinic, $initial->doctorId);
            $appointment = $this->appointments->get($command->clinic, $command->id, true);
            $before = array_intersect_key(AppointmentView::from($appointment), array_flip(['status', 'starts_at', 'ends_at', 'version']));
            $appointment->change($command->action, $command->version, $this->clock->now(), $command->start, $command->reason, $this->appointments->cancellationHours($command->clinic));
            if (AppointmentAction::Reschedule === $command->action) {
                $this->availability->assertAvailable($appointment);
            }
            $this->appointments->save($appointment);
            $this->events->record($appointment, $command->actor, $command->action->eventType(), $before);

            return AppointmentView::from($appointment);
        });
    }
}
