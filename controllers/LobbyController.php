<?php

require_once __DIR__ . '/../services/LobbyService.php';
require_once __DIR__ . '/../utils/response.php';

class LobbyController
{
    private LobbyService $service;

    public function __construct()
    {
        $this->service = new LobbyService();
    }

    public function create(int $userId, array $payload): void
    {
        $data = $this->service->createLobby($userId, $payload);
        json_success('Lobby cree', $data, 201);
    }

    public function join(int $userId, array $payload): void
    {
        $code = (string)($payload['lobby_code'] ?? '');
        if ($code === '') {
            json_error('lobby_code requis', 400);
        }

        $data = $this->service->joinLobby($userId, $code);
        json_success('Lobby rejoint', $data);
    }

    public function leave(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->leaveLobby($userId, $lobbyId);
        json_success('Lobby quitte', $data);
    }

    public function getByCode(int $userId, array $payload): void
    {
        $code = (string)($payload['lobby_code'] ?? ($_GET['lobby_code'] ?? ''));
        if ($code === '') {
            json_error('lobby_code requis', 400);
        }

        $data = $this->service->getLobbyByCodeForUser($userId, $code);
        json_success(null, $data);
    }

    public function updateConfig(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->updateLobbyConfig($userId, $lobbyId, $payload);
        json_success('Configuration lobby mise a jour', $data);
    }

    public function syncPlayback(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->syncPlayback($userId, $lobbyId, $payload);
        json_success('Etat de lecture synchronise', $data);
    }

    public function getPlayback(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? ($_GET['lobby_id'] ?? 0));
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->getPlaybackState($lobbyId);
        json_success(null, $data);
    }

    public function addTrackToPool(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        $trackId = (int)($payload['track_id'] ?? 0);
        if ($lobbyId <= 0 || $trackId <= 0) {
            json_error('lobby_id et track_id requis', 400);
        }

        $data = $this->service->addTrackToPool($userId, $lobbyId, $trackId);
        json_success('Track ajoute au pool', $data);
    }

    public function removeTrackFromPool(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        $trackId = (int)($payload['track_id'] ?? 0);
        if ($lobbyId <= 0 || $trackId <= 0) {
            json_error('lobby_id et track_id requis', 400);
        }

        $data = $this->service->removeTrackFromPool($userId, $lobbyId, $trackId);
        json_success('Track retire du pool', $data);
    }

    public function listTrackPool(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? ($_GET['lobby_id'] ?? 0));
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->listTrackPool($userId, $lobbyId);
        json_success(null, $data);
    }

    public function startRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->startRound($userId, $lobbyId, $payload);
        json_success('Manche demarree', $data);
    }

    public function revealRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->revealCurrentRound($userId, $lobbyId);
        json_success('Manche en reveal', $data);
    }

    public function finishRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->finishCurrentRound($userId, $lobbyId);
        json_success('Manche terminee', $data);
    }

    public function submitAnswer(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->submitAnswer($userId, $lobbyId, $payload);
        json_success('Reponse enregistree', $data);
    }

    public function getRoundState(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? ($_GET['lobby_id'] ?? 0));
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->getRoundState($userId, $lobbyId);
        json_success(null, $data);
    }

    public function getScoreboard(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? ($_GET['lobby_id'] ?? 0));
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->getScoreboard($userId, $lobbyId);
        json_success(null, $data);
    }

    public function listPublicLobbies(): void
    {
        $data = $this->service->listPublicLobbies();
        json_success(null, $data);
    }

    public function streamLobby(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? ($_GET['lobby_id'] ?? 0));
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $this->initSse();

        $lastRevision = (int)($_GET['since'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));
        $heartbeatAt = time();

        for ($i = 0; $i < 25; $i++) {
            if (connection_aborted()) {
                break;
            }

            $snapshot = $this->service->getLobbyRealtimeSnapshot($userId, $lobbyId);
            $revision = (int)($snapshot['revision'] ?? 0);

            if ($revision !== $lastRevision) {
                $this->emitSse('lobby', $revision, $snapshot);
                $lastRevision = $revision;
                $heartbeatAt = time();
            } elseif ((time() - $heartbeatAt) >= 10) {
                echo ": ping\n\n";
                $this->flushSse();
                $heartbeatAt = time();
            }

            usleep(1000000);
        }

        exit;
    }

    public function streamPublicLobbies(): void
    {
        $this->initSse();

        $lastRevision = (int)($_GET['since'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));
        $heartbeatAt = time();

        for ($i = 0; $i < 25; $i++) {
            if (connection_aborted()) {
                break;
            }

            $snapshot = $this->service->getPublicLobbiesRealtimeSnapshot();
            $revision = (int)($snapshot['revision'] ?? 0);

            if ($revision !== $lastRevision) {
                $this->emitSse('lobbies', $revision, $snapshot);
                $lastRevision = $revision;
                $heartbeatAt = time();
            } elseif ((time() - $heartbeatAt) >= 10) {
                echo ": ping\n\n";
                $this->flushSse();
                $heartbeatAt = time();
            }

            usleep(1000000);
        }

        exit;
    }

    private function initSse(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    private function emitSse(string $event, int $id, array $payload): void
    {
        echo 'id: ' . $id . "\n";
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $this->flushSse();
    }

    private function flushSse(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
}

