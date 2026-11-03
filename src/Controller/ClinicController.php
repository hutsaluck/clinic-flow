<?php

declare(strict_types=1);

namespace App\Controller;

use App\Identity\Identity;
use App\Shared\ApiError;
use App\Shared\Id;
use App\Shared\Input;
use App\Shared\Store;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
final class ClinicController
{
    public function __construct(private Identity $identity, private Store $s)
    {
    }

    #[Route('/clinics', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        return new JsonResponse(['data' => $this->identity->clinics($this->identity->user($r)['id'])]);
    }

    #[Route('/platform/clinics', methods: ['GET', 'POST'])]
    #[Route('/platform/clinics/{id}', methods: ['GET', 'PATCH'])]
    public function platform(Request $r, ?string $id = null): JsonResponse
    {
        $u = $this->identity->user($r);
        if (!$u['platform_admin']) {
            throw new ApiError(403, 'FORBIDDEN', 'Доступ лише адміністратору платформи.');
        }
        if ($r->isMethod('GET')) {
            return new JsonResponse(['data' => $id ? $this->s->db->fetchAssociative('SELECT * FROM clinics WHERE id=?', [Input::uuid($id)]) : $this->s->db->fetchAllAssociative('SELECT * FROM clinics ORDER BY name')]);
        }
        $d = $r->toArray();
        if ($id) {
            $c = $this->s->db->fetchAssociative('SELECT * FROM clinics WHERE id=?', [Input::uuid($id)]);
            if (!$c) {
                throw new ApiError(404, 'NOT_FOUND', 'Клініку не знайдено.');
            }
            $data = $this->validate(array_replace($c, $d));
            if (isset($d['status'])) {
                if (!in_array($d['status'], ['ACTIVE', 'SUSPENDED'], true)) {
                    throw new ApiError(422, 'VALIDATION_ERROR', 'Некоректний статус.');
                } $data['status'] = $d['status'];
            }
            $this->s->db->transactional(function () use ($data, $id, $u) {
                $this->s->db->update('clinics', $data, ['id' => $id]);
                $this->s->audit($id, $u['id'], 'CLINIC_UPDATED', 'clinic', $id);
            });
        } else {
            $data = $this->validate($d);
            $id = Id::new();
            $data['id'] = $id;
            $owner = Input::uuid($d['owner_user_id'] ?? null);
            if (!$this->s->db->fetchOne("SELECT id FROM users WHERE id=? AND status='ACTIVE'", [$owner])) {
                throw new ApiError(422, 'OWNER_NOT_FOUND', 'Адміністратора не знайдено.');
            }
            $this->s->db->transactional(function () use ($data, $id, $owner, $u) {
                $this->s->db->insert('clinics', $data);
                $this->s->db->insert('clinic_memberships', ['id' => Id::new(), 'clinic_id' => $id, 'user_id' => $owner, 'role' => 'CLINIC_ADMIN']);
                $this->s->audit($id, $u['id'], 'CLINIC_CREATED', 'clinic', $id);
            });
        }

        return new JsonResponse(['data' => $this->s->db->fetchAssociative('SELECT * FROM clinics WHERE id=?', [$id])], $r->isMethod('POST') ? 201 : 200);
    }

    #[Route('/clinics/{clinicId}', methods: ['GET', 'PATCH'])]
    public function clinic(Request $r, string $clinicId): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, $r->isMethod('PATCH') ? ['CLINIC_ADMIN'] : ['CLINIC_ADMIN', 'RECEPTIONIST', 'DOCTOR']);
        $c = $this->s->db->fetchAssociative('SELECT * FROM clinics WHERE id=?', [$clinicId]);
        if ($r->isMethod('PATCH')) {
            $data = $this->validate(array_replace($c, $r->toArray()));
            $this->s->db->transactional(function () use ($data, $clinicId, $u) {
                $this->s->db->update('clinics', $data, ['id' => $clinicId]);
                $this->s->audit($clinicId, $u['id'], 'CLINIC_UPDATED', 'clinic', $clinicId);
            });
            $c = array_replace($c, $data);
        }

        return new JsonResponse(['data' => $c]);
    }

    #[Route('/clinics/{clinicId}/memberships', methods: ['GET', 'POST'])]
    #[Route('/clinics/{clinicId}/memberships/{id}', methods: ['PATCH'])]
    public function memberships(Request $r, string $clinicId, ?string $id = null): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN']);
        if (!$r->isMethod('GET')) {
            $d = $r->toArray();
            $role = $d['role'] ?? '';
            $status = $d['status'] ?? 'ACTIVE';
            if (!in_array($role, ['CLINIC_ADMIN', 'RECEPTIONIST', 'DOCTOR'], true) || !in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                throw new ApiError(422, 'VALIDATION_ERROR', 'Некоректна роль або статус.');
            }
            $this->s->db->transactional(function () use ($id, $clinicId, $d, $role, $status, $u) {
                $this->s->db->fetchOne('SELECT id FROM clinics WHERE id=? FOR UPDATE', [$clinicId]);
                if ($id) {
                    $m = $this->s->one('clinic_memberships', $clinicId, $id);
                    if ('CLINIC_ADMIN' === $m['role'] && 'ACTIVE' === $m['status'] && ('CLINIC_ADMIN' !== $role || 'ACTIVE' !== $status) && (int) $this->s->db->fetchOne("SELECT count(*) FROM clinic_memberships WHERE clinic_id=? AND role='CLINIC_ADMIN' AND status='ACTIVE'", [$clinicId]) <= 1) {
                        throw new ApiError(409, 'LAST_ADMIN', 'Не можна вимкнути останнього адміністратора.');
                    }
                    $this->s->db->update('clinic_memberships', ['role' => $role, 'status' => $status], ['clinic_id' => $clinicId, 'id' => $id]);
                    if ('DOCTOR' !== $role) {
                        $this->s->db->executeStatement('UPDATE doctor_profiles SET membership_id=NULL WHERE clinic_id=? AND membership_id=?', [$clinicId, $id]);
                    }
                } else {
                    $email = strtolower(Input::text($d, 'email'));
                    $user = $this->s->db->fetchOne("SELECT id FROM users WHERE email=? AND status='ACTIVE'", [$email]);
                    if (!$user) {
                        throw new ApiError(422, 'USER_NOT_FOUND', 'Обліковий запис не знайдено. Спершу створіть користувача командою app:user:create.');
                    }
                    $id = Id::new();
                    $this->s->db->insert('clinic_memberships', ['id' => $id, 'clinic_id' => $clinicId, 'user_id' => $user, 'role' => $role, 'status' => $status]);
                }
                $this->s->audit($clinicId, $u['id'], 'MEMBERSHIP_UPDATED', 'membership', $id, ['role' => $role, 'status' => $status]);
            });
        }

        return new JsonResponse(['data' => $this->s->db->fetchAllAssociative('SELECT m.*,u.email,u.first_name,u.last_name FROM clinic_memberships m JOIN users u ON m.user_id=u.id WHERE m.clinic_id=? ORDER BY u.last_name', [$clinicId])], $r->isMethod('POST') ? 201 : 200);
    }

    private function validate(array $d): array
    {
        $timezone = $d['timezone'] ?? 'Europe/Kyiv';
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new ApiError(422, 'INVALID_TIMEZONE', 'Потрібен IANA часовий пояс.');
        }
        $slug = Input::text($d, 'slug', 100);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new ApiError(422, 'INVALID_SLUG', 'Некоректний slug.');
        }
        $currency = $d['currency'] ?? 'UAH';
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ApiError(422, 'INVALID_CURRENCY', 'Некоректна валюта.');
        }
        $data = ['name' => Input::text($d, 'name'), 'slug' => $slug, 'timezone' => $timezone, 'currency' => $currency, 'booking_horizon_days' => Input::integer($d + ['booking_horizon_days' => 90], 'booking_horizon_days', 1, 365), 'cancellation_hours' => Input::integer($d + ['cancellation_hours' => 0], 'cancellation_hours', 0, 168)];
        foreach (['email', 'phone', 'address'] as $f) {
            $data[$f] = Input::text($d, $f, 'address' === $f ? 2000 : 255, false);
        }
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ApiError(422, 'INVALID_EMAIL', 'Некоректний email.');
        }

        return $data;
    }
}
