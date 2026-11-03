<?php

declare(strict_types=1);

namespace App\Controller;

use App\Identity\Identity;
use App\Scheduling\Availability;
use App\Shared\ApiError;
use App\Shared\Id;
use App\Shared\Input;
use App\Shared\Store;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/clinics/{clinicId}')]
final class ScheduleController
{
    public function __construct(private Identity $identity, private Store $s, private Availability $availability)
    {
    }

    #[Route('/availability', methods: ['GET'])]
    public function availability(Request $r, string $clinicId): JsonResponse
    {
        $u = $this->identity->user($r);
        $m = $this->identity->clinic($u, $clinicId);
        $doctor = Input::uuid($r->query->get('doctor_id'));
        $this->identity->ownDoctor($m, $doctor);

        $service = Input::uuid($r->query->get('service_id'));
        $ignore = null;
        $duration = null;
        if ($appointmentId = $r->query->get('appointment_id')) {
            $a = $this->s->one('appointments', $clinicId, $appointmentId);
            $this->identity->ownDoctor($m, $a['doctor_id']);
            if ($a['doctor_id'] !== $doctor || $a['service_id'] !== $service || !in_array($a['status'], ['SCHEDULED', 'CONFIRMED'], true)) {
                throw new ApiError(422, 'INVALID_APPOINTMENT', 'Запис не можна перенести з цими параметрами.');
            }
            $ignore = $a['id'];
            $duration = (int) $a['duration_minutes_snapshot'];
        }

        return new JsonResponse($this->availability->get($clinicId, $doctor, $service, $r->query->get('date', ''), $ignore, $duration));
    }

    #[Route('/doctors/{id}/{kind}', requirements: ['kind' => 'working-hours|exceptions'], methods: ['GET', 'PUT', 'POST'])]
    public function schedule(Request $r, string $clinicId, string $id, string $kind): JsonResponse
    {
        $u = $this->identity->user($r);
        $write = !$r->isMethod('GET');
        $m = $this->identity->clinic($u, $clinicId, $write ? ['CLINIC_ADMIN'] : ['CLINIC_ADMIN', 'RECEPTIONIST', 'DOCTOR']);
        $this->identity->ownDoctor($m, $id);
        $this->s->one('doctor_profiles', $clinicId, $id);
        $table = 'working-hours' === $kind ? 'doctor_working_hours' : 'doctor_schedule_exceptions';
        if ($write) {
            if (('working-hours' === $kind && !$r->isMethod('PUT')) || ('exceptions' === $kind && !$r->isMethod('POST'))) {
                throw new ApiError(405, 'METHOD_NOT_ALLOWED', 'Недозволений метод.');
            }
            $d = $r->toArray();
            $this->s->db->transactional(function () use ($d, $kind, $table, $clinicId, $id, $u) {
                $this->s->db->fetchOne('SELECT id FROM doctor_profiles WHERE clinic_id=? AND id=? FOR UPDATE', [$clinicId, $id]);
                if ('working-hours' === $kind) {
                    $hours = $d['intervals'] ?? null;
                    if (!is_array($hours) || !array_is_list($hours) || count($hours) > 50) {
                        throw new ApiError(422, 'VALIDATION_ERROR', 'Некоректні робочі інтервали.');
                    }
                    $valid = [];
                    foreach ($hours as $h) {
                        if (!is_array($h)) {
                            throw new ApiError(422, 'VALIDATION_ERROR', 'Некоректний інтервал.');
                        }
                        $day = Input::integer($h, 'day_of_week', 1, 7);
                        $start = Input::text($h, 'start_local_time', 8);
                        $end = Input::text($h, 'end_local_time', 8);
                        foreach ([$start, $end] as $t) {
                            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(:00)?$/', $t)) {
                                throw new ApiError(422, 'INVALID_TIME', 'Некоректний час.');
                            }
                        }
                        $start = substr($start, 0, 5).':00';
                        $end = substr($end, 0, 5).':00';
                        if ($start >= $end) {
                            throw new ApiError(422, 'INVALID_INTERVAL', 'Кінець має бути пізніше початку.');
                        }
                        foreach ($valid as $v) {
                            if ($v['day_of_week'] === $day && $v['start_local_time'] < $end && $v['end_local_time'] > $start) {
                                throw new ApiError(422, 'OVERLAPPING_HOURS', 'Робочі інтервали перетинаються.');
                            }
                        }
                        $valid[] = ['id' => Id::new(), 'clinic_id' => $clinicId, 'doctor_id' => $id, 'day_of_week' => $day, 'start_local_time' => $start, 'end_local_time' => $end];
                    }
                    $this->s->db->delete($table, ['clinic_id' => $clinicId, 'doctor_id' => $id]);
                    foreach ($valid as $v) {
                        $this->s->db->insert($table, $v);
                    }
                } else {
                    $start = Input::instant($d['starts_at'] ?? null);
                    $end = Input::instant($d['ends_at'] ?? null);
                    $type = $d['type'] ?? '';
                    if ($start >= $end || !in_array($type, ['UNAVAILABLE', 'AVAILABLE_OVERRIDE'], true)) {
                        throw new ApiError(422, 'INVALID_INTERVAL', 'Некоректний виняток.');
                    }
                    $this->s->db->insert($table, ['id' => Id::new(), 'clinic_id' => $clinicId, 'doctor_id' => $id, 'starts_at' => $start->format('c'), 'ends_at' => $end->format('c'), 'type' => $type, 'reason' => Input::text($d, 'reason', 2000, false)]);
                }
                $conflicts = $this->availability->conflicts($clinicId, $id);
                if ($conflicts) {
                    throw new ApiError(409, 'SCHEDULE_CONFLICT', 'Зміна зачіпає наявні записи. Спочатку перенесіть або скасуйте їх.', ['appointment_ids' => $conflicts]);
                }
                $this->s->audit($clinicId, $u['id'], 'SCHEDULE_UPDATED', 'doctor', $id);
            });
        }

        return new JsonResponse(['data' => $this->s->db->fetchAllAssociative("SELECT * FROM $table WHERE clinic_id=? AND doctor_id=?", [$clinicId, $id])], $r->isMethod('POST') ? 201 : 200);
    }

    #[Route('/doctors/{id}/exceptions/{exceptionId}', methods: ['DELETE'])]
    public function deleteException(Request $r, string $clinicId, string $id, string $exceptionId): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN']);
        $x = $this->s->one('doctor_schedule_exceptions', $clinicId, $exceptionId);
        if ($x['doctor_id'] !== $id) {
            throw new ApiError(404, 'NOT_FOUND', 'Виняток не знайдено.');
        }
        $this->s->db->transactional(function () use ($clinicId, $id, $exceptionId, $u) {
            $this->s->db->fetchOne('SELECT id FROM doctor_profiles WHERE clinic_id=? AND id=? FOR UPDATE', [$clinicId, $id]);
            $this->s->db->delete('doctor_schedule_exceptions', ['clinic_id' => $clinicId, 'id' => $exceptionId]);
            $conflicts = $this->availability->conflicts($clinicId, $id);
            if ($conflicts) {
                throw new ApiError(409, 'SCHEDULE_CONFLICT', 'Спочатку перенесіть записи.', ['appointment_ids' => $conflicts]);
            }
            $this->s->audit($clinicId, $u['id'], 'EXCEPTION_DELETED', 'doctor', $id);
        });

        return new JsonResponse(null, 204);
    }
}
