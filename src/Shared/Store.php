<?php

declare(strict_types=1);

namespace App\Shared;

use Doctrine\DBAL\Connection;

final class Store
{
    public function __construct(public readonly Connection $db)
    {
    }

    public function one(string $table, string $clinic, string $id): array
    {
        Input::uuid($id);
        $row = $this->db->fetchAssociative("SELECT * FROM $table WHERE clinic_id = ? AND id = ?", [$clinic, $id]);
        if (!$row) {
            throw new ApiError(404, 'NOT_FOUND', 'Об’єкт не знайдено.');
        }

        return $row;
    }

    public function audit(?string $clinic, ?string $actor, string $action, string $type, ?string $id, array $changes = []): void
    {
        $this->db->insert('audit_logs', ['id' => Id::new(), 'clinic_id' => $clinic, 'actor_user_id' => $actor, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'correlation_id' => RequestContext::$id]);
    }

    public function order(array $query, array $allowed, string $default): string
    {
        $field = $query['sort'] ?? $default;
        $direction = strtoupper($query['order'] ?? 'ASC');
        if (!isset($allowed[$field]) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new ApiError(422, 'INVALID_SORT', 'Некоректне сортування.');
        }

        return ' ORDER BY '.$allowed[$field].' '.$direction.',id '.$direction;
    }

    public function page(string $sql, array $args, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $size = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $total = (int) $this->db->fetchOne("SELECT count(*) FROM ($sql) result", $args);

        return ['data' => $this->db->fetchAllAssociative($sql.' LIMIT '.$size.' OFFSET '.(($page - 1) * $size), $args), 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'total_pages' => (int) ceil($total / $size)]];
    }
}
