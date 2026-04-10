<?php

require_once __DIR__ . '/../services/LobbyService.php';
require_once __DIR__ . '/../services/MercureService.php';
require_once __DIR__ . '/../utils/response.php';

class LobbyController
{
    private LobbyService $service;
    private MercureService $mercure;

    public function __construct()
    {
        $this->service = new LobbyService();
        $this->mercure = new MercureService();
    }

    public function create(int $userId, array $payload): void
    {
        $data = $this->service->createLobby($userId, $payload);
        $data = $this->attachLobbyRealtime($data);
        $this->publishLobbySnapshot((int)($data['lobby']['id'] ?? 0), true);
        json_success('Lobby cree', $data, 201);
    }

    public function join(int $userId, array $payload): void
    {
        $code = (string)($payload['lobby_code'] ?? '');
        if ($code === '') {
            json_error('lobby_code requis', 400);
        }

        $data = $this->service->joinLobby($userId, $code);
        $data = $this->attachLobbyRealtime($data);
        $this->publishLobbySnapshot((int)($data['lobby']['id'] ?? 0), true);
        json_success('Lobby rejoint', $data);
    }

    public function leave(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->leaveLobby($userId, $lobbyId);
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Lobby quitte', $data);
    }

    public function touch(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->touchLobbyPresence($userId, $lobbyId);
        $this->refreshLobbyRealtimeAuthorization($lobbyId);
        json_success('Presence mise a jour', $data);
    }

    public function kickPlayer(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        $targetUserId = (int)($payload['target_user_id'] ?? 0);
        if ($lobbyId <= 0 || $targetUserId <= 0) {
            json_error('lobby_id et target_user_id requis', 400);
        }

        $data = $this->service->kickPlayer($userId, $lobbyId, $targetUserId);
        $data = $this->attachLobbyRealtime($data);
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Joueur exclu', $data);
    }

    public function delete(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->deleteLobby($userId, $lobbyId);
        $this->publishDeletedLobby((string)($data['lobby_code'] ?? ''), $lobbyId, true);
        json_success('Lobby supprime', $data);
    }

    public function getByCode(int $userId, array $payload): void
    {
        $code = (string)($payload['lobby_code'] ?? ($_GET['lobby_code'] ?? ''));
        if ($code === '') {
            json_error('lobby_code requis', 400);
        }

        $data = $this->service->getLobbyByCodeForUser($userId, $code);
        $data = $this->attachLobbyRealtime($data);
        json_success(null, $data);
    }

    public function resetForReplay(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->resetLobbyForReplay($userId, $lobbyId);
        $data = $this->attachLobbyRealtime($data);
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Lobby reinitialise', $data);
    }

    public function updateConfig(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->updateLobbyConfig($userId, $lobbyId, $payload);
        $data = $this->attachLobbyRealtime($data);
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Configuration lobby mise a jour', $data);
    }

    public function syncPlayback(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->syncPlayback($userId, $lobbyId, $payload);
        $this->publishLobbySnapshot($lobbyId, false);
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
        $this->publishLobbySnapshot($lobbyId, false);
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
        $this->publishLobbySnapshot($lobbyId, false);
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
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Manche demarree', $data);
    }

