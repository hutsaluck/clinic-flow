<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Application\PaymentView;
use App\Billing\Application\RecordPayment;
use App\Billing\Application\ReversePayment;
use App\Billing\Domain\BillingRepository;
use App\Shared\Application\TransactionManager;

final readonly class Billing
{
    public function __construct(private BillingRepository $repository, private TransactionManager $transactions)
    {
    }

    /** @return array<string,scalar|null> */
    public function record(RecordPayment $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $ledger = $this->repository->lockLedger($command->clinic, $command->appointment);
            $payment = $ledger->record($command->actor, $command->amount, $command->method, $command->comment);
            $this->repository->save($payment);

            return PaymentView::from($payment);
        });
    }

    /** @return array<string,scalar|null> */
    public function reverse(ReversePayment $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $original = $this->repository->payment($command->clinic, $command->payment);
            $ledger = $this->repository->lockLedger($command->clinic, $original->appointment);
            $payment = $ledger->reverse($command->actor, $original, $this->repository->isReversed($original->id), $command->comment);
            $this->repository->save($payment);

            return PaymentView::from($payment);
        });
    }
}
