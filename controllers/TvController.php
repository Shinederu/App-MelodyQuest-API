<?php

require_once __DIR__ . '/../services/TvService.php';
require_once __DIR__ . '/../services/MercureService.php';
require_once __DIR__ . '/../utils/response.php';

class TvController
{
    private TvService $service;
    private MercureService $mercure;

    public function __construct()
    {
        $this->service = new TvService();
        $this->mercure = new MercureService();
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

    public function markRoundReady(array $payload): void
    {
        $token = (string)($payload['device_token'] ?? '');
        $roundId = (int)($payload['round_id'] ?? 0);
        $trackId = (int)($payload['track_id'] ?? 0);
        if ($token === '' || $roundId <= 0 || $trackId <= 0) {
            json_error('device_token, round_id et track_id requis', 400);
        }

        $data = $this->service->markRoundReady($token, $roundId, $trackId);
        if (!empty($data['released']) && !empty($data['lobby_id'])) {
            $this->publishLobbySnapshot((int)$data['lobby_id']);
        }

        json_success('TV prête', $data);
    }

    private function publishLobbySnapshot(int $lobbyId): void
    {
        if ($lobbyId <= 0 || !$this->mercure->canPublish()) {
            return;
        }

        try {
            $lobbyService = new LobbyService();
            $snapshot = $lobbyService->buildLobbyRealtimeSnapshot($lobbyId);
            $lobbyCode = strtoupper(trim((string)($snapshot['lobby']['lobby_code'] ?? '')));
            if ($lobbyCode === '') {
                return;
            }

            $this->mercure->publish(
                $this->mercure->getLobbyTopic($lobbyCode),
                $snapshot,
                true,
                'lobby',
                (string)($snapshot['revision'] ?? '')
            );
        } catch (Throwable $e) {
            error_log('MelodyQuest TV ready publish failed: ' . $e->getMessage());
        }
    }
}
