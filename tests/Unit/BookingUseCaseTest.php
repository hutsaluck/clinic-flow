<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Appointment\Application\AppointmentEvents;
use App\Appointment\Application\BookingAvailability;
use App\Appointment\Application\ChangeAppointment;
use App\Appointment\Application\CreateAppointment;
use App\Appointment\Application\IdempotencyStore;
use App\Appointment\Bookings;
use App\Appointment\Domain\Appointment;
use App\Appointment\Domain\AppointmentAction;
use App\Appointment\Domain\AppointmentRepository;
use App\Appointment\Domain\ServiceSnapshot;
use App\Shared\Application\TransactionManager;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Money;
use App\Shared\Domain\Uuid;
use PHPUnit\Framework\TestCase;

final class BookingUseCaseTest extends TestCase
{
    public function testUseCaseBooksAndChangesUsingOnlyInMemoryPorts(): void
    {
        $repository = new class implements AppointmentRepository {
            public array $appointments = [];
            public int $locks = 0;

            public function lockDoctor(Uuid $clinic, Uuid $doctor): void
            {
                ++$this->locks;
            }

            public function requireActivePatient(Uuid $clinic, Uuid $patient): void
            {
            }

            public function service(Uuid $clinic, Uuid $service): ServiceSnapshot
            {
                return new ServiceSnapshot('Консультація', 30, new Money(85000, 'UAH'));
            }

            public function get(Uuid $clinic, Uuid $id, bool $lock = false): Appointment
            {
                $a = $this->appointments[(string) $id] ?? null;
                if (!$a || !$a->clinicId->equals($clinic)) {
                    throw new DomainViolation('NOT_FOUND', 'Прийом не знайдено.');
                }

return clone $a;
            }

            public function save(Appointment $appointment, bool $new = false): void
            {
                $this->appointments[(string) $appointment->id] = clone $appointment;
            }

            public function cancellationHours(Uuid $clinic): int
            {
                return 0;
            }
        };
        $availability = new class implements BookingAvailability {
            public int $checks = 0;

            public function assertAvailable(Appointment $appointment): void
            {
                ++$this->checks;
            }
        };
        $transactions = new class implements TransactionManager {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
        $idempotency = new class implements IdempotencyStore {
            public array $responses = [];

            public function lock(Uuid $clinic, Uuid $actor, string $key): void
            {
            }

            private function key(Uuid $clinic, Uuid $actor, string $key): string
            {
                return $clinic.'|'.$actor.'|'.$key;
            }

            public function find(Uuid $clinic, Uuid $actor, string $key, string $hash): ?array
            {
                return $this->responses[$this->key($clinic, $actor, $key)][$hash] ?? null;
            }

            public function save(Uuid $clinic, Uuid $actor, string $key, string $hash, array $response): void
            {
                $this->responses[$this->key($clinic, $actor, $key)][$hash] = $response;
            }
        };
        $events = new class implements AppointmentEvents {
            public array $events = [];

            public function record(Appointment $appointment, Uuid $actor, string $event, array $before): void
            {
                $this->events[] = $event;
            }
        };
        $clock = new class implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2030-01-01T00:00:00Z');
            }
        };
        $service = new Bookings($repository, $availability, $transactions, $idempotency, $events, $clock);
        $clinic = Uuid::generate();
        $actor = Uuid::generate();
        $command = new CreateAppointment($clinic, $actor, Uuid::generate(), Uuid::generate(), Uuid::generate(), new \DateTimeImmutable('2030-01-07T10:00:00Z'), 'same-request');
        $one = $service->create($command);
        $two = $service->create($command);
        self::assertSame($one['id'], $two['id']);
        self::assertCount(1, $repository->appointments);
        self::assertSame(1, $availability->checks);
        self::assertSame(['CREATED'], $events->events);
        $updated = $service->action(new ChangeAppointment($clinic, $actor, new Uuid($one['id']), AppointmentAction::Confirm, 1));
        self::assertSame('CONFIRMED', $updated['status']);
        self::assertSame(2, $updated['version']);
        self::assertSame(['CREATED', 'CONFIRM'], $events->events);
        try {
            $service->action(new ChangeAppointment($clinic, $actor, new Uuid($one['id']), AppointmentAction::Cancel, 1));
            self::fail('Expected a version conflict');
        } catch (DomainViolation $error) {
            self::assertSame('VERSION_CONFLICT', $error->errorCode);
        }
        self::assertSame('CONFIRMED', $repository->get($clinic, new Uuid($one['id']))->status()->value);
        self::assertCount(2, $events->events);
        $past = new CreateAppointment($clinic, $actor, $command->patient, $command->doctor, $command->service, new \DateTimeImmutable('2029-01-01T10:00:00Z'));
        try {
            $service->create($past);
            self::fail('Past appointment must be rejected');
        } catch (DomainViolation $error) {
            self::assertSame('SLOT_UNAVAILABLE',$error->errorCode);
        }
        self::assertCount(1,$repository->appointments);
    }
}
