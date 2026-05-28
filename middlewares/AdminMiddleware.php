<?php

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../services/DatabaseService.php';
require_once __DIR__ . '/../../core/services/ProjectAccessService.php';

class AdminMiddleware
{
    public static function check(int $userId): void
    {
        $db = DatabaseService::getInstance();
        $accessService = new ProjectAccessService($db);

        if (!$accessService->hasPermission($userId, 'melodyquest', 'catalog.manage')) {
            json_error('Admin requis', 403);
        }
    }
}
