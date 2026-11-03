<?php

declare(strict_types=1);

namespace App\Appointment\Application;

use App\Appointment\Domain\Appointment;

final class AppointmentView
{
    /** @return array<string,scalar|null> */
    public static function from(Appointment $a): array
    {
        return ['id' => (string) $a->id, 'clinic_id' => (string) $a->clinicId, 'patient_id' => (string) $a->patientId, 'doctor_id' => (string) $a->doctorId, 'service_id' => (string) $a->serviceId, 'starts_at' => $a->interval()->start->format('c'), 'ends_at' => $a->interval()->end->format('c'), 'status' => $a->status()->value, 'service_name_snapshot' => $a->service->name, 'duration_minutes_snapshot' => $a->service->durationMinutes, 'price_minor_snapshot' => $a->service->price->minor, 'currency_snapshot' => $a->service->price->currency, 'created_by_user_id' => (string) $a->createdBy, 'version' => $a->version(), 'cancellation_reason' => $a->cancellationReason()];
    }
}
