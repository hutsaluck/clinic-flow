<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Appointment\Domain\Appointment;
use App\Appointment\Domain\AppointmentAction;
use App\Appointment\Domain\AppointmentStatus;
use App\Appointment\Domain\ServiceSnapshot;
use App\Billing\Domain\PaymentLedger;
use App\Billing\Domain\PaymentMethod;
use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Email;
use App\Shared\Domain\Money;
use App\Shared\Domain\PhoneNumber;
use App\Shared\Domain\TimeInterval;
use App\Shared\Domain\Uuid;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    public function testMoneyUsesExactMinorUnits(): void
    {
        $total = (new Money(40000, 'UAH'))->add(new Money(60000, 'UAH'));
        self::assertSame(100000, $total->minor);
        self::assertSame(60000, $total->subtract(new Money(40000, 'UAH'))->minor);
    }

    public function testMoneyRejectsDifferentCurrencies(): void
    {
        $this->expectException(DomainViolation::class);
        (new Money(1, 'UAH'))->add(new Money(1, 'EUR'));
    }

    public function testMoneyCannotBecomeNegative(): void
    {
        $this->expectException(DomainViolation::class);
        (new Money(100, 'UAH'))->subtract(new Money(101, 'UAH'));
    }

    public function testUuidNormalizesAndUsesValueEquality(): void
    {
        $id = Uuid::generate();
        self::assertTrue($id->equals(new Uuid(strtoupper((string) $id))));
        self::assertFalse($id->equals(Uuid::generate()));
    }

    public function testEmailAndPhoneNormalization(): void
    {
        self::assertSame('a@example.test', (string) new Email(' A@Example.Test '));
        self::assertSame('+380501234567', (string) new PhoneNumber('(050) 123-45-67'));
    }

    public function testInvalidPhoneRejected(): void
    {
        $this->expectException(DomainViolation::class);
        new PhoneNumber('not a phone');
    }

    public function testHalfOpenIntervalsAllowAdjacentAppointments(): void
    {
        $one = TimeInterval::lasting(new \DateTimeImmutable('2030-01-01T10:00:00Z'), 30);
        $two = TimeInterval::lasting(new \DateTimeImmutable('2030-01-01T10:30:00Z'), 30);
        self::assertFalse($one->overlaps($two));
        self::assertTrue($one->overlaps(TimeInterval::lasting(new \DateTimeImmutable('2030-01-01T10:15:00Z'), 30)));
        self::assertSame('UTC', $one->start->getTimezone()->getName());
    }

    private function appointment(): Appointment
    {
        return new Appointment(Uuid::generate(), Uuid::generate(), Uuid::generate(), Uuid::generate(), Uuid::generate(), new ServiceSnapshot('Консультація', 30, new Money(85000, 'UAH')), Uuid::generate(), TimeInterval::lasting(new \DateTimeImmutable('2030-01-07T10:00:00Z'), 30));
    }

    public function testAggregateReschedulesWithoutChangingSnapshotOrStatus(): void
    {
        $a = $this->appointment();
        $now = new \DateTimeImmutable('2030-01-01T00:00:00Z');
        $a->change(AppointmentAction::Confirm, 1, $now);
        $a->change(AppointmentAction::Reschedule, 2, $now, new \DateTimeImmutable('2030-01-08T12:00:00Z'));
        self::assertSame(AppointmentStatus::Confirmed, $a->status());
        self::assertSame(3, $a->version());
        self::assertSame(85000, $a->service->price->minor);
        self::assertSame('12:30', $a->interval()->end->format('H:i'));
    }

    public function testStaleAggregateUpdateLeavesStateUnchanged(): void
    {
        $a = $this->appointment();
        try {
            $a->change(AppointmentAction::Cancel, 9, new \DateTimeImmutable('2030-01-01'));
            self::fail('Stale version must fail');
        } catch (DomainViolation $error) {
            self::assertSame('VERSION_CONFLICT', $error->errorCode);
        }self::assertSame(1, $a->version());
        self::assertSame(AppointmentStatus::Scheduled, $a->status());
    }

    public function testLedgerOutstandingAndReversal(): void
    {
        $ledger = new PaymentLedger(Uuid::generate(), Uuid::generate(), new Money(100000, 'UAH'), 40000);
        $actor = Uuid::generate();
        $payment = $ledger->record($actor, new Money(60000, 'UAH'), PaymentMethod::Cash);
        self::assertSame(60000, $ledger->outstanding()->minor);
        $paid = new PaymentLedger($ledger->clinic, $ledger->appointment, $ledger->cost, 100000);
        $reversal = $paid->reverse($actor, $payment, false);
        self::assertSame(-60000, $reversal->amountMinor);
        self::assertTrue($reversal->reverses->equals($payment->id));
    }

    public function testLedgerRejectsOverpayment(): void
    {
        $ledger = new PaymentLedger(Uuid::generate(), Uuid::generate(), new Money(100, 'UAH'), 90);
        $this->expectException(DomainViolation::class);
        $ledger->record(Uuid::generate(), new Money(11, 'UAH'), PaymentMethod::Cash);
    }

    public function testLedgerRejectsDuplicateReversal(): void
    {
        $ledger = new PaymentLedger(Uuid::generate(), Uuid::generate(), new Money(100, 'UAH'), 100);
        $actor = Uuid::generate();
        $payment = (new PaymentLedger($ledger->clinic,$ledger->appointment,$ledger->cost,0))->record($actor,new Money(100,'UAH'),PaymentMethod::Cash);
        $this->expectException(DomainViolation::class);
        $ledger->reverse($actor,$payment,true);
    }
}
