<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\Billing;
use App\Identity\Identity;
use App\Shared\ApiError;
use App\Shared\Input;
use App\Shared\Store;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/clinics/{clinicId}')]
final class ReportsController
{
    public function __construct(private Identity $identity, private Store $s, private Billing $billing)
    {
    }

    #[Route('/appointments/{id}/payments', methods: ['GET', 'POST'])]
    public function payments(Request $r, string $clinicId, string $id): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN', 'RECEPTIONIST']);
        $a = $this->s->one('appointments', $clinicId, $id);
        if ($r->isMethod('POST')) {
            return new JsonResponse(['data' => $this->billing->record(\App\Billing\Presentation\PaymentRequest::record($clinicId, $u['id'], $id, $r->toArray()))], 201);
        }
        $rows = $this->s->db->fetchAllAssociative('SELECT * FROM payments WHERE clinic_id=? AND appointment_id=? ORDER BY received_at', [$clinicId, $id]);
        $paid = array_sum(array_column($rows, 'amount_minor'));

        return new JsonResponse(['data' => $rows, 'paid_amount' => $paid, 'outstanding_amount' => (int) $a['price_minor_snapshot'] - $paid]);
    }

    #[Route('/payments/{id}/reverse', methods: ['POST'])]
    public function reverse(Request $r, string $clinicId, string $id): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN', 'RECEPTIONIST']);
        $p = $this->s->one('payments', $clinicId, $id);

        return new JsonResponse(['data' => $this->billing->reverse(\App\Billing\Presentation\PaymentRequest::reverse($clinicId, $u['id'], $id, $r->getContent() ? $r->toArray() : []))], 201);
    }

    #[Route('/audit-logs', methods: ['GET'])]
    public function audit(Request $r, string $clinicId): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN']);

        return new JsonResponse($this->s->page('SELECT a.*,concat_ws(\' \',u.first_name,u.last_name) AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id WHERE a.clinic_id=? ORDER BY occurred_at DESC,id', [$clinicId], $r->query->all()));
    }

    #[Route('/analytics/{kind}', requirements: ['kind' => 'overview|doctors|services'], methods: ['GET'])]
    public function analytics(Request $r, string $clinicId, string $kind): JsonResponse
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN']);
        $from = Input::instant($r->query->get('from', gmdate('Y-m-01\T00:00:00\Z')))->format('c');
        $to = Input::instant($r->query->get('to', (new \DateTimeImmutable('first day of next month'))->format('Y-m-d\T00:00:00\Z')))->format('c');
        if (strtotime($from) >= strtotime($to) || strtotime($to) - strtotime($from) > 366 * 86400) {
            throw new ApiError(422, 'INVALID_INTERVAL', 'Некоректний період.');
        }
        $where = 'a.clinic_id=? AND a.starts_at>=? AND a.starts_at<?';
        $args = [$clinicId, $from, $to];
        foreach (['doctor_id', 'service_id'] as $f) {
            if ($v = $r->query->get($f)) {
                $where .= " AND a.$f=?";
                $args[] = Input::uuid($v);
            }
        }
        if ('services' === $kind) {
            return new JsonResponse(['data' => $this->s->db->fetchAllAssociative("SELECT service_id,service_name_snapshot AS name,count(*) AS appointments,sum(price_minor_snapshot) AS expected_minor,currency_snapshot AS currency FROM appointments a WHERE $where GROUP BY service_id,service_name_snapshot,currency_snapshot ORDER BY appointments DESC", $args)]);
        }
        if ('doctors' === $kind) {
            $rows = $this->s->db->fetchAllAssociative("SELECT d.id,concat_ws(' ',d.last_name,d.first_name) AS name,count(a.id) AS appointments,COALESCE(sum(EXTRACT(EPOCH FROM (a.ends_at-a.starts_at))/60) FILTER(WHERE a.status IN ('SCHEDULED','CONFIRMED','COMPLETED')),0) AS booked_minutes FROM doctor_profiles d LEFT JOIN appointments a ON a.doctor_id=d.id AND $where WHERE d.clinic_id=? GROUP BY d.id ORDER BY name", [...$args, $clinicId]);
            $tz = new \DateTimeZone($this->s->db->fetchOne('SELECT timezone FROM clinics WHERE id=?', [$clinicId]));
            foreach ($rows as &$row) {
                $hours = $this->s->db->fetchAllAssociative('SELECT * FROM doctor_working_hours WHERE clinic_id=? AND doctor_id=?', [$clinicId, $row['id']]);
                $exceptions = $this->s->db->fetchAllAssociative('SELECT * FROM doctor_schedule_exceptions WHERE clinic_id=? AND doctor_id=? AND starts_at<? AND ends_at>?', [$clinicId, $row['id'], $to, $from]);
                $available = 0;
                // Denominator counts real minutes in the union of hours/overrides minus absences (DST safe).
                for ($t = strtotime($from); $t < strtotime($to); $t += 60) {
                    $local = (new \DateTimeImmutable('@'.$t))->setTimezone($tz);
                    $ok = false;
                    foreach ($hours as $h) {
                        if ((int) $h['day_of_week'] === (int) $local->format('N') && $local->format('H:i:s') >= $h['start_local_time'] && $local->format('H:i:s') < $h['end_local_time']) {
                            $ok = true;
                        }
                    }
                    foreach ($exceptions as $x) {
                        if ('AVAILABLE_OVERRIDE' === $x['type'] && strtotime($x['starts_at']) <= $t && strtotime($x['ends_at']) > $t) {
                            $ok = true;
                        }
                    }
                    foreach ($exceptions as $x) {
                        if ('UNAVAILABLE' === $x['type'] && strtotime($x['starts_at']) <= $t && strtotime($x['ends_at']) > $t) {
                            $ok = false;
                        }
                    }
                    if ($ok) {
                        ++$available;
                    }
                }
                $row['available_minutes'] = $available;
                $row['utilization_percent'] = $available ? round((float) $row['booked_minutes'] / $available * 100, 1) : 0;
            }

            return new JsonResponse(['data' => $rows]);
        }
        $stats = $this->s->db->fetchAssociative("SELECT count(*) AS total,count(*) FILTER(WHERE status='COMPLETED') AS completed,count(*) FILTER(WHERE status='CANCELLED') AS cancelled,count(*) FILTER(WHERE status='NO_SHOW') AS no_show FROM appointments a WHERE $where", $args);
        $stats['created_count'] = (int) $this->s->db->fetchOne('SELECT count(*) FROM appointments WHERE clinic_id=? AND created_at>=? AND created_at<?', [$clinicId, $from, $to]);
        $stats['expected_by_currency'] = $this->s->db->fetchAllAssociative("SELECT currency_snapshot AS currency,sum(price_minor_snapshot) AS amount_minor FROM appointments a WHERE $where AND status<>'CANCELLED' GROUP BY currency_snapshot", $args);
        $paymentWhere = 'p.clinic_id=? AND p.received_at>=? AND p.received_at<?';
        $paymentArgs = [$clinicId, $from, $to];
        foreach (['doctor_id', 'service_id'] as $f) {
            if ($v = $r->query->get($f)) {
                $paymentWhere .= " AND a.$f=?";
                $paymentArgs[] = $v;
            }
        }
        $stats['payments'] = $this->s->db->fetchAllAssociative("SELECT p.currency,p.method,sum(p.amount_minor) AS amount_minor FROM payments p JOIN appointments a ON a.clinic_id=p.clinic_id AND a.id=p.appointment_id WHERE $paymentWhere GROUP BY p.currency,p.method", $paymentArgs);

        return new JsonResponse(['data' => $stats]);
    }

    #[Route('/reports/appointments.csv', methods: ['GET'])]
    public function export(Request $r, string $clinicId): Response
    {
        $u = $this->identity->user($r);
        $this->identity->clinic($u, $clinicId, ['CLINIC_ADMIN', 'RECEPTIONIST']);
        $from = Input::instant($r->query->get('from', gmdate('Y-m-01\T00:00:00\Z')))->format('c');
        $to = Input::instant($r->query->get('to', (new \DateTimeImmutable('+1 month'))->format('c')))->format('c');
        $rows = $this->s->db->fetchAllAssociative('SELECT id,starts_at,ends_at,status,service_name_snapshot,price_minor_snapshot,currency_snapshot FROM appointments WHERE clinic_id=? AND starts_at>=? AND starts_at<? ORDER BY starts_at', [$clinicId, $from, $to]);
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['ID', 'Початок UTC', 'Кінець UTC', 'Статус', 'Послуга', 'Сума в копійках', 'Валюта'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($f, array_map(static fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, array_values($row)), ',', '"', '');
        }
        rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);
        $this->s->audit($clinicId, $u['id'], 'CSV_EXPORTED', 'report', null);

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="appointments.csv"']);
    }
}
