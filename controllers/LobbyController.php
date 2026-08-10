<?php

require_once __DIR__ . '/../services/LobbyService.php';
require_once __DIR__ . '/../services/MercureService.php';
require_once __DIR__ . '/../services/RealtimeOutboxService.php';
require_once __DIR__ . '/../utils/response.php';

class LobbyController
{
    private LobbyService $service;
    private MercureService $mercure;
    private RealtimeOutboxService $outbox;

    public function __construct()
    {
        $this->service = new LobbyService();
        $this->mercure = new MercureService();
        $this->outbox = new RealtimeOutboxService(null, $this->mercure);
    }

    public function create(int $userId, array $payload): void
    {
        $data = $this->service->createLobby($userId, $payload);
        $data = $this->attachLobbyRealtime($data);
        $this->outbox->queueLobbySnapshot((int)($data['lobby']['id'] ?? 0), true);
        json_success('Lobby créé', $data, 201);
    }

    public function join(int $userId, array $payload): void
    {
        $code = (string)($payload['lobby_code'] ?? '');
        if ($code === '') {
            json_error('lobby_code requis', 400);
        }

        $data = $this->service->joinLobby($userId, $code);
        $data = $this->attachLobbyRealtime($data);
        $this->outbox->queueLobbySnapshot((int)($data['lobby']['id'] ?? 0), true);
        json_success('Lobby rejoint', $data);
    }

    public function leave(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->leaveLobby($userId, $lobbyId);
        $this->outbox->queueLobbySnapshot($lobbyId, true);
        json_success('Lobby quitté', $data);
    }

    public function touch(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $presenceStatus = (string)($payload['presence_status'] ?? 'active');
        $targetUserId = isset($payload['target_user_id']) ? (int)$payload['target_user_id'] : null;
        $data = $this->service->touchLobbyPresence($userId, $lobbyId, $presenceStatus, $targetUserId);
        $this->refreshLobbyRealtimeAuthorization($lobbyId);
        if (!empty($data['changed'])) {
            $this->outbox->queueLobbySnapshot($lobbyId, true);
        }
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
        $this->outbox->queueLobbySnapshot($lobbyId, true);
        json_success('Joueur exclu', $data);
    }

    public function delete(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->deleteLobby($userId, $lobbyId);
        $this->outbox->queueDeletedLobby((string)($data['lobby_code'] ?? ''), $lobbyId, true);
        json_success('Lobby supprimé', $data);
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
        $this->outbox->queueLobbySnapshot($lobbyId, true);
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
        $this->outbox->queueLobbySnapshot($lobbyId, true);
        json_success('Configuration lobby mise a jour', $data);
    }

    public function syncPlayback(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->syncPlayback($userId, $lobbyId, $payload);
        $this->outbox->queueLobbySnapshot($lobbyId, false);
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
        $this->outbox->queueLobbySnapshot($lobbyId, false);
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
        $this->outbox->queueLobbySnapshot($lobbyId, false);
        json_success('Track retirée du pool', $data);
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
        $this->outbox->queueLobbySnapshot($lobbyId, true);
        json_success('Manche démarrée', $data);
    }

    public function revealRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->revealCurrentRound($userId, $lobbyId);
        $this->outbox->queueLobbySnapshot($lobbyId, false);
        json_success('Manche en reveal', $data);
    }

    public function finishRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->finishCurrentRound($userId, $lobbyId);
        $this->outbox->queueLobbySnapshot($lobbyId, true);
        json_success('Manche terminée', $data);
    }

    public function voteNextRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->voteNextRound($userId, $lobbyId);
        $this->outbox->queueLobbySnapshot($lobbyId, true);
        json_success('Vote enregistré', $data);
    }

    public function voteRevealRound(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->voteRevealRound($userId, $lobbyId);
        $this->outbox->queueLobbySnapshot($lobbyId, false);
        json_success('Vote de révélation enregistré', $data);
    }

    public function submitAnswer(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->submitAnswer($userId, $lobbyId, $payload);
        $this->outbox->queueLobbySnapshot($lobbyId, false);
        json_success('Réponse enregistrée', $data);
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

    public function holdSuggestion(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        $roundId = (int)($payload['round_id'] ?? 0);
        if ($lobbyId <= 0 || $roundId <= 0) {
            json_error('lobby_id et round_id requis', 400);
        }

        $data = $this->service->holdSuggestion($userId, $lobbyId, $roundId);
        $this->outbox->queueLobbySnapshot($lobbyId, false);
        json_success('Proposition en cours', $data);
    }

    public function releaseSuggestionHold(int $userId, array $payload): void
    {
        $lobbyId = (int)($payload['lobby_id'] ?? 0);
        $roundId = (int)($payload['round_id'] ?? 0);
        if ($lobbyId <= 0) {
            json_error('lobby_id requis', 400);
        }

        $data = $this->service->releaseSuggestionHold($userId, $lobbyId, $roundId);
        $this->outbox->queueLobbySnapshot($lobbyId, false);
        json_success('Proposition terminee', $data);
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
        $gameMode = isset($_GET['game_mode']) ? (string)$_GET['game_mode'] : 'participative';
        $data = $this->service->listPublicLobbies($gameMode);
        if ($this->mercure->canPublish()) {
            $data['realtime'] = [
                'transport' => 'mercure',
                'hub_url' => $this->mercure->getHubUrl(),
                'topic' => $this->mercure->getPublicLobbiesTopic(),
                'event' => 'lobbies',
                'with_credentials' => false,
            ];
        } else {
            $data['realtime'] = null;
        }
        json_success(null, $data);
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

        $data['realtime'] = null;

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

}

