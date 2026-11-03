<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\Delivery;
use App\Shared\Id;
use App\Shared\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:notifications:tick', description: 'Publish transactional outbox and deduplicated 24-hour reminders')]
final class NotificationsCommand extends Command
{
    public function __construct(private Store $s, private MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->s->db;
        $db->transactional(function () use ($db) {
            $events = $db->fetchAllAssociative('SELECT * FROM outbox_messages WHERE published_at IS NULL ORDER BY occurred_at LIMIT 100 FOR UPDATE SKIP LOCKED');
            foreach ($events as $e) {
                $p = json_decode($e['payload'], true, 512, JSON_THROW_ON_ERROR);
                $this->enqueue($e['clinic_id'], $p['appointment_id'], (int) $p['version'], $e['event_type'], $e['id']);
                $db->update('outbox_messages', ['published_at' => gmdate('c'), 'attempts' => (int) $e['attempts'] + 1], ['id' => $e['id']]);
            }
            $appointments = $db->fetchAllAssociative("SELECT id,clinic_id,version FROM appointments WHERE starts_at>now() AND starts_at<=now()+interval '24 hours' AND status IN ('SCHEDULED','CONFIRMED')");
            foreach ($appointments as $a) {
                $this->enqueue($a['clinic_id'], $a['id'], (int) $a['version'], 'REMINDER', 'reminder:'.$a['id'].':'.$a['version']);
            }
        });
        // Persisted pending IDs are redispatched safely after a crash between DB commit and Redis publish.
        $pending = $db->fetchAllAssociative("SELECT id FROM notification_deliveries WHERE status='PENDING' ORDER BY created_at LIMIT 200");
        foreach ($pending as $n) {
            $this->bus->dispatch(new Delivery($n['id']));
        }
        $output->writeln('Dispatched '.count($pending).' delivery jobs.');

        return Command::SUCCESS;
    }

    private function enqueue(string $clinic, string $appointment, int $version, string $template, string $key): void
    {
        $recipient = $this->s->db->fetchOne('SELECT p.email FROM appointments a JOIN patients p ON p.id=a.patient_id AND p.clinic_id=a.clinic_id WHERE a.clinic_id=? AND a.id=?', [$clinic, $appointment]);
        if (!$recipient) {
            return;
        }
        $this->s->db->executeStatement('INSERT INTO notification_deliveries(id,clinic_id,appointment_id,recipient,template_key,deduplication_key,appointment_version) VALUES (?,?,?,?,?,?,?) ON CONFLICT(deduplication_key) DO NOTHING', [Id::new(), $clinic, $appointment, $recipient, $template, $key, $version]);
    }
}
