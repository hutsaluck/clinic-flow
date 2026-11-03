<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\TransactionManager;
use App\Shared\Domain\DomainViolation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private Connection $db)
    {
    }

    public function run(callable $operation): mixed
    {
        try {
            return $this->db->transactional(static fn () => $operation());
        } catch (DriverException $error) {
            if ('23P01' === $error->getSQLState()) {
                throw new DomainViolation('SLOT_ALREADY_BOOKED', 'Цей час уже зайнятий. Оберіть інший слот.');
            }
            throw $error;
        }
    }
}
