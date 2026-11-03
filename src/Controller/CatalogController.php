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

#[Route('/api/v1/clinics/{clinicId}')]
final class CatalogController
{
    private const TABLES = ['patients' => 'patients', 'doctors' => 'doctor_profiles', 'services' => 'services'];

    public function __construct(private Identity $identity, private Store $s)
    {
    }

    private function access(Request $r, string $clinic, string $resource, bool $write): array
    {
        $u = $this->identity->user($r);
        $m = $this->identity->clinic($u, $clinic, $write ? ('patients' === $resource ? ['CLINIC_ADMIN', 'RECEPTIONIST'] : ['CLINIC_ADMIN']) : ['CLINIC_ADMIN', 'RECEPTIONIST', 'DOCTOR']);

        return [$u, $m];
    }

    #[Route('/{resource}', requirements: ['resource' => 'patients|doctors|services'], methods: ['GET', 'POST'])]
    public function collection(Request $r, string $clinicId, string $resource): JsonResponse
    {
        [$u,$m] = $this->access($r, $clinicId, $resource, $r->isMethod('POST'));
        $table = self::TABLES[$resource];
        if ($r->isMethod('POST')) {
            $data = $this->validate($r->toArray(), $resource, $clinicId);
            $data['id'] = Id::new();
            $data['clinic_id'] = $clinicId;
            $this->s->db->transactional(function () use ($table, $data, $clinicId, $u, $resource) {
                $this->s->db->insert($table, $data);
                $this->s->audit($clinicId, $u['id'], 'CREATED', $resource, $data['id']);
            });

            return new JsonResponse(['data' => $this->s->one($table, $clinicId, $data['id']), 'duplicates' => 'patients' === $resource ? $this->duplicates($clinicId, $data) : []], 201);
        }
        $sql = "SELECT * FROM $table t WHERE clinic_id=?";
        $args = [$clinicId];
        if ('DOCTOR' === $m['role'] && 'patients' === $resource) {
            $sql .= ' AND EXISTS (SELECT 1 FROM appointments a WHERE a.clinic_id=t.clinic_id AND a.patient_id=t.id AND a.doctor_id=?)';
            $args[] = $m['doctor_id'];
        }
        if ('1' !== $r->query->get('archived')) {
            $sql .= 'patients' === $resource ? ' AND archived_at IS NULL' : ' AND is_active=TRUE';
        }
        if ($search = $r->query->get('search')) {
            $sql .= 'services' === $resource ? ' AND name ILIKE ?' : " AND concat_ws(' ',first_name,last_name".('patients' === $resource ? ',phone_e164,email' : ',specialization').') ILIKE ?';
            $args[] = '%'.$search.'%';
        }
        $allowed = 'services' === $resource ? ['name' => 'name', 'price_minor' => 'price_minor', 'duration_minutes' => 'duration_minutes', 'created_at' => 'created_at'] : ['last_name' => 'last_name', 'first_name' => 'first_name', 'created_at' => 'created_at'];
        $sql .= $this->s->order($r->query->all(), $allowed, 'services' === $resource ? 'name' : 'last_name');
        $result = $this->s->page($sql, $args, $r->query->all());
        if ('DOCTOR' === $m['role'] && 'patients' === $resource) {
            $result['data'] = array_map($this->doctorPatient(...), $result['data']);
        }

        return new JsonResponse($result);
    }

    #[Route('/{resource}/{id}', requirements: ['resource' => 'patients|doctors|services'], methods: ['GET', 'PATCH'])]
    public function detail(Request $r, string $clinicId, string $resource, string $id): JsonResponse
    {
        [$u,$m] = $this->access($r, $clinicId, $resource, $r->isMethod('PATCH'));
        $table = self::TABLES[$resource];
        $row = $this->s->one($table, $clinicId, $id);
        if ('DOCTOR' === $m['role'] && 'patients' === $resource) {
            if (!$this->s->db->fetchOne('SELECT 1 FROM appointments WHERE clinic_id=? AND patient_id=? AND doctor_id=?', [$clinicId, $id, $m['doctor_id']])) {
                throw new ApiError(404, 'NOT_FOUND', 'Пацієнта не знайдено.');
            }
            $row = $this->doctorPatient($row);
        }
        if ($r->isMethod('PATCH')) {
            $data = $this->validate(array_replace($row, $r->toArray()), $resource, $clinicId);
            $this->s->db->transactional(function () use ($table, $data, $clinicId, $id, $u, $resource) {
                $this->s->db->update($table, $data, ['clinic_id' => $clinicId, 'id' => $id]);
                $this->s->audit($clinicId, $u['id'], 'UPDATED', $resource, $id, ['fields' => array_keys($data)]);
            });
            $row = $this->s->one($table, $clinicId, $id);
        }
        if ('doctors' === $resource) {
            $row['service_ids'] = $this->s->db->fetchFirstColumn('SELECT service_id FROM doctor_services WHERE clinic_id=? AND doctor_id=?', [$clinicId, $id]);
        }

        return new JsonResponse(['data' => $row, 'duplicates' => 'patients' === $resource && 'DOCTOR' !== $m['role'] ? $this->duplicates($clinicId, $row) : []]);
    }

