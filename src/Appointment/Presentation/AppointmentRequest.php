<?php

declare(strict_types=1);

namespace App\Appointment\Presentation;

use App\Appointment\Application\ChangeAppointment;
use App\Appointment\Application\CreateAppointment;
use App\Appointment\Domain\AppointmentAction;
use App\Shared\ApiError;
use App\Shared\Domain\Uuid;
use App\Shared\Input;

final class AppointmentRequest
{
    /** @param array<string,mixed> $body */
    public static function create(string $clinic, string $actor, array $body, ?string $key): CreateAppointment
    {
        return new CreateAppointment(new Uuid($clinic), new Uuid($actor), new Uuid(Input::uuid($body['patient_id'] ?? null)), new Uuid(Input::uuid($body['doctor_id'] ?? null)), new Uuid(Input::uuid($body['service_id'] ?? null)), Input::instant($body['starts_at'] ?? null), $key);
    }

    /** @param array<string,mixed> $body */
    public static function change(string $clinic, string $actor, string $id, string $action, array $body): ChangeAppointment
    {
        if (!isset($body['version']) || !is_int($body['version'])) {
            throw new ApiError(409, 'VERSION_CONFLICT', 'Запис змінено. Оновіть дані.');
        }

        return new ChangeAppointment(new Uuid($clinic), new Uuid($actor), new Uuid($id), AppointmentAction::from($action), $body['version'], isset($body['starts_at']) ? Input::instant($body['starts_at']) : null, Input::text($body,'reason',2000,false));
    }
}