    public function revealRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->revealCurrentRound($userId, $lobbyId);
        $this->publishLobbySnapshot($lobbyId, false);
        json_success('Manche en reveal', $data);
    }

    public function finishRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->finishCurrentRound($userId, $lobbyId);
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Manche terminee', $data);
    }

    public function voteNextRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->voteNextRound($userId, $lobbyId);
        $this->publishLobbySnapshot($lobbyId, true);
        json_success('Vote enregistre', $data);
    }

    public function submitAnswer(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->submitAnswer($userId, $lobbyId, $payload);
        $this->publishLobbySnapshot($lobbyId, false);
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
        if ($this->mercure->canPublish()) {
            $data['realtime'] = [
                'transport' => 'mercure',
                'hub_url' => $this->mercure->getHubUrl(),
                'topic' => $this->mercure->getPublicLobbiesTopic(),
                'event' => 'lobbies',
                'with_credentials' => false,
            ];
        } else {
            $data['realtime'] = [
                'transport' => 'sse',
                'event' => 'lobbies',
            ];
        }
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

    private function attachLobbyRealtime(array $data): array
    {
        $lobbyCode = strtoupper(trim((string)($data['lobby']['lobby_code'] ?? '')));
        if ($lobbyCode === '') {
            return $data;
        }

        if ($this->mercure->canPublish() && $this->mercure->canAuthorizeSubscribers()) {
            $this->mercure->authorizeLobbySubscription($lobbyCode);
            $data['realtime'] = [
                'transport' => 'mercure',
                'hub_url' => $this->mercure->getHubUrl(),
                'topic' => $this->mercure->getLobbyTopic($lobbyCode),
                'event' => 'lobby',
                'with_credentials' => true,
            ];

            return $data;
        }

        $data['realtime'] = [
            'transport' => 'sse',
            'event' => 'lobby',
        ];

        return $data;
    }

    private function refreshLobbyRealtimeAuthorization(int $lobbyId): void
    {
        if (!$this->mercure->canPublish() || !$this->mercure->canAuthorizeSubscribers()) {
            return;
        }

        $lobbyCode = $this->service->getLobbyCodeById($lobbyId);
        if ($lobbyCode === '') {
            return;
        }

        $this->mercure->authorizeLobbySubscription($lobbyCode);
    }

    private function publishLobbySnapshot(int $lobbyId, bool $includePublicLobbies): void
    {
        if ($lobbyId <= 0 || !$this->mercure->canPublish()) {
            return;
        }

        try {
            $snapshot = $this->service->buildLobbyRealtimeSnapshot($lobbyId);
            $lobbyCode = strtoupper(trim((string)($snapshot['lobby']['lobby_code'] ?? '')));
            if ($lobbyCode !== '') {
                $eventId = (string)($snapshot['revision'] ?? '');
                $this->mercure->publish(
                    $this->mercure->getLobbyTopic($lobbyCode),
                    $snapshot,
                    true,
                    'lobby',
                    $eventId
                );
            }
        } catch (Throwable $e) {
            error_log('MelodyQuest lobby snapshot publish failed: ' . $e->getMessage());
        }

        if ($includePublicLobbies) {
            $this->publishPublicLobbiesSnapshot();
        }
    }

    private function publishDeletedLobby(string $lobbyCode, int $lobbyId, bool $includePublicLobbies): void
    {
        if (!$this->mercure->canPublish()) {
            return;
        }

        $lobbyCode = strtoupper(trim($lobbyCode));
        if ($lobbyCode !== '') {
            $payload = [
                'revision' => 'deleted-' . $lobbyId . '-' . time(),
                'lobby' => null,
                'players' => [],
                'pool' => ['items' => []],
                'round' => ['round' => null, 'answers' => []],
                'playback' => null,
                'scoreboard' => ['items' => []],
                'deleted' => true,
                'deleted_lobby_id' => $lobbyId,
                'server_time' => gmdate('c'),
            ];

            $this->mercure->publish(
                $this->mercure->getLobbyTopic($lobbyCode),
                $payload,
                true,
                'lobby',
                (string)$payload['revision']
            );
        }

        if ($includePublicLobbies) {
            $this->publishPublicLobbiesSnapshot();
        }
    }

    private function publishPublicLobbiesSnapshot(): void
    {
        if (!$this->mercure->canPublish()) {
            return;
        }

        try {
            $snapshot = $this->service->getPublicLobbiesRealtimeSnapshot();
            $this->mercure->publish(
                $this->mercure->getPublicLobbiesTopic(),
                $snapshot,
                false,
                'lobbies',
                (string)($snapshot['revision'] ?? '')
            );
        } catch (Throwable $e) {
            error_log('MelodyQuest public lobbies publish failed: ' . $e->getMessage());
        }
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

