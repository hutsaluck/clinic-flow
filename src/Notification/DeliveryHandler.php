<?php

declare(strict_types=1);

namespace App\Notification;

use App\Shared\Store;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final class DeliveryHandler
{
    public function __construct(private Store $s, private MailerInterface $mailer, #[Autowire('%env(MAILER_FROM)%')] private string $sender)
    {
    }

    public function __invoke(Delivery $message): void
    {
        $this->s->db->transactional(function () use ($message) {
            $n = $this->s->db->fetchAssociative('SELECT * FROM notification_deliveries WHERE id=? FOR UPDATE', [$message->id]);
            if (!$n || 'PENDING' !== $n['status']) {
                return;
            }
            $a = $this->s->one('appointments', $n['clinic_id'], $n['appointment_id']);
            if ((int) $a['version'] !== (int) $n['appointment_version'] || ('REMINDER' === $n['template_key'] && (!in_array($a['status'], ['SCHEDULED', 'CONFIRMED'], true) || strtotime($a['starts_at']) <= time()))) {
                $this->s->db->update('notification_deliveries', ['status' => 'SKIPPED'], ['id' => $n['id']]);

                return;
            }
            $tz = $this->s->db->fetchOne('SELECT timezone FROM clinics WHERE id=?', [$a['clinic_id']]);
            $when = (new \DateTimeImmutable($a['starts_at']))->setTimezone(new \DateTimeZone($tz))->format('d.m.Y H:i P');
            $subject = match ($n['template_key']) {
                'REMINDER' => 'Нагадування про прийом', 'appointment.CANCEL' => 'Прийом скасовано', 'appointment.reschedule','appointment.RESCHEDULE' => 'Прийом перенесено', default => 'Ваш запис у ClinicFlow',
            };
            // Direct SMTP here; enqueue the delivery ID only, never an entire patient email payload.
            $email = (new Email())->from($this->sender)->to($n['recipient'])->subject($subject)->text($subject.'. Час: '.$when.'. Послуга: '.$a['service_name_snapshot'].'.');
            $senderAddress = $email->getFrom()[0]->getEncodedAddress();
            $messageDomain = substr($senderAddress, (int) strrpos($senderAddress, '@') + 1);
            $email->getHeaders()->addIdHeader('Message-ID', $n['id'].'@'.$messageDomain);
            $this->mailer->send($email);
            $this->s->db->update('notification_deliveries', ['status' => 'SENT', 'sent_at' => gmdate('c'), 'attempts' => (int) $n['attempts'] + 1, 'last_error_code' => null], ['id' => $n['id']]);
        });
    }
}
