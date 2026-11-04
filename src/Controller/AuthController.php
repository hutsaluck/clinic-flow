<?php

declare(strict_types=1);

namespace App\Controller;

use App\Identity\Identity;
use App\Shared\ApiError;
use App\Shared\Input;
use App\Shared\Store;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
final class AuthController
{
    public function __construct(private Identity $identity, private Store $s, private MailerInterface $mailer, #[Autowire('%env(MAILER_FROM)%')] private string $sender)
    {
    }

    private function session(array $tokens, Request $r): JsonResponse
    {
        $response = new JsonResponse(['data' => ['access_token' => $tokens['access_token'], 'expires_in' => 900]]);
        $response->headers->setCookie(Cookie::create('clinicflow_refresh', $tokens['refresh_token'])->withExpires(new \DateTimeImmutable('+14 days'))->withPath('/api/v1/auth')->withHttpOnly(true)->withSecure($r->isSecure())->withSameSite('strict'));

        return $response;
    }

    private function sameOrigin(Request $r): void
    {
        $origin = $r->headers->get('Origin');
        if (($origin && $origin !== $r->getSchemeAndHttpHost()) || 'cross-site' === $r->headers->get('Sec-Fetch-Site')) {
            throw new ApiError(403, 'INVALID_ORIGIN', 'Недозволене джерело запиту.');
        }
    }

    #[Route('/auth/login', methods: ['POST'])]
    public function login(Request $r): JsonResponse
    {
        $this->sameOrigin($r);
        $d = $r->toArray();

        return $this->session($this->identity->login(Input::text($d, 'email'), Input::text($d, 'password', 200), $r->getClientIp() ?? 'unknown'), $r);
    }

    #[Route('/auth/refresh', methods: ['POST'])]
    public function refresh(Request $r): JsonResponse
    {
        $this->sameOrigin($r);

        return $this->session($this->identity->refresh($r->cookies->get('clinicflow_refresh')), $r);
    }

    #[Route('/auth/logout', methods: ['POST'])]
    public function logout(Request $r): JsonResponse
    {
        $this->sameOrigin($r);
        $this->identity->logout($r->cookies->get('clinicflow_refresh'));
        $response = new JsonResponse(null, 204);
        $response->headers->clearCookie('clinicflow_refresh', '/api/v1/auth');

        return $response;
    }

    #[Route('/me', methods: ['GET', 'PATCH'])]
    public function me(Request $r): JsonResponse
    {
        $u = $this->identity->user($r);
        if ($r->isMethod('PATCH')) {
            $d = $r->toArray();
            $changes = [];
            foreach (['first_name', 'last_name'] as $f) {
                if (array_key_exists($f, $d)) {
                    $changes[$f] = Input::text($d, $f, 120);
                }
            }
            if ($changes) {
                $this->s->db->update('users', $changes, ['id' => $u['id']]);
            }
            $u = array_replace($u, $changes);
        }

        return new JsonResponse(['data' => $this->identity->publicUser($u), 'clinics' => $this->identity->clinics($u['id'])]);
    }

    #[Route('/auth/forgot-password', methods: ['POST'])]
    public function forgot(Request $r): JsonResponse
    {
        $this->sameOrigin($r);
        $this->identity->throttle('reset:'.$r->getClientIp(), 5);
        $email = strtolower(Input::text($r->toArray(), 'email'));
        $u = $this->s->db->fetchAssociative("SELECT id FROM users WHERE email=? AND status='ACTIVE'", [$email]);
        if ($u) {
            $token = bin2hex(random_bytes(32));
            $this->s->db->insert('password_reset_tokens', ['token_hash' => hash('sha256', $token), 'user_id' => $u['id'], 'expires_at' => (new \DateTimeImmutable('+30 minutes'))->format('c')]);
            $url = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/').'/?reset_token='.$token;
            $this->mailer->send((new Email())->from($this->sender)->to($email)->subject('ClinicFlow — відновлення пароля')->text('Встановіть новий пароль (посилання діє 30 хвилин): '.$url));
        }

        return new JsonResponse(['data' => ['message' => 'Якщо обліковий запис існує, ми надіслали лист.']]);
    }

    #[Route('/auth/reset-password', methods: ['POST'])]
    public function reset(Request $r): JsonResponse
    {
        $this->sameOrigin($r);
        $this->identity->throttle('reset-submit:'.$r->getClientIp(), 10);
        $d = $r->toArray();
        $hash = $this->identity->hash(Input::text($d, 'password', 200));
        $token = hash('sha256', Input::text($d, 'token', 100));
        $this->s->db->transactional(function () use ($hash, $token) {
            $t = $this->s->db->fetchAssociative('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>now() FOR UPDATE', [$token]);
            if (!$t) {
                throw new ApiError(422, 'INVALID_RESET_TOKEN', 'Посилання недійсне або вже використане.');
            }
            $this->s->db->update('users', ['password_hash' => $hash], ['id' => $t['user_id']]);
            $this->s->db->executeStatement('UPDATE password_reset_tokens SET used_at=now() WHERE user_id=?', [$t['user_id']]);
            $this->s->db->executeStatement('UPDATE refresh_tokens SET revoked_at=now() WHERE user_id=?', [$t['user_id']]);
            $this->s->db->delete('access_tokens', ['user_id' => $t['user_id']]);
        });

        return new JsonResponse(['data' => ['message' => 'Пароль змінено. Увійдіть знову.']]);
    }
}
