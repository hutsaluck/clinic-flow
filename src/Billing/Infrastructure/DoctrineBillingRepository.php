<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Application\PaymentView;
use App\Billing\Domain\BillingRepository;
use App\Billing\Domain\Payment;
use App\Billing\Domain\PaymentLedger;
use App\Billing\Domain\PaymentMethod;
use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Money;
use App\Shared\Domain\Uuid;
use App\Shared\Store;

final readonly class DoctrineBillingRepository implements BillingRepository
{
    public function __construct(private Store $store)
    {
    }

    public function lockLedger(Uuid $clinic, Uuid $appointment): PaymentLedger
    {
        $a = $this->store->db->fetchAssociative('SELECT * FROM appointments WHERE clinic_id=? AND id=? FOR UPDATE', [(string) $clinic, (string) $appointment]);
        if (!$a) {
            throw new DomainViolation('NOT_FOUND', 'Прийом не знайдено.');
        }
        $paid = (int) $this->store->db->fetchOne('SELECT COALESCE(sum(amount_minor),0) FROM payments WHERE clinic_id=? AND appointment_id=?', [(string) $clinic, (string) $appointment]);

        return new PaymentLedger($clinic, $appointment, new Money((int) $a['price_minor_snapshot'], $a['currency_snapshot']), $paid);
    }

    public function payment(Uuid $clinic, Uuid $id): Payment
    {
        $p = $this->store->one('payments', (string) $clinic, (string) $id);

        return new Payment(new Uuid($p['id']), $clinic, new Uuid($p['appointment_id']), (int) $p['amount_minor'], $p['currency'], PaymentMethod::from($p['method']), new Uuid($p['created_by_user_id']), $p['reverses_payment_id'] ? new Uuid($p['reverses_payment_id']) : null, $p['comment']);
    }

    public function isReversed(Uuid $payment): bool
    {
        return (bool) $this->store->db->fetchOne('SELECT 1 FROM payments WHERE reverses_payment_id=?', [(string) $payment]);
    }

    public function save(Payment $payment): void
    {
        $this->store->db->insert('payments', PaymentView::from($payment));
        $this->store->audit((string) $payment->clinic, (string) $payment->actor, $payment->isReversal() ? 'PAYMENT_REVERSED' : 'PAYMENT_RECORDED', 'payment', (string) $payment->id, ['amount_minor' => $payment->amountMinor, 'appointment_id' => (string) $payment->appointment]);
    }
}
