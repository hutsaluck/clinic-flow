<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('test', false);
$kernel->boot();
$payload = json_decode(getenv('CF_BOOKING_PAYLOAD'), true, 512, JSON_THROW_ON_ERROR);
try {
    $kernel->getContainer()->get('test.service_container')->get(App\Appointment\Bookings::class)->create(App\Appointment\Presentation\AppointmentRequest::create($payload['clinic'], $payload['actor'], $payload['data'], null));
    echo '201';
} catch (App\Shared\Domain\DomainViolation $e) {
    echo 'SLOT_ALREADY_BOOKED' === $e->errorCode ? '409' : '422';
} catch (App\Shared\ApiError $e) {
    echo $e->status;
}
