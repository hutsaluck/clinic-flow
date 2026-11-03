<?php

declare(strict_types=1);

namespace App\Appointment\Infrastructure;

use App\Appointment\Application\AppointmentEvents;
use App\Appointment\Application\AppointmentView;
use App\Appointment\Domain\Appointment;
use App\Shared\Domain\Uuid;
use App\Shared\Store;

final readonly class DoctrineAppointmentEvents implements AppointmentEvents
{
    public function __construct(private Store $store)
    {
    }

    public function record(Appointment $appointment, Uuid $actor, string $event, array $before): void
    {
        $snapshot = AppointmentView::from($appointment);
        $after = array_intersect_key($snapshot, array_flip(['status', 'starts_at', 'ends_at', 'version']));
        $clinic = (string) $appointment->clinicId;
        $id = (string) $appointment->id;
        $this->store->db->insert('appointment_events', ['id' => (string) Uuid::generate(), 'clinic_id' => $clinic, 'appointment_id' => $id, 'actor_user_id' => (string) $actor, 'event_type' => $event, 'old_value' => json_encode($before, JSON_THROW_ON_ERROR), 'new_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
        $this->store->audit($clinic, (string) $actor, 'APPOINTMENT_'.$event, 'appointment', $id, $after);
        $this->store->db->insert('outbox_messages', ['id' => (string) Uuid::generate(), 'clinic_id' => $clinic, 'event_type' => 'appointment.'.$event, 'payload' => json_encode(['appointment_id' => $id, 'version' => $appointment->version()], JSON_THROW_ON_ERROR)]);
    }
}
