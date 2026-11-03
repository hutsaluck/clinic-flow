<?php

declare(strict_types=1);

namespace App\Controller;

use App\Appointment\Bookings;
use App\Identity\Identity;
use App\Shared\ApiError;
use App\Shared\Input;
use App\Shared\Store;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/clinics/{clinicId}')]
final class AppointmentController
{
    public function __construct(private Identity $identity, private Store $s, private Bookings $bookings)
    {
    }

    #[Route('/appointments', methods: ['GET', 'POST'])]
    #[Route('/patients/{patientId}/appointments', methods: ['GET'])]
    public function collection(Request $r, string $clinicId, ?string $patientId = null): JsonResponse
    {
        $u = $this->identity->user($r);
        $m = $this->identity->clinic($u, $clinicId, $r->isMethod('POST') ? ['CLINIC_ADMIN', 'RECEPTIONIST'] : ['CLINIC_ADMIN', 'RECEPTIONIST', 'DOCTOR']);
        if ($r->isMethod('POST')) {
            return new JsonResponse(['data' => $this->bookings->create(\App\Appointment\Presentation\AppointmentRequest::create($clinicId, $u['id'], $r->toArray(), $r->headers->get('Idempotency-Key')))], 201);
        }
        $sql = $this->baseSql().' WHERE a.clinic_id=?';
        $args = [$clinicId];
        if ('DOCTOR' === $m['role']) {
            $sql .= ' AND a.doctor_id=?';
            $args[] = $m['doctor_id'];
        }
        if ($patientId) {
            $this->s->one('patients', $clinicId, $patientId);
            $sql .= ' AND a.patient_id=?';
            $args[] = $patientId;
        }
        foreach (['doctor_id', 'service_id', 'patient_id', 'status'] as $f) {
            if ($v = $r->query->get($f)) {
                if ('status' !== $f) {
                    Input::uuid($v);
                } $sql .= " AND a.$f=?";
                $args[] = $v;
            }
        }
        if ($v = $r->query->get('from')) {
            $sql .= ' AND a.starts_at>=?';
            $args[] = $this->boundary($v, $m['timezone']);
        }
        if ($v = $r->query->get('to')) {
            $sql .= ' AND a.starts_at<?';
            $args[] = $this->boundary($v, $m['timezone']);
        }

        return new JsonResponse($this->s->page($sql.' ORDER BY a.starts_at,a.id', $args, $r->query->all()));
    }

    private function boundary(string $value, string $timezone): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            Input::date($value);

            return (new \DateTimeImmutable($value.' 00:00:00', new \DateTimeZone($timezone)))->format('c');
        }

        return Input::instant($value)->format('c');
    }

    private function baseSql(): string
    {
        return "SELECT a.*,a.price_minor_snapshot AS price_minor,a.currency_snapshot AS currency,concat_ws(' ',p.last_name,p.first_name) AS patient_name,concat_ws(' ',d.last_name,d.first_name) AS doctor_name,COALESCE((SELECT sum(amount_minor) FROM payments pay WHERE pay.clinic_id=a.clinic_id AND pay.appointment_id=a.id),0) AS paid_amount FROM appointments a JOIN patients p ON p.clinic_id=a.clinic_id AND p.id=a.patient_id JOIN doctor_profiles d ON d.clinic_id=a.clinic_id AND d.id=a.doctor_id";
    }

    #[Route('/appointments/{id}', methods: ['GET'])]
    #[Route('/appointments/{id}/events', methods: ['GET'])]
    public function detail(Request $r, string $clinicId, string $id): JsonResponse
    {
        $u = $this->identity->user($r);
        $m = $this->identity->clinic($u, $clinicId);
        $a = $this->s->one('appointments', $clinicId, $id);
        $this->identity->ownDoctor($m, $a['doctor_id']);
        if (str_ends_with($r->getPathInfo(), '/events')) {
            return new JsonResponse(['data' => $this->s->db->fetchAllAssociative('SELECT * FROM appointment_events WHERE clinic_id=? AND appointment_id=? ORDER BY occurred_at', [$clinicId, $id])]);
        }

        return new JsonResponse(['data' => $this->s->db->fetchAssociative($this->baseSql().' WHERE a.clinic_id=? AND a.id=?', [$clinicId, $id])]);
    }

    #[Route('/appointments/{id}/{action}', requirements: ['action' => 'confirm|cancel|reschedule|complete|mark-no-show'], methods: ['POST'])]
    public function action(Request $r, string $clinicId, string $id, string $action): JsonResponse
    {
        $u = $this->identity->user($r);
        $m = $this->identity->clinic($u, $clinicId);
        $a = $this->s->one('appointments', $clinicId, $id);
        $this->identity->ownDoctor($m, $a['doctor_id']);
        if ('DOCTOR' === $m['role'] && !in_array($action, ['complete', 'mark-no-show'], true)) {
            throw new ApiError(403, 'FORBIDDEN', 'Недостатньо прав.');
        }

        return new JsonResponse(['data' => $this->bookings->action(\App\Appointment\Presentation\AppointmentRequest::change($clinicId, $u['id'], $id, $action, $r->toArray()))]);
    }
}
