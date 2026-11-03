<?php

declare(strict_types=1);

namespace App\Appointment\Application;

use App\Shared\Domain\Uuid;

interface IdempotencyStore
{
    public function lock(Uuid $clinic, Uuid $actor, string $key): void;

    /** @return array<string,mixed>|null */
    public function find(Uuid $clinic, Uuid $actor, string $key, string $hash): ?array;

    /** @param array<string,mixed> $response */
    public function save(Uuid $clinic, Uuid $actor, string $key, string $hash, array $response): void;
}
