<?php

declare(strict_types=1);

namespace App\Appointment\Infrastructure;

use App\Appointment\Application\IdempotencyStore;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainViolation;
use App\Shared\Domain\Uuid;
use Doctrine\DBAL\Connection;

final readonly class DoctrineIdempotencyStore implements IdempotencyStore
{
    public function __construct(private Connection $db, private Clock $clock)
    {
    }

    public function lock(Uuid $clinic, Uuid $actor, string $key): void
    {
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [(string) $clinic.(string) $actor.$key]);
    }

    public function find(Uuid $clinic, Uuid $actor, string $key, string $hash): ?array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM idempotency_keys WHERE clinic_id=? AND actor_user_id=? AND endpoint='appointments' AND key=? AND expires_at>?", [(string) $clinic, (string) $actor, $key, $this->clock->now()->format('c')]);
        if (!$row) {
            return null;
        }
        if ($row['request_hash'] !== $hash) {
            throw new DomainViolation('IDEMPOTENCY_CONFLICT', 'Ключ уже використано для іншого запиту.');
        }

        return json_decode($row['response_body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function save(Uuid $clinic, Uuid $actor, string $key, string $hash, array $response): void
    {
        $this->db->executeStatement("DELETE FROM idempotency_keys WHERE clinic_id=? AND actor_user_id=? AND endpoint='appointments' AND key=? AND expires_at<=?", [(string) $clinic, (string) $actor, $key, $this->clock->now()->format('c')]);
        $this->db->insert('idempotency_keys', ['id' => (string) Uuid::generate(), 'clinic_id' => (string) $clinic, 'actor_user_id' => (string) $actor, 'endpoint' => 'appointments', 'key' => $key, 'request_hash' => $hash, 'response_code' => 201, 'response_body' => json_encode($response, JSON_THROW_ON_ERROR), 'expires_at' => $this->clock->now()->modify('+1 day')->format('c')]);
    }
}
