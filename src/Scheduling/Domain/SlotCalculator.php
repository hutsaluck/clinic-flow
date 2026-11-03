<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

final class SlotCalculator
{
    /**
     * Iterate real instants; repeated wall times retain distinct UTC offsets.
     *
     * @param list<array{day_of_week:int,start_local_time:string,end_local_time:string}> $hours
     * @param list<array{type:string,starts_at:string,ends_at:string}>                   $exceptions
     * @param list<array{starts_at:string,ends_at:string}>                               $busy
     *
     * @return list<array{starts_at:string,ends_at:string,local_start:string}>
     */
    public static function calculate(string $date, string $timezone, int $duration, array $hours, array $exceptions, array $busy, \DateTimeImmutable $now): array
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \App\Shared\Domain\DomainViolation('INVALID_DATE', 'Некоректна дата.');
        }
        $tz = new \DateTimeZone($timezone);
        $day = new \DateTimeImmutable($date.' 00:00:00', $tz);
        $end = $day->modify('+1 day');
        $slots = [];
        for ($ts = $day->getTimestamp(); $ts < $end->getTimestamp(); $ts += 60) {
            $start = (new \DateTimeImmutable('@'.$ts))->setTimezone($tz);
            if (0 !== (int) $start->format('i') % 15 || $start <= $now) {
                continue;
            }
            $finish = $ts + $duration * 60;
            $localEnd = (new \DateTimeImmutable('@'.$finish))->setTimezone($tz);
            $allowed = false;
            foreach ($hours as $h) {
                if ((int) $h['day_of_week'] !== (int) $start->format('N')) {
                    continue;
                }
                if ($start->format('Y-m-d') === $localEnd->format('Y-m-d') && $start->format('H:i:s') >= $h['start_local_time'] && $localEnd->format('H:i:s') <= $h['end_local_time']) {
                    // Every real minute must remain inside wall-clock hours, including an offset transition.
                    $inside = true;
                    for ($t = $ts; $t < $finish; $t += 60) {
                        $wall = (new \DateTimeImmutable('@'.$t))->setTimezone($tz)->format('H:i:s');
                        if ($wall < $h['start_local_time'] || $wall >= $h['end_local_time']) {
                            $inside = false;
                            break;
                        }
                    }
                    if ($inside) {
                        $allowed = true;
                    }
                }
            }
            foreach ($exceptions as $x) {
                if ('AVAILABLE_OVERRIDE' === $x['type'] && strtotime($x['starts_at']) <= $ts && strtotime($x['ends_at']) >= $finish) {
                    $allowed = true;
                }
            }
            if (!$allowed) {
                continue;
            }
            foreach ($exceptions as $x) {
                if ('UNAVAILABLE' === $x['type'] && strtotime($x['starts_at']) < $finish && strtotime($x['ends_at']) > $ts) {
                    $allowed = false;
                }
            }
            foreach ($busy as $b) {
                if (strtotime($b['starts_at']) < $finish && strtotime($b['ends_at']) > $ts) {
                    $allowed = false;
                }
            }
            if ($allowed) {
                $slots[] = ['starts_at' => gmdate('Y-m-d\TH:i:s\Z', $ts), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $finish), 'local_start' => $start->format('c')];
            }
        }

        return $slots;
    }
}
