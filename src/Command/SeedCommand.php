<?php

declare(strict_types=1);

namespace App\Command;

use App\Identity\Identity;
use App\Shared\Id;
use App\Shared\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:demo:seed', description: 'Create synthetic demo data (idempotent; --full: 5 clinics, 50 doctors, 5000 patients, 30000 visits)')]
final class SeedCommand extends Command
{
    public function __construct(private Store $s, private Identity $identity, #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.debug%')] private bool $debug)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Portfolio dataset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('full') && $this->debug) {
            $output->writeln('Large seed requires --no-debug to keep Doctrine profiling memory bounded. Re-run: php bin/console app:demo:seed --full --no-debug');

            return Command::FAILURE;
        }
        if ($this->s->db->fetchOne("SELECT id FROM users WHERE email='admin@clinicflow.test'")) {
            $output->writeln('Demo already exists; no data changed.');

            return Command::SUCCESS;
        }
        $full = $input->getOption('full');
        $hash = $this->identity->hash('ClinicFlow!2026');
        $this->s->db->transactional(function () use ($full, $hash) {
            $users = [];
            foreach (['admin', 'reception', 'doctor', 'platform'] as $i => $role) {
                $id = Id::new();
                $users[$role] = $id;
                $this->s->db->insert('users', ['id' => $id, 'email' => $role.'@clinicflow.test', 'password_hash' => $hash, 'first_name' => ['Олена', 'Марія', 'Андрій', 'Тарас'][$i], 'last_name' => ['Коваль', 'Мельник', 'Шевченко', 'Тестовий'][$i], 'platform_admin' => 'platform' === $role ? 1 : 0]);
            }
            $first = ['Олександр', 'Анна', 'Марія', 'Дмитро', 'Ірина', 'Андрій', 'Софія', 'Віктор', 'Олена', 'Максим'];
            $last = ['Коваль', 'Мельник', 'Шевченко', 'Бондар', 'Ткаченко', 'Кравченко', 'Олійник', 'Шевчук', 'Поліщук', 'Лисенко'];
            for ($c = 0; $c < ($full ? 5 : 2); ++$c) {
                $clinic = Id::new();
                $this->s->db->insert('clinics', ['id' => $clinic, 'name' => ['Клініка «Добробут»', 'Медичний центр «Гармонія»', 'Клініка «Віта»', 'Центр «Здоров’я»', 'Клініка «Медлайн»'][$c], 'slug' => 'demo-'.($c + 1), 'timezone' => ['Europe/Kyiv', 'Europe/Warsaw', 'Europe/London', 'America/New_York', 'Europe/Kyiv'][$c], 'email' => 'clinic'.($c + 1).'@clinicflow.test', 'phone' => '+380441234560', 'address' => 'Київ, вул. Демонстраційна, '.($c + 1)]);
                $members = [];
                foreach (['admin' => 'CLINIC_ADMIN', 'reception' => 'RECEPTIONIST', 'doctor' => 'DOCTOR'] as $role => $r) {
                    if ($c > 0 && 'admin' !== $role) {
                        continue;
                    }
                    $members[$role] = Id::new();
                    $this->s->db->insert('clinic_memberships', ['id' => $members[$role], 'clinic_id' => $clinic, 'user_id' => $users[$role], 'role' => $r]);
                }
                $services = [];
                $serviceRows = [];
                foreach (['Консультація терапевта', 'Консультація кардіолога', 'УЗД діагностика', 'Консультація невролога', 'Повторна консультація'] as $i => $name) {
                    $id = Id::new();
                    $services[] = $id;
                    $this->s->db->insert('services', ['id' => $id, 'clinic_id' => $clinic, 'name' => $name, 'description' => 'Демонстраційна медична послуга', 'duration_minutes' => [30, 45, 60, 30, 15][$i], 'price_minor' => [85000, 120000, 95000, 110000, 65000][$i], 'currency' => 'UAH']);
                    $serviceRows[$id] = $this->s->one('services', $clinic, $id);
                }
                $doctors = [];
                for ($n = 0; $n < ($full ? 10 : 4); ++$n) {
                    $id = Id::new();
                    $doctors[] = $id;
                    $this->s->db->insert('doctor_profiles', ['id' => $id, 'clinic_id' => $clinic, 'membership_id' => 0 === $n ? ($members['doctor'] ?? null) : null, 'first_name' => 0 === $n ? 'Андрій' : $first[($n + 2) % 10], 'last_name' => 0 === $n ? 'Шевченко' : $last[($n + 3) % 10], 'specialization' => ['Терапевт', 'Кардіолог', 'Лікар УЗД', 'Невролог'][$n % 4]]);
                    foreach ($services as $sid) {
                        $this->s->db->insert('doctor_services', ['clinic_id' => $clinic, 'doctor_id' => $id, 'service_id' => $sid]);
                    }
                    for ($day = 1; $day <= 7; ++$day) {
                        foreach ([['09:00', '13:00'], ['14:00', '18:00']] as [$start,$end]) {
                            $this->s->db->insert('doctor_working_hours', ['id' => Id::new(), 'clinic_id' => $clinic, 'doctor_id' => $id, 'day_of_week' => $day, 'start_local_time' => $start, 'end_local_time' => $end]);
                        }
                    }
                }
                $patients = [];
                for ($n = 0; $n < ($full ? 1000 : 36); ++$n) {
                    $id = Id::new();
                    $patients[] = $id;
                    $this->s->db->insert('patients', ['id' => $id, 'clinic_id' => $clinic, 'first_name' => $first[$n % 10], 'last_name' => $last[(int) ($n / 10) % 10].($n >= 100 ? ' '.($n + 1) : ''), 'phone_e164' => '+38050'.str_pad((string) ($c * 100000 + $n), 7, '0', STR_PAD_LEFT), 'email' => 'patient'.$c.'.'.$n.'@example.test', 'birth_date' => (1980 + $n % 25).'-'.str_pad((string) ($n % 12 + 1), 2, '0', STR_PAD_LEFT).'-15']);
                }
                $count = $full ? 6000 : 120;
                $tz = new \DateTimeZone(['Europe/Kyiv', 'Europe/Warsaw', 'Europe/London', 'America/New_York', 'Europe/Kyiv'][$c]);
                for ($n = 0; $n < $count; ++$n) {
                    $doctor = $doctors[$n % count($doctors)];
                    $slot = (int) floor($n / count($doctors));
                    $day = (int) floor($slot / 8);
                    $hourSlot = $slot % 8;
                    $start = (new \DateTimeImmutable('today', $tz))->modify(($full ? $day - 65 : $day - 1).' days')->setTime(9 + $hourSlot + ($hourSlot >= 4 ? 1 : 0), 0);
                    $status = $start < new \DateTimeImmutable() ? ['COMPLETED', 'COMPLETED', 'COMPLETED', 'CANCELLED', 'NO_SHOW'][$n % 5] : (0 === $n % 3 ? 'SCHEDULED' : 'CONFIRMED');
                    $sid = $services[$n % 5];
                    $service = $serviceRows[$sid];
                    $id = Id::new();
                    $this->s->db->insert('appointments', ['id' => $id, 'clinic_id' => $clinic, 'patient_id' => $patients[$n % count($patients)], 'doctor_id' => $doctor, 'service_id' => $sid, 'starts_at' => $start->format('c'), 'ends_at' => $start->modify('+'.$service['duration_minutes'].' minutes')->format('c'), 'status' => $status, 'service_name_snapshot' => $service['name'], 'duration_minutes_snapshot' => $service['duration_minutes'], 'price_minor_snapshot' => $service['price_minor'], 'currency_snapshot' => 'UAH', 'created_by_user_id' => $users['admin']]);
                    $this->s->db->insert('appointment_events', ['id' => Id::new(), 'clinic_id' => $clinic, 'appointment_id' => $id, 'actor_user_id' => $users['admin'], 'event_type' => 'CREATED']);
                    if ('COMPLETED' === $status) {
                        $paymentId = Id::new();
                        $amount = 0 === $n % 3 ? intdiv((int) $service['price_minor'], 2) : (int) $service['price_minor'];
                        $this->s->db->insert('payments', ['id' => $paymentId, 'clinic_id' => $clinic, 'appointment_id' => $id, 'amount_minor' => $amount, 'currency' => 'UAH', 'method' => 0 === $n % 2 ? 'CASH' : 'CARD_TERMINAL', 'type' => 'PAYMENT', 'created_by_user_id' => $users['reception']]);
                        if (0 === $n % 20) {
                            $this->s->db->insert('payments', ['id' => Id::new(), 'clinic_id' => $clinic, 'appointment_id' => $id, 'amount_minor' => -$amount, 'currency' => 'UAH', 'method' => 'CASH', 'type' => 'REVERSAL', 'reverses_payment_id' => $paymentId, 'created_by_user_id' => $users['reception'], 'comment' => 'Синтетичне сторнування для демонстрації']);
                        }
                    }
                }
            }
        });
        $output->writeln('Synthetic demo ready. admin / reception / doctor / platform @clinicflow.test — ClinicFlow!2026');

        return Command::SUCCESS;
    }
}
