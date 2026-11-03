<?php

declare(strict_types=1);

namespace App\Identity;

use App\Entity\User;
use App\Shared\ApiError;
use App\Shared\Id;
use App\Shared\Store;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class Identity
{
    public function __construct(private Store $s, private UserPasswordHasherInterface $hasher)
    {
    }

    public function hash(string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 200) {
            throw new ApiError(422, 'WEAK_PASSWORD', 'Пароль має містити від 12 до 200 символів.');
        }

        return $this->hasher->hashPassword(new User(), $password);
    }

    public function throttle(string $key, int $max = 8): void
    {
        $row = $this->s->db->fetchAssociative("INSERT INTO rate_limits(key) VALUES (?) ON CONFLICT(key) DO UPDATE SET hits = CASE WHEN rate_limits.window_start < now() - interval '15 minutes' THEN 1 ELSE rate_limits.hits + 1 END, window_start = CASE WHEN rate_limits.window_start < now() - interval '15 minutes' THEN now() ELSE rate_limits.window_start END RETURNING hits", [hash('sha256', $key)]);
        if ((int) $row['hits'] > $max) {
            throw new ApiError(429, 'RATE_LIMITED', 'Забагато спроб. Спробуйте через 15 хвилин.');
        }
    }

    public function user(Request $r): array
    {
        $header = $r->headers->get('Authorization', '');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $header, $m)) {
            throw new ApiError(401, 'UNAUTHENTICATED', 'Увійдіть у систему.');
        }
        $u = $this->s->db->fetchAssociative("SELECT u.* FROM users u JOIN access_tokens a ON a.user_id=u.id WHERE a.token_hash=? AND a.expires_at>now() AND u.status='ACTIVE'", [hash('sha256', $m[1])]);
        if (!$u) {
            throw new ApiError(401, 'SESSION_EXPIRED', 'Сесія закінчилась.');
        }

        return $u;
    }

    public function login(string $email, string $password, string $ip): array
    {
        $this->throttle('login:'.$ip, 30);
        $this->throttle('login-email:'.strtolower($email));
        $u = $this->s->db->fetchAssociative('SELECT * FROM users WHERE email=?', [strtolower(trim($email))]);
        $user = new User();
        // A real hash is verified even for unknown users to reduce timing disclosure.
        $user->password_hash = $u['password_hash'] ?? '$2y$12$eS0A7X5rQXyMyCSW.MI9LO7fmCqK4S2x8IYV3j0mUb9mcd7RXecE.';
        $valid = $this->hasher->isPasswordValid($user, $password);
        if (!$u || !$valid || 'ACTIVE' !== $u['status']) {
            $this->s->audit(null, null, 'LOGIN_FAILED', 'user', null);
            throw new ApiError(401, 'INVALID_CREDENTIALS', 'Неправильний email або пароль.');
        }
        $this->s->db->delete('rate_limits', ['key' => hash('sha256', 'login-email:'.strtolower($email))]);
        $this->s->audit(null, $u['id'], 'LOGIN', 'user', $u['id']);

        return $this->issue($u['id'], Id::new());
    }

    private function issue(string $user, string $family): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $id = Id::new();
        $this->s->db->insert('access_tokens', ['token_hash' => hash('sha256', $access), 'user_id' => $user, 'family_id' => $family, 'expires_at' => (new \DateTimeImmutable('+15 minutes'))->format('c')]);
        $this->s->db->insert('refresh_tokens', ['id' => $id, 'user_id' => $user, 'family_id' => $family, 'token_hash' => hash('sha256', $refresh), 'expires_at' => (new \DateTimeImmutable('+14 days'))->format('c')]);

        return ['access_token' => $access, 'refresh_token' => $refresh, 'refresh_id' => $id, 'expires_in' => 900];
    }

    public function refresh(?string $raw): array
    {
        $db = $this->s->db;
        // Commit family revocation even when reporting token reuse.
        $result = $db->transactional(function () use ($db, $raw) {
            $t = $db->fetchAssociative('SELECT r.*,u.status AS user_status FROM refresh_tokens r JOIN users u ON u.id=r.user_id WHERE token_hash=? FOR UPDATE OF r', [hash('sha256', $raw ?? '')]);
            if (!$t || 'ACTIVE' !== $t['user_status'] || new \DateTimeImmutable($t['expires_at']) < new \DateTimeImmutable()) {
                return null;
            }
            if ($t['revoked_at']) {
                $this->revokeFamily($t['family_id']);

                return null;
            }
            $next = $this->issue($t['user_id'], $t['family_id']);
            $db->update('refresh_tokens', ['revoked_at' => gmdate('c'), 'replaced_by_token_id' => $next['refresh_id']], ['id' => $t['id']]);

            return $next;
        });
        if (!$result) {
            throw new ApiError(401, 'INVALID_REFRESH', 'Увійдіть у систему знову.');
        }

        return $result;
    }

    public function logout(?string $raw): void
    {
        $family = $this->s->db->fetchOne('SELECT family_id FROM refresh_tokens WHERE token_hash=?', [hash('sha256', $raw ?? '')]);
        if ($family) {
            $this->revokeFamily($family);
        }
    }

    private function revokeFamily(string $family): void
    {
        $this->s->db->executeStatement('UPDATE refresh_tokens SET revoked_at=now() WHERE family_id=?', [$family]);
        $this->s->db->delete('access_tokens', ['family_id' => $family]);
    }

    public function publicUser(array $u): array
    {
        return array_intersect_key($u, array_flip(['id', 'email', 'first_name', 'last_name', 'platform_admin']));
    }

    public function clinics(string $user): array
    {
        return $this->s->db->fetchAllAssociative("SELECT c.*,m.role,m.id AS membership_id FROM clinics c JOIN clinic_memberships m ON c.id=m.clinic_id WHERE m.user_id=? AND m.status='ACTIVE' AND c.status='ACTIVE' ORDER BY c.name", [$user]);
    }

    public function clinic(array $user, string $clinic, array $roles = ['CLINIC_ADMIN', 'RECEPTIONIST', 'DOCTOR']): array
    {
        \App\Shared\Input::uuid($clinic);
        $m = $this->s->db->fetchAssociative("SELECT m.*,c.timezone,c.currency,c.booking_horizon_days,c.cancellation_hours FROM clinic_memberships m JOIN clinics c ON c.id=m.clinic_id WHERE m.user_id=? AND m.clinic_id=? AND m.status='ACTIVE' AND c.status='ACTIVE'", [$user['id'], $clinic]);
        if (!$m) {
            $this->s->audit(null, $user['id'], 'TENANT_ACCESS_DENIED', 'clinic', $clinic);
            throw new ApiError(404, 'NOT_FOUND', 'Клініку не знайдено.');
        }
        if (!in_array($m['role'], $roles, true)) {
            throw new ApiError(403, 'FORBIDDEN', 'Недостатньо прав для цієї дії.');
        }
        $m['doctor_id'] = $this->s->db->fetchOne('SELECT id FROM doctor_profiles WHERE clinic_id=? AND membership_id=?', [$clinic, $m['id']]) ?: null;

        return $m;
    }

    public function ownDoctor(array $membership, string $doctor): void
    {
        if ('DOCTOR' === $membership['role'] && $membership['doctor_id'] !== $doctor) {
            throw new ApiError(403, 'FORBIDDEN', 'Доступний лише власний розклад.');
        }
    }
}
