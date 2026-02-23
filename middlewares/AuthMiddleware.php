<?php

require_once __DIR__ . '/../utils/request.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../services/DatabaseService.php';

class AuthMiddleware
{
    public static function check(): int
    {
        $sid = get_session_id();
        if (!$sid) {
            json_error('Non authentifie', 401);
        }

        $db = DatabaseService::getInstance();
        $stmt = $db->prepare(
            'SELECT s.user_id
             FROM sessions s
             WHERE s.id = :sid
               AND s.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['sid' => $sid]);
        $row = $stmt->fetch();

        if (!$row || empty($row['user_id'])) {
            json_error('Session invalide ou expiree', 401);
        }

        return (int)$row['user_id'];
    }
}

