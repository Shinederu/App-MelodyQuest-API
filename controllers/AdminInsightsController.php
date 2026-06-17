<?php

require_once __DIR__ . '/../services/AdminInsightsService.php';
require_once __DIR__ . '/../utils/response.php';

class AdminInsightsController
{
    private AdminInsightsService $service;

    public function __construct()
    {
        $this->service = new AdminInsightsService();
    }

    public function listAnswerAttempts(array $payload): void
    {
        json_success(null, $this->service->listAnswerAttempts($payload));
    }
}
