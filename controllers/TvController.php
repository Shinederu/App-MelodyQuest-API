<?php

require_once __DIR__ . '/../services/TvService.php';
require_once __DIR__ . '/../utils/response.php';

class TvController
{
    private TvService $service;

    public function __construct()
    {
        $this->service = new TvService();
    }

    public function createPairing(): void
    {
        json_success('Code TV créé', $this->service->createPairing(), 201);
    }

    public function getPairing(array $payload): void
    {
        $token = (string)($payload['device_token'] ?? ($_GET['device_token'] ?? ''));
        if ($token === '') {
            json_error('device_token requis', 400);
        }

        json_success(null, $this->service->getPairing($token));
    }

    public function linkPairing(int $userId, array $payload): void
    {
        $code = (string)($payload['pairing_code'] ?? '');
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($code === '' || $lobbyId <= 0) {
            json_error('pairing_code et lobby_id requis', 400);
        }

        json_success('TV liée au salon', $this->service->linkPairing($userId, $code, $lobbyId));
    }

    public function getState(array $payload): void
    {
        $token = (string)($payload['device_token'] ?? ($_GET['device_token'] ?? ''));
        if ($token === '') {
            json_error('device_token requis', 400);
        }

        json_success(null, $this->service->getState($token));
    }
}
