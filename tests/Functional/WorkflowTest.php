<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Id;
use App\Shared\Store;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WorkflowTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Store $s;
    private string $clinic;
    private string $doctor;
    private string $service;
    private string $patient;
    private string $actor;
    private string $start;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->s = static::getContainer()->get(Store::class);
        $this->s->db->executeStatement('DELETE FROM rate_limits');
        $this->login('admin');
        $this->actor = $this->s->db->fetchOne("SELECT id FROM users WHERE email='admin@clinicflow.test'");
        $this->clinic = Id::new();
        $this->doctor = Id::new();
        $this->service = Id::new();
        $this->patient = Id::new();
        $this->s->db->insert('clinics', ['id' => $this->clinic, 'name' => 'Test', 'slug' => 'test-'.$this->clinic]);
        $this->s->db->insert('clinic_memberships', ['id' => Id::new(), 'clinic_id' => $this->clinic, 'user_id' => $this->actor, 'role' => 'CLINIC_ADMIN']);
        $this->s->db->insert('doctor_profiles', ['id' => $this->doctor, 'clinic_id' => $this->clinic, 'first_name' => 'Test', 'last_name' => 'Doctor', 'specialization' => 'Test']);
        $this->s->db->insert('services', ['id' => $this->service, 'clinic_id' => $this->clinic, 'name' => 'Consultation', 'duration_minutes' => 30, 'price_minor' => 100000, 'currency' => 'UAH']);
        $this->s->db->insert('doctor_services', ['clinic_id' => $this->clinic, 'doctor_id' => $this->doctor, 'service_id' => $this->service]);
        $this->s->db->insert('patients', ['id' => $this->patient, 'clinic_id' => $this->clinic, 'first_name' => 'Test', 'last_name' => 'Patient', 'email' => 'synthetic@example.test']);
        for ($day = 1; $day <= 7; ++$day) {
            $this->s->db->insert('doctor_working_hours', ['id' => Id::new(), 'clinic_id' => $this->clinic, 'doctor_id' => $this->doctor, 'day_of_week' => $day, 'start_local_time' => '09:00', 'end_local_time' => '18:00']);
        }
        $this->start = (new \DateTimeImmutable('tomorrow 10:00', new \DateTimeZone('Europe/Kyiv')))->format('c');
    }

    private function login(string $role): array
    {
        $this->browser->jsonRequest('POST', '/api/v1/auth/login', ['email' => $role.'@clinicflow.test', 'password' => 'ClinicFlow!2026']);
        self::assertResponseIsSuccessful();
        $r = json_decode($this->browser->getResponse()->getContent(), true);
        $this->browser->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$r['data']['access_token']);

        return $r;
    }

    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $this->browser->jsonRequest($method, '/api/v1/clinics/'.$this->clinic.$path, $body, $headers);

        return json_decode($this->browser->getResponse()->getContent(), true) ?? [];
    }

    private function payload(?string $start = null): array
    {
        return ['patient_id' => $this->patient, 'doctor_id' => $this->doctor, 'service_id' => $this->service, 'starts_at' => $start ?? $this->start];
    }

    private function create(): array
    {
        $r = $this->request('POST', '/appointments', $this->payload());
        self::assertResponseStatusCodeSame(201);

        return $r['data'];
    }

    public function testBookingSnapshotIdempotencyAndConflict(): void
    {
        $headers = ['HTTP_IDEMPOTENCY_KEY' => 'repeat-me'];
        $a = $this->request('POST', '/appointments', $this->payload(), $headers)['data'];
        self::assertResponseStatusCodeSame(201);
        $b = $this->request('POST', '/appointments', $this->payload(), $headers)['data'];
        self::assertSame($a['id'], $b['id']);
        $this->request('POST', '/appointments', $this->payload((new \DateTimeImmutable($this->start))->modify('+15 minutes')->format('c')), $headers);
        self::assertResponseStatusCodeSame(409);
        $conflict = $this->request('POST', '/appointments', $this->payload());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SLOT_ALREADY_BOOKED', $conflict['error']['code']);
        $this->request('PATCH', '/services/'.$this->service, ['price_minor' => 150000, 'duration_minutes' => 60]);
        self::assertResponseIsSuccessful();
        $a = $this->request('GET', '/appointments/'.$a['id'])['data'];
        self::assertSame(100000, $a['price_minor_snapshot']);
        self::assertSame(30, $a['duration_minutes_snapshot']);
        $slots = $this->request('GET', '/availability?doctor_id='.$this->doctor.'&service_id='.$this->service.'&date='.substr($this->start, 0, 10).'&appointment_id='.$a['id'])['slots'];
        self::assertNotEmpty($slots);
        self::assertSame(1800, strtotime($slots[0]['ends_at']) - strtotime($slots[0]['starts_at']));
    }

    public function testCancelFreesSlotAndStatusIsFinal(): void
    {
        $a = $this->create();
        $this->request('POST', '/appointments/'.$a['id'].'/confirm', ['version' => 1]);
        self::assertResponseIsSuccessful();
        $this->request('POST', '/appointments/'.$a['id'].'/cancel', ['version' => 1]);
        self::assertResponseStatusCodeSame(409);
        $this->request('POST', '/appointments/'.$a['id'].'/cancel', ['version' => 2, 'reason' => 'Зміна планів']);
        self::assertResponseIsSuccessful();
        $this->create();
        $this->request('POST', '/appointments/'.$a['id'].'/confirm', ['version' => 3]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(3, (int) $this->s->db->fetchOne('SELECT count(*) FROM appointment_events WHERE appointment_id=?', [$a['id']]));
    }

    public function testAdjacentVisitsAndFailedRescheduleRollback(): void
    {
        $a = $this->create();
        $b = $this->request('POST', '/appointments', $this->payload((new \DateTimeImmutable($this->start))->modify('+30 minutes')->format('c')))['data'];
        self::assertResponseStatusCodeSame(201);
        $this->request('POST', '/appointments/'.$b['id'].'/reschedule', ['version' => 1, 'starts_at' => $a['starts_at']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->s->db->fetchOne('SELECT version FROM appointments WHERE id=?', [$b['id']]));
        self::assertSame(1, (int) $this->s->db->fetchOne('SELECT count(*) FROM appointment_events WHERE appointment_id=?', [$b['id']]));
    }

    public function testScheduleChangesRollbackWhenConflicting(): void
    {
        $this->create();
        $r = $this->request('PUT', '/doctors/'.$this->doctor.'/working-hours', ['intervals' => []]);
        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $r['error']['details']['appointment_ids']);
        self::assertSame(7, (int) $this->s->db->fetchOne('SELECT count(*) FROM doctor_working_hours WHERE doctor_id=?', [$this->doctor]));
    }

    public function testPaymentsPartialReversalAndOverpayment(): void
    {
        $a = $this->create();
        $url = '/appointments/'.$a['id'].'/payments';
        $p = $this->request('POST', $url, ['amount_minor' => 40000, 'currency' => 'UAH', 'method' => 'CASH'])['data'];
        self::assertResponseStatusCodeSame(201);
        $this->request('POST', $url, ['amount_minor' => 60000, 'currency' => 'UAH', 'method' => 'CARD_TERMINAL']);
        self::assertResponseStatusCodeSame(201);
        $r = $this->request('GET', $url);
        self::assertSame(100000, $r['paid_amount']);
        self::assertSame(0, $r['outstanding_amount']);
        $this->request('POST', $url, ['amount_minor' => 1, 'currency' => 'UAH', 'method' => 'CASH']);
        self::assertResponseStatusCodeSame(409);
        $this->request('POST', '/payments/'.$p['id'].'/reverse', []);
        self::assertResponseStatusCodeSame(201);
        $this->request('POST', '/payments/'.$p['id'].'/reverse', []);
        self::assertResponseStatusCodeSame(409);
        $r = $this->request('GET', $url);
        self::assertSame(60000, $r['paid_amount']);
        self::assertCount(3, $r['data']);
    }

    public function testReceptionCannotSeeAnotherTenantOrChangeRoles(): void
    {
        $this->login('reception');
        $this->request('GET', '/patients');
        self::assertResponseStatusCodeSame(404);
        $user = $this->s->db->fetchOne("SELECT id FROM users WHERE email='reception@clinicflow.test'");
        $this->s->db->insert('clinic_memberships', ['id' => Id::new(), 'clinic_id' => $this->clinic, 'user_id' => $user, 'role' => 'RECEPTIONIST']);
        $this->request('GET', '/patients');
        self::assertResponseIsSuccessful();
        $this->request('POST', '/memberships', ['email' => 'doctor@clinicflow.test', 'role' => 'CLINIC_ADMIN']);
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', '/analytics/overview');
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', '/audit-logs');
        self::assertResponseStatusCodeSame(403);
        $other = $this->s->db->fetchOne('SELECT id FROM patients WHERE clinic_id<>? LIMIT 1', [$this->clinic]);
        $this->request('GET', '/patients/'.$other);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDoctorSeesOnlyOwnPatientsAndCannotBook(): void
    {
        $a = $this->create();
        $user = $this->s->db->fetchOne("SELECT id FROM users WHERE email='doctor@clinicflow.test'");
        $membership = Id::new();
        $this->s->db->insert('clinic_memberships', ['id' => $membership, 'clinic_id' => $this->clinic, 'user_id' => $user, 'role' => 'DOCTOR']);
        $this->s->db->update('doctor_profiles', ['membership_id' => $membership], ['id' => $this->doctor]);
        $this->login('doctor');
        $r = $this->request('GET', '/patients');
        self::assertCount(1, $r['data']);
        self::assertArrayNotHasKey('administrative_notes', $r['data'][0]);
        $this->request('POST', '/appointments', $this->payload());
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/appointments/'.$a['id'].'/cancel', ['version' => 1]);
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/appointments/'.$a['id'].'/complete', ['version' => 1]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPlatformAdminDoesNotBypassClinicMembership(): void
    {
        $this->login('platform');
        $this->request('GET', '/patients');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRefreshRotationReuseRevokesFamily(): void
    {
        $cookie = $this->browser->getCookieJar()->get('clinicflow_refresh', '/api/v1/auth/refresh');
        self::assertNotNull($cookie);
        $old = $cookie->getValue();
        $this->browser->jsonRequest('POST', '/api/v1/auth/refresh');
        self::assertResponseIsSuccessful();
        $new = json_decode($this->browser->getResponse()->getContent(), true)['data']['access_token'];
        $this->browser->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('clinicflow_refresh', $old, null, '/api/v1/auth', 'localhost'));
        $this->browser->jsonRequest('POST', '/api/v1/auth/refresh');
        self::assertResponseStatusCodeSame(401);
        $this->browser->jsonRequest('GET', '/api/v1/me', [], ['HTTP_AUTHORIZATION' => 'Bearer '.$new]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDatabaseCompositeForeignKeyRejectsCrossTenantPatient(): void
    {
        $other = $this->s->db->fetchOne('SELECT id FROM patients WHERE clinic_id<>? LIMIT 1', [$this->clinic]);
        $this->request('POST', '/appointments', array_replace($this->payload(), ['patient_id' => $other]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testConcurrentBookingDatabaseGuarantee(): void
    {
        $env = ['APP_ENV' => 'test', 'CF_BOOKING_PAYLOAD' => json_encode(['clinic' => $this->clinic, 'actor' => $this->actor, 'data' => $this->payload()], JSON_THROW_ON_ERROR)];
        $worker = dirname(__DIR__).'/Integration/book.php';
        $one = new \Symfony\Component\Process\Process([PHP_BINARY, $worker], null, $env);
        $two = new \Symfony\Component\Process\Process([PHP_BINARY, $worker], null, $env);
        $one->start();
        $two->start();
        $one->wait();
        $two->wait();
        $results = [$one->getOutput(), $two->getOutput()];
        sort($results);
        self::assertSame(['201', '409'], $results, $one->getErrorOutput().$two->getErrorOutput());
        self::assertSame(1, (int) $this->s->db->fetchOne('SELECT count(*) FROM appointments WHERE clinic_id=?', [$this->clinic]));
        self::assertSame(1, (int) $this->s->db->fetchOne('SELECT count(*) FROM appointment_events WHERE clinic_id=?', [$this->clinic]));
    }

    public function testNotificationDedupAndStaleDelivery(): void
    {
        $a = $this->create();
        $command = static::getContainer()->get(\App\Command\NotificationsCommand::class);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute([]);
        $tester->execute([]);
        self::assertSame(1, (int) $this->s->db->fetchOne("SELECT count(*) FROM notification_deliveries WHERE appointment_id=? AND template_key='appointment.CREATED'", [$a['id']]));
        $id = $this->s->db->fetchOne("SELECT id FROM notification_deliveries WHERE appointment_id=? AND template_key='appointment.CREATED'", [$a['id']]);
        $handler = new \App\Notification\DeliveryHandler($this->s, new \Symfony\Component\Mailer\Mailer(new \Symfony\Component\Mailer\Transport\NullTransport()));
        $handler(new \App\Notification\Delivery($id));
        $handler(new \App\Notification\Delivery($id));
        self::assertSame(1, (int) $this->s->db->fetchOne('SELECT attempts FROM notification_deliveries WHERE id=?', [$id]));
        $this->request('POST', '/appointments/'.$a['id'].'/confirm', ['version' => 1]);
        $tester->execute([]);
        $id = $this->s->db->fetchOne("SELECT id FROM notification_deliveries WHERE appointment_id=? AND template_key='appointment.CONFIRM'", [$a['id']]);
        $this->request('POST', '/appointments/'.$a['id'].'/cancel', ['version' => 2]);
        $handler(new \App\Notification\Delivery($id));
        self::assertSame('SKIPPED', $this->s->db->fetchOne('SELECT status FROM notification_deliveries WHERE id=?', [$id]));
    }

    public function testDatabaseRejectsOverlapEvenWithoutApplication(): void
    {
        $a = $this->create();
        $a['id'] = Id::new();
        $a['starts_at'] = (new \DateTimeImmutable($a['starts_at']))->modify('+15 minutes')->format('c');
        $a['ends_at'] = (new \DateTimeImmutable($a['ends_at']))->modify('+15 minutes')->format('c');
        try {
            $this->s->db->insert('appointments', $a);
            self::fail('Database must reject overlapping intervals');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('23P01', $e->getSQLState());
        }
    }

    public function testPasswordResetIsOneTimeAndRevokesSessions(): void
    {
        $user = Id::new();
        $hash = static::getContainer()->get(\App\Identity\Identity::class)->hash('Original!Password123');
        $this->s->db->insert('users', ['id' => $user, 'email' => $user.'@example.test', 'first_name' => 'Reset', 'last_name' => 'Test', 'password_hash' => $hash]);
        $token = bin2hex(random_bytes(32));
        $this->s->db->insert('password_reset_tokens', ['token_hash' => hash('sha256', $token), 'user_id' => $user, 'expires_at' => (new \DateTimeImmutable('+30 minutes'))->format('c')]);
        $body = ['token' => $token, 'password' => 'Changed!Password123'];
        $this->browser->jsonRequest('POST', '/api/v1/auth/reset-password', $body);
        self::assertResponseIsSuccessful();
        $this->browser->jsonRequest('POST', '/api/v1/auth/reset-password', $body);
        self::assertResponseStatusCodeSame(422);
        $this->browser->jsonRequest('POST', '/api/v1/auth/login', ['email' => $user.'@example.test', 'password' => 'Changed!Password123']);
        self::assertResponseIsSuccessful();
    }

    public function testDeactivatedMembershipAndClinicAreRejected(): void
    {
        $this->s->db->update('clinic_memberships', ['status' => 'INACTIVE'], ['clinic_id' => $this->clinic, 'user_id' => $this->actor]);
        $this->request('GET', '/patients');
        self::assertResponseStatusCodeSame(404);
        $this->s->db->update('clinic_memberships', ['status' => 'ACTIVE'], ['clinic_id' => $this->clinic, 'user_id' => $this->actor]);
        $this->s->db->update('clinics', ['status' => 'SUSPENDED'], ['id' => $this->clinic]);
        $this->request('GET', '/patients');
        self::assertResponseStatusCodeSame(404);
    }
}
