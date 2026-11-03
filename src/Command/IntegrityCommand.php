<?php

declare(strict_types=1);

namespace App\Command;

use App\Shared\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:integrity:check', description: 'Read-only tenant, overlap, money and appointment integrity checks')]
final class IntegrityCommand extends Command
{
    public function __construct(private Store $s)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = [
            'tenant references' => 'SELECT count(*) FROM appointments a LEFT JOIN patients p ON p.clinic_id=a.clinic_id AND p.id=a.patient_id LEFT JOIN doctor_profiles d ON d.clinic_id=a.clinic_id AND d.id=a.doctor_id LEFT JOIN services s ON s.clinic_id=a.clinic_id AND s.id=a.service_id WHERE p.id IS NULL OR d.id IS NULL OR s.id IS NULL',
            'overlapping appointments' => "SELECT count(*) FROM appointments a JOIN appointments b ON a.doctor_id=b.doctor_id AND a.id<b.id AND a.starts_at<b.ends_at AND a.ends_at>b.starts_at WHERE a.status IN ('SCHEDULED','CONFIRMED') AND b.status IN ('SCHEDULED','CONFIRMED')",
            'overpaid appointments' => 'SELECT count(*) FROM (SELECT a.id FROM appointments a JOIN payments p ON p.appointment_id=a.id GROUP BY a.id HAVING sum(p.amount_minor)>a.price_minor_snapshot OR sum(p.amount_minor)<0) t',
            'currency mismatch' => 'SELECT count(*) FROM payments p JOIN appointments a ON a.id=p.appointment_id WHERE p.currency<>a.currency_snapshot',
        ];
        $bad = false;
        foreach ($checks as $label => $sql) {
            $count = (int) $this->s->db->fetchOne($sql);
            $output->writeln($label.': '.$count.' violations');
            if ($count) {
                $bad = true;
            }
        }

        return $bad ? Command::FAILURE : Command::SUCCESS;
    }
}
