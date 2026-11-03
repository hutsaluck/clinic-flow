<?php

declare(strict_types=1);

namespace App\Billing\Presentation;

use App\Billing\Application\RecordPayment;
use App\Billing\Application\ReversePayment;
use App\Billing\Domain\PaymentMethod;
use App\Shared\Domain\Money;
use App\Shared\Domain\Uuid;
use App\Shared\Input;

final class PaymentRequest
{
    /** @param array<string,mixed> $body */
    public static function record(string $clinic, string $actor, string $appointment, array $body): RecordPayment
    {
        return new RecordPayment(new Uuid($clinic), new Uuid($actor), new Uuid($appointment), new Money(Input::integer($body, 'amount_minor', 1, 1000000000), Input::text($body, 'currency', 3)), PaymentMethod::parse(Input::text($body, 'method', 30)), Input::text($body, 'comment', 2000, false));
    }

    /** @param array<string,mixed> $body */
    public static function reverse(string $clinic, string $actor, string $payment, array $body): ReversePayment
    {
        return new ReversePayment(new Uuid($clinic), new Uuid($actor), new Uuid($payment), Input::text($body,'comment',2000,false));
    }
}
