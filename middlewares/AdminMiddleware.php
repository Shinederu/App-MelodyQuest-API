<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../services/DatabaseService.php';

class AdminMiddleware
{
    public static function check(int $userId): void
    {
        $db = DatabaseService::getInstance();

        $hasIsAdmin = self::usersHasIsAdminColumn($db);

        if ($hasIsAdmin) {
            $stmt = $db->prepare('SELECT role, is_admin FROM users WHERE id = :id LIMIT 1');
        } else {
            $stmt = $db->prepare('SELECT role, NULL AS is_admin FROM users WHERE id = :id LIMIT 1');
        }

        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        $role = strtolower((string)($row['role'] ?? ''));
        $isAdminRaw = $row['is_admin'] ?? null;
        $isAdmin = self::toBool($isAdminRaw) || $role === 'admin';

        if (!$isAdmin) {
            json_error('Admin requis', 403);
        }
    }

    private static function usersHasIsAdminColumn(PDO $db): bool
    {
        $stmt = $db->query(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'is_admin'
             LIMIT 1"
        );

        return (bool)$stmt->fetch();
    }

    private static function toBool($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        $v = strtolower(trim((string)$value));
        return in_array($v, ['1', 'true', 'yes', 'on', 'admin'], true);
    }
}
