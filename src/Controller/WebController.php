<?php

declare(strict_types=1);

namespace App\Controller;

use App\Shared\Store;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WebController
{
    public function __construct(private Store $s, #[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    #[Route('/health/live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        try {
            $this->s->db->fetchOne('SELECT 1 FROM clinics LIMIT 1');
            $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? '';
            if (str_starts_with($dsn, 'redis://')) {
                $parts = parse_url($dsn);
                $redis = new \Redis();
                $redis->connect($parts['host'], $parts['port'] ?? 6379, 2);
                if (!$redis->ping()) {
                    throw new \RuntimeException('Queue unavailable');
                }
                $redis->close();
            }

            return new JsonResponse(['status' => 'ready']);
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'unavailable'], 503);
        }
    }

    #[Route('/api/openapi.json', methods: ['GET'])]
    public function openapi(): BinaryFileResponse
    {
        return new BinaryFileResponse($this->root.'/docs/api/openapi.json', 200, ['Content-Type' => 'application/json']);
    }

    #[Route('/api/docs', methods: ['GET'])]
    public function docs(): Response
    {
        return new Response('<!doctype html><html><head><title>ClinicFlow API</title><link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css"></head><body><div id="swagger-ui"></div><script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script><script>SwaggerUIBundle({url:"/api/openapi.json",dom_id:"#swagger-ui"})</script></body></html>');
    }

    #[Route('/{path}', requirements: ['path' => '(?!api(?:/|$)|health(?:/|$)).*'], methods: ['GET'], priority: -100)]
    public function app(string $path = ''): Response
    {
        $base = $this->root.'/public/app/';
        if (str_starts_with($path, 'app/')) {
            $path = substr($path, 4);
        }
        if ($path && !str_contains($path, '..') && is_file($base.$path)) {
            $type = match (pathinfo($path, PATHINFO_EXTENSION)) {
                'js' => 'application/javascript','css' => 'text/css','svg' => 'image/svg+xml','woff2' => 'font/woff2','woff' => 'font/woff',default => 'application/octet-stream',
            };

            return new BinaryFileResponse($base.$path, 200, ['Content-Type' => $type]);
        }
        if (is_file($base.'index.html')) {
            return new Response(file_get_contents($base.'index.html'));
        }

        return new Response('ClinicFlow: run cd frontend && npm ci && npm run build', 503);
    }
}
