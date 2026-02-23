<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../services/DatabaseService.php';

class AdminMiddleware
{
    public static function check(int $userId): void
    {
        $db = DatabaseService::getInstance();
        $stmt = $db->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        $role = strtolower((string)($row['role'] ?? ''));
        if ($role !== 'admin') {
            json_error('Admin requis', 403);
        }
    }
}

