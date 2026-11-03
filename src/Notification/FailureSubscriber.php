<?php

declare(strict_types=1);

namespace App\Notification;

use App\Shared\Store;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class FailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private Store $s)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['failed', -100]];
    }

    public function failed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof Delivery) {
            return;
        }
        $this->s->db->executeStatement("UPDATE notification_deliveries SET attempts=attempts+1,last_error_code='DELIVERY_FAILED',status=CASE WHEN ?=1 THEN 'PENDING' ELSE 'FAILED' END WHERE id=? AND status<>'SENT'", [$event->willRetry() ? 1 : 0, $message->id]);
    }
}
