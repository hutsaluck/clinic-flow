<?php

declare(strict_types=1);

namespace App\Shared;

use Doctrine\DBAL\Exception\ConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['request', 100], 'kernel.exception' => 'exception', 'kernel.response' => 'response'];
    }

    public function request(RequestEvent $e): void
    {
        if ($e->isMainRequest()) {
            RequestContext::$id = Id::new();
        }
    }

    public function response(ResponseEvent $e): void
    {
        $e->getResponse()->headers->set('X-Request-ID', RequestContext::$id);
        $e->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
        $e->getResponse()->headers->set('Referrer-Policy', 'same-origin');
        if (str_starts_with($e->getRequest()->getPathInfo(), '/api/')) {
            $e->getResponse()->headers->set('Cache-Control', 'no-store');
        }
    }

    public function exception(ExceptionEvent $e): void
    {
        if (!str_starts_with($e->getRequest()->getPathInfo(), '/api/') && !str_starts_with($e->getRequest()->getPathInfo(), '/health/')) {
            return;
        }
        $x = $e->getThrowable();
        if ($x instanceof Domain\DomainViolation) {
            $status = match ($x->errorCode) {
                'NOT_FOUND' => 404,
                'SLOT_ALREADY_BOOKED','VERSION_CONFLICT','INVALID_TRANSITION','VISIT_NOT_STARTED','CANCELLATION_POLICY','IDEMPOTENCY_CONFLICT','OVERPAYMENT','ALREADY_REVERSED' => 409,
                default => 422,
            };
            $x = new ApiError($status, $x->errorCode, $x->getMessage());
        }
        $status = $x instanceof ApiError ? $x->status : ($x instanceof ConstraintViolationException ? 409 : ($x instanceof HttpExceptionInterface ? $x->getStatusCode() : 500));
        $code = $x instanceof ApiError ? $x->errorCode : (409 === $status ? 'CONSTRAINT_CONFLICT' : 'REQUEST_ERROR');
        $message = $x instanceof ApiError ? $x->getMessage() : (500 === $status ? 'Внутрішня помилка сервера.' : 'Запит неможливо виконати.');
        $e->setResponse(new JsonResponse(['error' => ['code' => $code, 'message' => $message, 'details' => $x instanceof ApiError ? $x->details : []], 'request_id' => RequestContext::$id], $status));
    }
}
