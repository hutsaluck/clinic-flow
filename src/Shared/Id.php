<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\Uid\Uuid;

final class Id
{
    public static function new(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
