<?php

require_once __DIR__ . '/../services/SuggestionService.php';
require_once __DIR__ . '/../utils/response.php';

class SuggestionController
{
    private SuggestionService $service;

    public function __construct()
    {
        $this->service = new SuggestionService();
    }

    public function submit(?int $userId, array $payload): void
    {
        $result = $this->service->submit($userId, $payload);
        json_success('Suggestion envoyee', $result, 201);
    }

    public function list(array $payload): void
    {
        $status = (string)($payload['status'] ?? ($_GET['status'] ?? 'pending'));
        json_success(null, ['items' => $this->service->list($status)]);
    }

    public function updateStatus(int $userId, array $payload): void
    {
        $id = (int)($payload['id'] ?? 0);
        $status = (string)($payload['status'] ?? 'pending');
        json_success('Suggestion mise a jour', $this->service->updateStatus($id, $status, $userId));
    }
}
