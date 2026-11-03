<?php

declare(strict_types=1);

namespace App\Notification;

final readonly class Delivery
{
    public function __construct(public string $id)
    {
    }
}