    #[Route('/patients/{id}/archive', methods: ['POST'])]
    public function archive(Request $r, string $clinicId, string $id): JsonResponse
    {
        [$u] = $this->access($r, $clinicId, 'patients', true);
        $this->s->one('patients', $clinicId, $id);
        $this->s->db->transactional(function () use ($clinicId, $id, $u) {
            $this->s->db->update('patients', ['archived_at' => gmdate('c')], ['clinic_id' => $clinicId, 'id' => $id]);
            $this->s->audit($clinicId, $u['id'], 'ARCHIVED', 'patient', $id);
        });

        return new JsonResponse(null, 204);
    }

    #[Route('/doctors/{id}/services', methods: ['PUT'])]
    public function services(Request $r, string $clinicId, string $id): JsonResponse
    {
        [$u] = $this->access($r, $clinicId, 'doctors', true);
        $this->s->one('doctor_profiles', $clinicId, $id);
        $ids = $r->toArray()['service_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 200) {
            throw new ApiError(422, 'VALIDATION_ERROR', 'Потрібен список послуг.');
        }
        foreach ($ids as $sid) {
            $this->s->one('services', $clinicId, Input::uuid($sid));
        }
        $this->s->db->transactional(function () use ($clinicId, $id, $ids, $u) {
            $this->s->db->fetchOne('SELECT id FROM doctor_profiles WHERE clinic_id=? AND id=? FOR UPDATE', [$clinicId, $id]);
            $this->s->db->delete('doctor_services', ['clinic_id' => $clinicId, 'doctor_id' => $id]);
            foreach (array_unique($ids) as $sid) {
                $this->s->db->insert('doctor_services', ['clinic_id' => $clinicId, 'doctor_id' => $id, 'service_id' => $sid]);
            }
            $this->s->audit($clinicId, $u['id'], 'SERVICES_ASSIGNED', 'doctor', $id);
        });

        return new JsonResponse(['data' => $ids]);
    }

    private function doctorPatient(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'first_name', 'last_name', 'middle_name', 'phone_e164', 'email']));
    }

    private function duplicates(string $clinic, array $d): array
    {
        return $this->s->db->fetchAllAssociative('SELECT id,first_name,last_name FROM patients WHERE clinic_id=? AND id<>? AND archived_at IS NULL AND ((phone_e164 IS NOT NULL AND phone_e164=?) OR (email IS NOT NULL AND email=?))', [$clinic, $d['id'], $d['phone_e164'] ?? null, $d['email'] ?? null]);
    }

    private function validate(array $d, string $resource, string $clinic): array
    {
        $data = [];
        if ('services' === $resource) {
            $data = ['name' => Input::text($d, 'name'), 'description' => Input::text($d, 'description', 5000, false), 'duration_minutes' => Input::integer($d, 'duration_minutes', 5, 480), 'price_minor' => Input::integer($d, 'price_minor', 0, 1000000000)];
            $currency = $d['currency'] ?? $this->s->db->fetchOne('SELECT currency FROM clinics WHERE id=?', [$clinic]);
            if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new ApiError(422, 'INVALID_CURRENCY', 'Некоректна валюта.');
            } $data['currency'] = $currency;
        } else {
            foreach (['first_name', 'last_name'] as $f) {
                $data[$f] = Input::text($d, $f, 120);
            }
            if ('patients' === $resource) {
                $phone = $d['phone'] ?? $d['phone_e164'] ?? null;
                if (null !== $phone && !is_string($phone)) {
                    throw new ApiError(422, 'INVALID_PHONE', 'Некоректний телефон.');
                }
                $phone = $phone ? (string) new \App\Shared\Domain\PhoneNumber($phone) : null;
                $email = Input::text($d, 'email', 255, false) ?: null;
                if ($email) {
                    $email = (string) new \App\Shared\Domain\Email($email);
                }
                if (!$phone && !$email) {
                    throw new ApiError(422, 'CONTACT_REQUIRED', 'Вкажіть телефон або email.');
                }
                $birth = $d['birth_date'] ?? null;
                if ($birth) {
                    Input::date($birth);
                    if ($birth > date('Y-m-d')) {
                        throw new ApiError(422, 'INVALID_DATE', 'Дата народження не може бути в майбутньому.');
                    }
                }
                $data += ['phone_e164' => $phone, 'email' => $email ? strtolower($email) : null, 'birth_date' => $birth ?: null, 'middle_name' => Input::text($d, 'middle_name', 120, false), 'administrative_notes' => Input::text($d, 'administrative_notes', 5000, false)];
            } else {
                $data += ['specialization' => Input::text($d, 'specialization'), 'description' => Input::text($d, 'description', 5000, false)];
                $membership = $d['membership_id'] ?? null;
                if ($membership) {
                    $m = $this->s->one('clinic_memberships', $clinic, Input::uuid($membership));
                    if ('DOCTOR' !== $m['role']) {
                        throw new ApiError(422, 'INVALID_MEMBERSHIP', 'Потрібне членство лікаря.');
                    }
                }
                $data['membership_id'] = $membership ?: null;
            }
        }
        if ('patients' !== $resource) {
            if (isset($d['is_active']) && !is_bool($d['is_active'])) {
                throw new ApiError(422, 'VALIDATION_ERROR', 'Некоректний статус.');
            } $data['is_active'] = ($d['is_active'] ?? true) ? 1 : 0;
        }

        return $data;
    }
}
