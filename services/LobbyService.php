<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/GameSessionService.php';
require_once __DIR__ . '/TrackSelectionHistoryService.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../repositories/PdoGameSessionRepository.php';
require_once __DIR__ . '/../utils/youtube.php';

class LobbyService
{
    private PDO $db;
    private GameSessionService $gameSessions;
    private TrackSelectionHistoryService $trackHistory;
    private ?bool $familyAliasesTableExists = null;
    private ?bool $youtubeUrlColumnExists = null;
    private static ?bool $familyAliasesTableExistsCache = null;
    private static ?bool $youtubeUrlColumnExistsCache = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseService::getInstance();
        $this->gameSessions = new GameSessionService(new PdoGameSessionRepository($this->db));
        $this->trackHistory = new TrackSelectionHistoryService($this->db);
    }

    public function createLobby(int $ownerUserId, array $payload): array
    {
        $this->cleanupStaleOwnerLobbies();

        $name = $this->normalizeLobbyName($payload['name'] ?? 'Nouveau lobby');

        $maxPlayers = (int)($payload['max_players'] ?? MQ_DEFAULT_MAX_PLAYERS);
        $maxPlayers = max(2, min($maxPlayers, MQ_MAX_MAX_PLAYERS));

        $totalRounds = $this->validateTotalRoundsValue($payload['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS);

        $roundDuration = $this->validateRoundDurationValue($payload['round_duration_seconds'] ?? MQ_DEFAULT_ROUND_DURATION);

        $revealDuration = (int)($payload['reveal_duration_seconds'] ?? MQ_DEFAULT_REVEAL_DURATION);
        $revealDuration = max(3, min($revealDuration, 60));

        $visibility = strtolower((string)($payload['visibility'] ?? 'private'));
        if (!in_array($visibility, ['public', 'private'], true)) {
            $visibility = 'private';
        }

        $gameMode = $this->normalizeGameMode($payload['game_mode'] ?? 'participative');

        $guessMode = strtolower((string)($payload['guess_mode'] ?? 'both'));
        if (!in_array($guessMode, ['title', 'artist', 'both'], true)) {
            $guessMode = 'both';
        }

        $showTrackCategory = $this->normalizeBoolean($payload['show_track_category'] ?? true);
        $allowEarlyRevealVote = $this->normalizeBoolean($payload['allow_early_reveal_vote'] ?? true);
        $answerSimilarityThreshold = $this->validateAnswerSimilarityThreshold(
            $payload['answer_similarity_threshold'] ?? MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD
        );

        $selectedCategoryIds = array_key_exists('selected_category_ids', $payload)
            ? $this->normalizeCategoryIds($payload['selected_category_ids'])
            : $this->getDefaultSelectedCategoryIds();

        $code = $this->generateLobbyCode();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mq_lobbies
                (lobby_code, name, owner_user_id, status, visibility, game_mode, max_players, total_rounds, round_duration_seconds, reveal_duration_seconds, guess_mode, selected_category_ids, show_track_category, allow_early_reveal_vote, answer_similarity_threshold)
                VALUES (:code, :name, :owner, "waiting", :visibility, :game_mode, :max_players, :total_rounds, :round_duration, :reveal_duration, :guess_mode, :selected_category_ids, :show_track_category, :allow_early_reveal_vote, :answer_similarity_threshold)'
            );
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'owner' => $ownerUserId,
                'visibility' => $visibility,
                'game_mode' => $gameMode,
                'max_players' => $maxPlayers,
                'total_rounds' => $totalRounds,
                'round_duration' => $roundDuration,
                'reveal_duration' => $revealDuration,
                'guess_mode' => $guessMode,
                'selected_category_ids' => $this->encodeCategoryIds($selectedCategoryIds),
                'show_track_category' => $showTrackCategory,
                'allow_early_reveal_vote' => $allowEarlyRevealVote,
                'answer_similarity_threshold' => $answerSimilarityThreshold,
            ]);

            $lobbyId = (int)$this->db->lastInsertId();

            $stmt2 = $this->db->prepare(
                'INSERT INTO mq_lobby_players (lobby_id, user_id, role, is_ready, score)
                 VALUES (:lobby_id, :user_id, "owner", 0, 0)'
            );
            $stmt2->execute([
                'lobby_id' => $lobbyId,
                'user_id' => $ownerUserId,
            ]);

            $this->db->commit();
            return $this->getLobbyById($lobbyId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function joinLobby(int $userId, string $lobbyCode): array
    {
        $this->cleanupStaleOwnerLobbies();

        $stmt = $this->db->prepare('SELECT * FROM mq_lobbies WHERE lobby_code = :code LIMIT 1');
        $stmt->execute(['code' => strtoupper(trim($lobbyCode))]);
        $lobby = $stmt->fetch();
        if (!$lobby) {
            throw new RuntimeException('Lobby introuvable');
        }

        if ((string)$lobby['status'] === 'closed') {
            throw new RuntimeException('Lobby ferme');
        }

        $lobbyId = (int)$lobby['id'];
        $alreadyKnown = $this->isKnownLobbyPlayer($lobbyId, $userId);
        $alreadyMember = $this->isLobbyMember($lobbyId, $userId);
        if (!$alreadyMember) {
            $countStmt = $this->db->prepare(
                'SELECT COUNT(*) AS c
                 FROM mq_lobby_players
                 WHERE lobby_id = :id
                   AND presence_status <> "removed"'
            );
            $countStmt->execute(['id' => $lobbyId]);
            $count = (int)$countStmt->fetch()['c'];
            if ($count >= (int)$lobby['max_players']) {
                throw new RuntimeException('Lobby plein');
            }
        }

        $upsert = $this->db->prepare(
            'INSERT INTO mq_lobby_players (lobby_id, user_id, role, is_ready, score)
             VALUES (:lobby_id, :user_id, "player", 0, 0)
             ON DUPLICATE KEY UPDATE
                last_seen_at = NOW(),
                presence_status = "active",
                removed_at = NULL,
                removed_by = NULL'
        );
        $upsert->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
        ]);

        $this->syncLobbyPlayerRoles($lobbyId);

        if (!$alreadyKnown || !$alreadyMember) {
            $this->touchLobbyActivity($lobbyId);
        }

        return $this->getLobbyById($lobbyId);
    }

    public function leaveLobby(int $userId, int $lobbyId): array
    {
        $lobby = $this->requireLobby($lobbyId);
        $this->requireLobbyMember($lobbyId, $userId);

        $leave = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET presence_status = "removed",
                 is_ready = 0,
                 removed_at = NOW(3),
                 removed_by = NULL
             WHERE lobby_id = :lobby_id AND user_id = :user_id'
        );
        $leave->execute(['lobby_id' => $lobbyId, 'user_id' => $userId]);

        if ((int)$lobby['owner_user_id'] === $userId) {
            $nextStmt = $this->db->prepare(
                'SELECT user_id
                 FROM mq_lobby_players
                 WHERE lobby_id = :lobby_id
                   AND presence_status <> "removed"
                 ORDER BY joined_at ASC
                 LIMIT 1'
            );
            $nextStmt->execute(['lobby_id' => $lobbyId]);
            $next = $nextStmt->fetch();

            if ($next && !empty($next['user_id'])) {
                $newOwner = (int)$next['user_id'];
                $upd = $this->db->prepare('UPDATE mq_lobbies SET owner_user_id = :owner WHERE id = :id');
                $upd->execute(['owner' => $newOwner, 'id' => $lobbyId]);
                $role = $this->db->prepare(
                    'UPDATE mq_lobby_players
                     SET role = CASE WHEN user_id = :owner THEN "owner" ELSE "player" END
                     WHERE lobby_id = :lobby_id
                       AND presence_status <> "removed"'
                );
                $role->execute(['owner' => $newOwner, 'lobby_id' => $lobbyId]);
            } else {
                $this->gameSessions->archiveLobbyGame(
                    $lobbyId,
                    strtolower((string)$lobby['status']) === 'finished' ? 'finished' : 'cancelled'
                );
                $close = $this->db->prepare('UPDATE mq_lobbies SET status = "closed" WHERE id = :id');
                $close->execute(['id' => $lobbyId]);
            }
        }

        $this->touchLobbyActivity($lobbyId);

        return [
            'ok' => true,
            'lobby_id' => $lobbyId,
            'lobby_code' => (string)($lobby['lobby_code'] ?? ''),
        ];
    }

    public function touchLobbyPresence(int $userId, int $lobbyId, string $presenceStatus = 'active', ?int $targetUserId = null): array
    {
        $this->requireLobbyMember($lobbyId, $userId);
        $status = $this->normalizePresenceStatus($presenceStatus);
        $targetUserId = $targetUserId !== null && $targetUserId > 0 ? $targetUserId : $userId;

        if ($targetUserId !== $userId) {
            $lobby = $this->requireLobby($lobbyId);
            $this->requireOwner($lobby, $userId);
            $this->requireLobbyMember($lobbyId, $targetUserId);
            $this->touchLobbyMember($lobbyId, $userId);
            $changed = $this->setLobbyMemberPresence($lobbyId, $targetUserId, $status);
        } else {
            $changed = $this->touchLobbyMember($lobbyId, $userId, $status);
        }

        $this->cleanupStaleOwnerLobbies();

        if ($changed) {
            $this->touchLobbyActivity($lobbyId);
        }

        return [
            'ok' => true,
            'presence_status' => $status,
            'target_user_id' => $targetUserId,
            'changed' => $changed,
        ];
    }

    public function kickPlayer(int $ownerUserId, int $lobbyId, int $targetUserId): array
    {
        $this->touchLobbyMember($lobbyId, $ownerUserId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $ownerUserId);

        if ($targetUserId === $ownerUserId) {
            throw new RuntimeException('Le créateur ne peut pas s\'exclure lui-même');
        }

        $this->requireLobbyMember($lobbyId, $targetUserId);

        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET presence_status = "removed",
                 is_ready = 0,
                 removed_at = NOW(3),
                 removed_by = :removed_by
             WHERE lobby_id = :lobby_id AND user_id = :user_id'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $targetUserId,
            'removed_by' => $ownerUserId,
        ]);

        $this->touchLobbyActivity($lobbyId);

        return $this->getLobbyById($lobbyId);
    }

    public function deleteLobby(int $ownerUserId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $ownerUserId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $ownerUserId);

        $this->db->beginTransaction();
        try {
            $this->gameSessions->archiveLobbyGame(
                $lobbyId,
                strtolower((string)($lobby['status'] ?? '')) === 'finished' ? 'finished' : 'cancelled'
            );

            $stmt = $this->db->prepare('DELETE FROM mq_lobbies WHERE id = :id');
            $stmt->execute(['id' => $lobbyId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'ok' => true,
            'deleted_lobby_id' => $lobbyId,
            'lobby_code' => (string)($lobby['lobby_code'] ?? ''),
        ];
    }

    public function resetLobbyForReplay(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->db->beginTransaction();
        try {
            $lobby = $this->requireLobbyForUpdate($lobbyId);
            $this->requireLobbyMember($lobbyId, $userId);

            $currentRound = $this->getCurrentRoundRowForUpdate($lobbyId);
            if ($currentRound) {
                throw new RuntimeException('Une manche est encore en cours');
            }

            $status = strtolower((string)($lobby['status'] ?? ''));
            $finishedRounds = $this->countFinishedRounds($lobbyId);
            if ($status !== 'finished') {
                if ($status === 'waiting' && $finishedRounds === 0) {
                    $this->db->commit();
                    return $this->getLobbyById($lobbyId);
                }

                throw new RuntimeException('Le lobby n\'est pas dans un état relançable');
            }

            $this->gameSessions->archiveLobbyGame($lobbyId, 'finished');

            $this->db->prepare(
                'DELETE a
                 FROM mq_round_answers a
                 JOIN mq_rounds r ON r.id = a.round_id
                 WHERE r.lobby_id = :lobby_id'
            )->execute(['lobby_id' => $lobbyId]);

            $this->db->prepare(
                'DELETE FROM mq_rounds
                 WHERE lobby_id = :lobby_id'
            )->execute(['lobby_id' => $lobbyId]);

            $this->db->prepare(
                'DELETE FROM mq_round_preloads
                 WHERE lobby_id = :lobby_id'
            )->execute(['lobby_id' => $lobbyId]);

            $this->db->prepare(
                'UPDATE mq_lobby_players
                 SET score = 0,
                     is_ready = 0
                 WHERE lobby_id = :lobby_id'
            )->execute(['lobby_id' => $lobbyId]);

            $this->db->prepare(
                'UPDATE mq_lobbies
                 SET status = "waiting",
                     current_track_id = NULL,
                     playback_state = "stopped",
                     playback_started_at = NULL,
                     playback_offset_seconds = 0,
                     sync_revision = sync_revision + 1
                 WHERE id = :id'
            )->execute(['id' => $lobbyId]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->getLobbyById($lobbyId);
    }

    public function updateLobbyConfig(int $userId, int $lobbyId, array $payload): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $fields = [];
        $params = ['id' => $lobbyId];
        $clearPreloads = false;

        if (isset($payload['name'])) {
            $fields[] = 'name = :name';
            $params['name'] = $this->normalizeLobbyName($payload['name']);
        }
        if (isset($payload['visibility'])) {
            $visibility = strtolower((string)$payload['visibility']);
            if (!in_array($visibility, ['public', 'private'], true)) {
                throw new RuntimeException('visibility invalide');
            }
            $fields[] = 'visibility = :visibility';
            $params['visibility'] = $visibility;
        }
        if (isset($payload['game_mode'])) {
            $fields[] = 'game_mode = :game_mode';
            $params['game_mode'] = $this->normalizeGameMode($payload['game_mode']);
        }
        if (isset($payload['max_players'])) {
            $maxPlayers = max(2, min((int)$payload['max_players'], MQ_MAX_MAX_PLAYERS));
            $fields[] = 'max_players = :max_players';
            $params['max_players'] = $maxPlayers;
        }
        if (isset($payload['total_rounds'])) {
            $totalRounds = $this->validateTotalRoundsValue($payload['total_rounds']);
            $fields[] = 'total_rounds = :total_rounds';
            $params['total_rounds'] = $totalRounds;
            $clearPreloads = true;
        }
        if (isset($payload['round_duration_seconds'])) {
            $duration = $this->validateRoundDurationValue($payload['round_duration_seconds']);
            $fields[] = 'round_duration_seconds = :round_duration_seconds';
            $params['round_duration_seconds'] = $duration;
        }
        if (isset($payload['reveal_duration_seconds'])) {
            $duration = max(3, min((int)$payload['reveal_duration_seconds'], 60));
            $fields[] = 'reveal_duration_seconds = :reveal_duration_seconds';
            $params['reveal_duration_seconds'] = $duration;
        }
        if (isset($payload['guess_mode'])) {
            $guessMode = strtolower((string)$payload['guess_mode']);
            if (!in_array($guessMode, ['title', 'artist', 'both'], true)) {
                throw new RuntimeException('guess_mode invalide');
            }
            $fields[] = 'guess_mode = :guess_mode';
            $params['guess_mode'] = $guessMode;
        }
        if (array_key_exists('selected_category_ids', $payload)) {
            $fields[] = 'selected_category_ids = :selected_category_ids';
            $params['selected_category_ids'] = $this->encodeCategoryIds(
                $this->normalizeCategoryIds($payload['selected_category_ids'])
            );
            $clearPreloads = true;
        }
        if (array_key_exists('show_track_category', $payload)) {
            $fields[] = 'show_track_category = :show_track_category';
            $params['show_track_category'] = $this->normalizeBoolean($payload['show_track_category']);
        }
        if (array_key_exists('allow_early_reveal_vote', $payload)) {
            $fields[] = 'allow_early_reveal_vote = :allow_early_reveal_vote';
            $params['allow_early_reveal_vote'] = $this->normalizeBoolean($payload['allow_early_reveal_vote']);
        }
        if (array_key_exists('answer_similarity_threshold', $payload)) {
            $fields[] = 'answer_similarity_threshold = :answer_similarity_threshold';
            $params['answer_similarity_threshold'] = $this->validateAnswerSimilarityThreshold($payload['answer_similarity_threshold']);
        }

        if (!empty($fields)) {
            $sql = 'UPDATE mq_lobbies SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        if ($clearPreloads) {
            $stmt = $this->db->prepare('DELETE FROM mq_round_preloads WHERE lobby_id = :lobby_id');
            $stmt->execute(['lobby_id' => $lobbyId]);
        }

        return $this->getLobbyById($lobbyId);
    }

    public function addTrackToPool(int $userId, int $lobbyId, int $trackId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $trackStmt = $this->db->prepare('SELECT id FROM mq_tracks WHERE id = :id AND is_active = 1 AND is_validated = 1 LIMIT 1');
        $trackStmt->execute(['id' => $trackId]);
        if (!$trackStmt->fetch()) {
            throw new RuntimeException('Track introuvable, inactive ou non validee');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mq_lobby_track_pool (lobby_id, track_id, added_by)
             VALUES (:lobby_id, :track_id, :added_by)
             ON DUPLICATE KEY UPDATE added_by = VALUES(added_by), added_at = NOW()'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'track_id' => $trackId,
            'added_by' => $userId,
        ]);

        $this->clearRoundPreloadsForLobby($lobbyId);

        return $this->listTrackPool($userId, $lobbyId);
    }

    public function removeTrackFromPool(int $userId, int $lobbyId, int $trackId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $stmt = $this->db->prepare('DELETE FROM mq_lobby_track_pool WHERE lobby_id = :lobby_id AND track_id = :track_id');
        $stmt->execute(['lobby_id' => $lobbyId, 'track_id' => $trackId]);

        $this->clearRoundPreloadsForLobby($lobbyId);

        return $this->listTrackPool($userId, $lobbyId);
    }

    public function listTrackPool(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMemberSeen($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobby($lobbyId);
        $this->requireLobbyMember($lobbyId, $userId);

        return ['items' => $this->getTrackPoolSnapshotItems($lobbyId)];
    }

    public function startRound(int $userId, int $lobbyId, array $payload = []): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);
        $launchConfig = $this->assertLobbyCanStart($lobby);

        $running = $this->getCurrentRoundRow($lobbyId);
        if ($running) {
            throw new RuntimeException('Une manche est déjà en cours');
        }

        $finishedRounds = $this->countFinishedRounds($lobbyId);
        $targetRounds = $launchConfig['total_rounds'];
        if ($finishedRounds >= $targetRounds) {
            throw new RuntimeException('Toutes les manches de ce lobby ont déjà été jouées');
        }

        $requestedTrackId = isset($payload['track_id']) ? (int)$payload['track_id'] : 0;

        $this->db->beginTransaction();
        try {
            $roundNumberStmt = $this->db->prepare(
                'SELECT COALESCE(MAX(round_number), 0) + 1 AS next_round FROM mq_rounds WHERE lobby_id = :lobby_id'
            );
            $roundNumberStmt->execute(['lobby_id' => $lobbyId]);
            $roundNumber = (int)$roundNumberStmt->fetch()['next_round'];

            if ($requestedTrackId > 0) {
                if (!$this->isTrackPlayableForLobby($lobbyId, $requestedTrackId)) {
                    throw new RuntimeException('Track invalide pour ce lobby ou non validee');
                }
                $trackId = $requestedTrackId;
                $this->clearRoundPreload($lobbyId, $roundNumber);
            } else {
                $trackId = $this->consumeRoundPreloadLocked($lobbyId, $roundNumber) ?? $this->pickTrackForLobby($lobbyId);
            }

            $this->createRunningRoundLocked($lobbyId, $trackId, $roundNumber);
            $this->ensureUpcomingRoundPreloadsLocked($lobbyId, $roundNumber + 1, $targetRounds);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->getRoundState($userId, $lobbyId);
    }

    public function revealCurrentRound(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round) {
            throw new RuntimeException('Aucune manche en cours');
        }

        if ($this->isRoundWaitingToStart($round)) {
            throw new RuntimeException('La manche n\'a pas encore commencé');
        }

        $this->transitionRoundToReveal($lobbyId, (int)$round['id']);

        return $this->getRoundState($userId, $lobbyId);
    }

    public function finishCurrentRound(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round) {
            throw new RuntimeException('Aucune manche en cours');
        }

        $upd = $this->db->prepare(
            'UPDATE mq_rounds
             SET status = "finished",
                 ended_at = NOW(3)
             WHERE id = :id'
        );
        $upd->execute(['id' => $round['id']]);

        $this->db->prepare(
            'UPDATE mq_lobbies
             SET playback_state = "stopped",
                 status = :status,
                 playback_offset_seconds = 0,
                 sync_revision = sync_revision + 1
             WHERE id = :id'
        )->execute([
            'status' => $this->countFinishedRounds($lobbyId) >= $this->validateTotalRoundsValue($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS)
                ? 'finished'
                : 'waiting',
            'id' => $lobbyId,
        ]);

        return [
            'round' => $this->getRoundState($userId, $lobbyId),
            'scoreboard' => $this->getScoreboard($userId, $lobbyId),
        ];
    }

    public function voteNextRound(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->db->beginTransaction();
        try {
            $lobby = $this->requireLobbyForUpdate($lobbyId);
            $this->requireLobbyMember($lobbyId, $userId);

            $round = $this->getCurrentRoundRowForUpdate($lobbyId);
            if (!$round) {
                throw new RuntimeException('Aucune manche en cours');
            }

            if (!$this->isNextVoteWindowOpen($lobby, $round)) {
                throw new RuntimeException('Le passage à la manche suivante n\'est pas encore disponible');
            }

            $this->cleanupExpiredSuggestionHolds();
            if ($this->countActiveSuggestionHolds((int)$round['id']) > 0) {
                throw new RuntimeException('Une proposition de correction est en cours');
            }

            $this->db->prepare(
                'UPDATE mq_lobby_players
                 SET is_ready = 1
                 WHERE lobby_id = :lobby_id AND user_id = :user_id'
            )->execute([
                'lobby_id' => $lobbyId,
                'user_id' => $userId,
            ]);

            $playersCount = $this->countEligibleLobbyPlayers($lobbyId);
            $requiredCount = max(1, (int)ceil($playersCount * 0.5));
            $readyCount = $this->countReadyVotes($lobbyId);
            $advanced = false;
            $finishedGame = false;

            if ($readyCount >= $requiredCount) {
                $this->db->prepare(
                    'UPDATE mq_rounds
                     SET status = "finished",
                         ended_at = COALESCE(ended_at, NOW(3))
                     WHERE id = :id'
                )->execute(['id' => $round['id']]);

                $finishedRounds = $this->countFinishedRounds($lobbyId);
                $totalRounds = $this->validateTotalRoundsValue($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS);

                $this->resetLobbyReadyVotes($lobbyId);

                if ($finishedRounds >= $totalRounds) {
                    $this->db->prepare(
                        'UPDATE mq_lobbies
                         SET playback_state = "stopped",
                             current_track_id = NULL,
                             status = "finished",
                             playback_started_at = NULL,
                             playback_offset_seconds = 0,
                             sync_revision = sync_revision + 1
                         WHERE id = :id'
                    )->execute(['id' => $lobbyId]);
                    $finishedGame = true;
                } else {
                    $nextRoundNumber = (int)$round['round_number'] + 1;
                    $nextTrackId = $this->consumeRoundPreloadLocked($lobbyId, $nextRoundNumber) ?? $this->pickTrackForLobby($lobbyId);
                    $this->createRunningRoundLocked($lobbyId, $nextTrackId, $nextRoundNumber);
                    $this->ensureUpcomingRoundPreloadsLocked($lobbyId, $nextRoundNumber + 1, $totalRounds);
                }

                $advanced = true;
                $readyCount = 0;
            }

            $this->db->commit();

            return [
                'advanced' => $advanced,
                'finished_game' => $finishedGame,
                'ready_count' => $readyCount,
                'required_count' => $requiredCount,
                'players_count' => $playersCount,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function voteRevealRound(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->db->beginTransaction();
        try {
            $lobby = $this->requireLobbyForUpdate($lobbyId);
            $this->requireLobbyMember($lobbyId, $userId);

            if (!$this->normalizeBoolean($lobby['allow_early_reveal_vote'] ?? false)) {
                throw new RuntimeException('Le vote de révélation est désactivé pour ce salon');
            }

            $round = $this->getCurrentRoundRowForUpdate($lobbyId);
            if (!$round) {
                throw new RuntimeException('Aucune manche en cours');
            }

            if (strtolower((string)($round['status'] ?? '')) !== 'running') {
                throw new RuntimeException('La réponse est déjà révélée');
            }

            if ($this->isRoundWaitingToStart($round)) {
                throw new RuntimeException('La manche n\'a pas encore commencé');
            }

            if (!$this->isRoundAnswerWindowOpen($lobby, $round)) {
                throw new RuntimeException('Le chrono est déjà terminé');
            }

            if ($this->countSolvedPlayersForRound((int)$round['id']) > 0) {
                throw new RuntimeException('Un joueur a déjà trouvé la réponse');
            }

            $this->db->prepare(
                'INSERT INTO mq_round_reveal_votes (round_id, user_id, voted_at)
                 VALUES (:round_id, :user_id, NOW(3))
                 ON DUPLICATE KEY UPDATE voted_at = VALUES(voted_at)'
            )->execute([
                'round_id' => $round['id'],
                'user_id' => $userId,
            ]);

            $playersCount = $this->countEligibleLobbyPlayers($lobbyId);
            $requiredCount = max(1, $playersCount);
            $votesCount = $this->countEarlyRevealVotes((int)$round['id'], $lobbyId);
            $revealed = false;

            if ($votesCount >= $requiredCount) {
                $revealed = $this->transitionRoundToReveal($lobbyId, (int)$round['id'], false, $lobby);
            }

            $this->db->commit();

            return [
                'revealed' => $revealed,
                'votes_count' => $votesCount,
                'required_count' => $requiredCount,
                'players_count' => $playersCount,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function submitAnswer(int $userId, int $lobbyId, array $payload): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobbyMember($lobbyId, $userId);

        $lobby = $this->requireLobby($lobbyId);
        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round) {
            throw new RuntimeException('Aucune manche en cours');
        }

        if ($this->isRoundWaitingToStart($round)) {
            throw new RuntimeException('La manche n\'a pas encore commencé');
        }

        if (!$this->isRoundAnswerWindowOpen($lobby, $round)) {
            throw new RuntimeException('Le temps de réponse est écoulé');
        }

        $trackStmt = $this->db->prepare(
            'SELECT t.title, t.artist, t.family_id, f.name AS family_name
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $trackStmt->execute(['id' => $round['track_id']]);
        $track = $trackStmt->fetch();
        if (!$track) {
            throw new RuntimeException('Track introuvable');
        }

        $guessTitle = trim((string)($payload['guess_title'] ?? ''));
        $guessArtist = trim((string)($payload['guess_artist'] ?? ''));

        $isCorrectTitle = 0;
        $isCorrectArtist = 0;
        $mode = (string)$lobby['guess_mode'];
        $answerSimilarityThreshold = $this->getAnswerSimilarityThreshold($lobby);
        $titleVariants = $this->getExpectedTitleVariants((int)$track['family_id'], (string)$track['family_name']);

        if ($mode === 'title' || $mode === 'both') {
            $isCorrectTitle = $this->isGuessCorrectAgainstVariants($guessTitle, $titleVariants, $answerSimilarityThreshold) ? 1 : 0;
        }
        if ($mode === 'artist' || $mode === 'both') {
            $isCorrectArtist = $this->isGuessCorrect($guessArtist, (string)$track['artist'], $answerSimilarityThreshold) ? 1 : 0;
        }

        $prevStmt = $this->db->prepare(
            'SELECT id, score_awarded
             FROM mq_round_answers
             WHERE round_id = :round_id AND user_id = :user_id
             LIMIT 1'
        );
        $prevStmt->execute(['round_id' => $round['id'], 'user_id' => $userId]);
        $prev = $prevStmt->fetch();
        $previousScore = $prev ? (int)$prev['score_awarded'] : 0;
        if ($previousScore > 0) {
            return [
                'score_awarded' => $previousScore,
                'is_correct' => true,
                'is_correct_title' => (bool)$isCorrectTitle,
                'is_correct_artist' => (bool)$isCorrectArtist,
                'already_validated' => true,
            ];
        }

        $isCorrect = ($isCorrectTitle + $isCorrectArtist) > 0;
        $scoreAwarded = $isCorrect ? $this->calculateTimedScore($lobby, $round) : 0;
        $delta = $scoreAwarded - $previousScore;
        $autoRevealed = false;
        $awayBonus = ['score_awarded' => 0, 'players_count' => 0];

        $this->db->beginTransaction();
        try {
            $lockedLobby = $this->requireLobbyForUpdate($lobbyId);
            $lockedRound = $this->getCurrentRoundRowForUpdate($lobbyId);
            if (!$lockedRound || (int)($lockedRound['id'] ?? 0) !== (int)$round['id']) {
                throw new RuntimeException('La manche a change, reessaie');
            }

            if ($this->isRoundWaitingToStart($lockedRound)) {
                throw new RuntimeException('La manche n\'a pas encore commencé');
            }

            if (!$this->isRoundAnswerWindowOpen($lockedLobby, $lockedRound)) {
                throw new RuntimeException('Le temps de réponse est écoulé');
            }

            $hasSolvedPlayersBefore = $scoreAwarded > 0
                ? $this->hasSolvedPlayersForRoundForUpdate((int)$lockedRound['id'])
                : false;

            $upsert = $this->db->prepare(
                'INSERT INTO mq_round_answers
                 (round_id, user_id, guess_title, guess_artist, is_correct_title, is_correct_artist, score_awarded, answered_at)
                 VALUES (:round_id, :user_id, :guess_title, :guess_artist, :is_correct_title, :is_correct_artist, :score_awarded, NOW(3))
                 ON DUPLICATE KEY UPDATE
                    guess_title = VALUES(guess_title),
                    guess_artist = VALUES(guess_artist),
                    is_correct_title = VALUES(is_correct_title),
                    is_correct_artist = VALUES(is_correct_artist),
                    score_awarded = VALUES(score_awarded),
                    answered_at = NOW(3)'
            );
            $upsert->execute([
                'round_id' => $round['id'],
                'user_id' => $userId,
                'guess_title' => $guessTitle !== '' ? $guessTitle : null,
                'guess_artist' => $guessArtist !== '' ? $guessArtist : null,
                'is_correct_title' => $isCorrectTitle,
                'is_correct_artist' => $isCorrectArtist,
                'score_awarded' => $scoreAwarded,
            ]);

            $this->recordRoundAnswerAttempt(
                (int)$round['id'],
                $userId,
                $guessTitle,
                $guessArtist,
                $isCorrect,
                $scoreAwarded
            );

            if ($delta !== 0) {
                $scoreUpd = $this->db->prepare(
                    'UPDATE mq_lobby_players
                     SET score = score + :delta
                     WHERE lobby_id = :lobby_id AND user_id = :user_id'
                );
                $scoreUpd->execute([
                    'delta' => $delta,
                    'lobby_id' => $lobbyId,
                    'user_id' => $userId,
                ]);
            }

            if ($scoreAwarded > 0 && !$hasSolvedPlayersBefore) {
                $awayBonus = $this->awardAwayBonusToAbsentPlayers(
                    $lobbyId,
                    (int)$lockedRound['id'],
                    $userId,
                    $scoreAwarded
                );
            }

            if ($scoreAwarded > 0) {
                $playersCount = $this->countEligibleLobbyPlayers($lobbyId);
                $solvedPlayersCount = $this->countSolvedPlayersForRound((int)$lockedRound['id'], $lobbyId);
                if ($playersCount > 0 && $solvedPlayersCount >= $playersCount) {
                    $autoRevealed = $this->transitionRoundToReveal($lobbyId, (int)$lockedRound['id'], false, $lockedLobby);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'score_awarded' => $scoreAwarded,
            'is_correct' => $isCorrect,
            'is_correct_title' => (bool)$isCorrectTitle,
            'is_correct_artist' => (bool)$isCorrectArtist,
            'auto_revealed' => $autoRevealed,
            'answer_similarity_threshold' => $answerSimilarityThreshold,
            'away_bonus' => $awayBonus,
            'track' => $isCorrect ? $this->getTrackSnapshotById((int)$round['track_id']) : null,
        ];
    }

    public function syncPlayback(int $userId, int $lobbyId, array $payload): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $state = strtolower((string)($payload['playback_state'] ?? 'playing'));
        if (!in_array($state, ['stopped', 'playing', 'paused'], true)) {
            throw new RuntimeException('playback_state invalide');
        }

        $trackId = isset($payload['current_track_id']) ? (int)$payload['current_track_id'] : null;
        $offset = isset($payload['playback_offset_seconds']) ? (float)$payload['playback_offset_seconds'] : 0.0;
        $offset = max(0, $offset);

        $stmt = $this->db->prepare(
            'UPDATE mq_lobbies
             SET playback_state = :state,
                 current_track_id = :track_id,
                 playback_offset_seconds = :offset_seconds,
                 playback_started_at = NOW(3),
                 sync_revision = sync_revision + 1
             WHERE id = :id'
        );
        $stmt->execute([
            'state' => $state,
            'track_id' => $trackId,
            'offset_seconds' => $offset,
            'id' => $lobbyId,
        ]);

        return $this->getPlaybackState($lobbyId);
    }

    public function getPlaybackState(int $lobbyId): array
    {
        $this->cleanupStaleOwnerLobbies();

        $stmt = $this->db->prepare(
            'SELECT id, lobby_code, current_track_id, playback_state, playback_started_at, playback_offset_seconds, sync_revision
             FROM mq_lobbies
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $lobbyId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Lobby introuvable');
        }
        return $row;
    }

    public function getRoundState(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMemberSeen($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobbyMember($lobbyId, $userId);

        return $this->buildRoundStateSnapshot($lobbyId, ['viewer_user_id' => $userId]);
    }

    public function prepareTvPreloads(int $lobbyId): void
    {
        if ($lobbyId <= 0) {
            return;
        }

        $this->cleanupStaleOwnerLobbies();
        $this->db->beginTransaction();
        try {
            $lobby = $this->requireLobbyForUpdate($lobbyId);
            $status = strtolower((string)($lobby['status'] ?? ''));
            if (!in_array($status, ['waiting', 'playing'], true)) {
                $this->db->commit();
                return;
            }

            try {
                $launchConfig = $this->assertLobbyCanStart($lobby);
            } catch (RuntimeException) {
                $this->db->commit();
                return;
            }

            $round = $this->getCurrentRoundRowForUpdate($lobbyId);
            $firstRoundNumber = $round
                ? (int)$round['round_number'] + 1
                : $this->getNextRoundNumber($lobbyId);

            $this->ensureUpcomingRoundPreloadsLocked($lobbyId, $firstRoundNumber, (int)$launchConfig['total_rounds']);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getScoreboard(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMemberSeen($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobbyMember($lobbyId, $userId);

        return $this->buildScoreboardSnapshot($lobbyId);
    }

    public function holdSuggestion(int $userId, int $lobbyId, int $roundId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();
        $this->requireLobbyMember($lobbyId, $userId);

        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round || (int)$round['id'] !== $roundId) {
            throw new RuntimeException('Manche introuvable');
        }

        if (!in_array(strtolower((string)($round['status'] ?? '')), ['running', 'reveal'], true)) {
            throw new RuntimeException('La manche ne peut plus recevoir de proposition');
        }

        $this->cleanupExpiredSuggestionHolds();
        $stmt = $this->db->prepare(
            'INSERT INTO mq_round_suggestion_holds (lobby_id, round_id, user_id, expires_at)
             VALUES (:lobby_id, :round_id, :user_id, DATE_ADD(NOW(3), INTERVAL 3 MINUTE))
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), updated_at = NOW(3)'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'round_id' => $roundId,
            'user_id' => $userId,
        ]);

        return ['round_id' => $roundId, 'holds' => $this->buildSuggestionHoldSnapshot($roundId)];
    }

    public function releaseSuggestionHold(int $userId, int $lobbyId, int $roundId = 0): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();
        $this->requireLobbyMember($lobbyId, $userId);

        if ($roundId <= 0) {
            $round = $this->getCurrentRoundRow($lobbyId);
            $roundId = (int)($round['id'] ?? 0);
        }

        if ($roundId > 0) {
            $stmt = $this->db->prepare(
                'DELETE FROM mq_round_suggestion_holds
                 WHERE lobby_id = :lobby_id AND round_id = :round_id AND user_id = :user_id'
            );
            $stmt->execute([
                'lobby_id' => $lobbyId,
                'round_id' => $roundId,
                'user_id' => $userId,
            ]);
        }

        return ['round_id' => $roundId, 'holds' => $roundId > 0 ? $this->buildSuggestionHoldSnapshot($roundId) : []];
    }

    public function getLobbyById(int $lobbyId): array
    {
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);

        $playersStmt = $this->db->prepare(
            'SELECT lp.user_id, lp.role, lp.is_ready, lp.score, lp.presence_status, lp.joined_at, lp.last_seen_at, u.username, u.avatar_url
             FROM mq_lobby_players lp
             JOIN users u ON u.id = lp.user_id
             WHERE lp.lobby_id = :id
               AND lp.presence_status <> "removed"
             ORDER BY lp.joined_at ASC'
        );
        $playersStmt->execute(['id' => $lobbyId]);
        $players = $this->hydrateAvatarRows($playersStmt->fetchAll(), 'avatar_url', 'user_id');

        $lobby['selected_category_ids'] = $this->decodeCategoryIds($lobby['selected_category_ids'] ?? null);

        $currentRound = $this->getCurrentRoundRow($lobbyId);
        $roundsFinished = $this->countFinishedRounds($lobbyId);
        $lobby['rounds_finished'] = $roundsFinished;
        $lobby['rounds_remaining'] = max(0, (int)$lobby['total_rounds'] - $roundsFinished);
        $lobby['current_round_number'] = $currentRound ? (int)$currentRound['round_number'] : null;

        return [
            'lobby' => $lobby,
            'players' => $players,
        ];
    }

    public function getLobbyByCodeForUser(int $userId, string $code): array
    {
        $this->cleanupStaleOwnerLobbies();

        $stmt = $this->db->prepare('SELECT id, visibility FROM mq_lobbies WHERE lobby_code = :code LIMIT 1');
        $stmt->execute(['code' => strtoupper(trim($code))]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Lobby introuvable');
        }

        $lobbyId = (int)$row['id'];
        $isMember = $this->isLobbyMember($lobbyId, $userId);
        if (!$isMember && (string)$row['visibility'] !== 'public') {
            throw new RuntimeException('Accès refusé à ce lobby privé');
        }

        if ($isMember) {
            $this->touchLobbyMemberSeen($lobbyId, $userId);
        }

        return $this->getLobbyById($lobbyId);
    }

    public function getPublicLobbiesRealtimeSnapshot(): array
    {
        $this->cleanupStaleOwnerLobbies();
        $data = $this->listPublicLobbies(null);

        return [
            'revision' => $this->computePublicLobbiesRevision(null),
            'items' => $data['items'] ?? [],
            'server_time' => gmdate('c'),
        ];
    }

    public function buildLobbyRealtimeSnapshot(int $lobbyId, array $options = []): array
    {
        $this->cleanupStaleOwnerLobbies();

        $detail = $this->getLobbyById($lobbyId);
        $pool = ['items' => $this->getTrackPoolSnapshotItems($lobbyId)];
        $round = $this->buildRoundStateSnapshot($lobbyId, $options);
        $playback = $this->getPlaybackState($lobbyId);
        $scoreboard = $this->buildScoreboardSnapshot($lobbyId);

        return [
            'revision' => $this->computeLobbyRevision($lobbyId),
            'lobby' => $detail['lobby'] ?? null,
            'players' => $detail['players'] ?? [],
            'pool' => $pool,
            'round' => $round,
            'playback' => $playback,
            'scoreboard' => $scoreboard,
            'server_time' => gmdate('c'),
        ];
    }

    public function getLobbyCodeById(int $lobbyId): string
    {
        $stmt = $this->db->prepare('SELECT lobby_code FROM mq_lobbies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $lobbyId]);
        $row = $stmt->fetch();

        return strtoupper(trim((string)($row['lobby_code'] ?? '')));
    }
    public function listPublicLobbies(?string $gameMode = 'participative'): array
    {
        $this->cleanupStaleOwnerLobbies();

        $mode = $gameMode === null ? null : $this->normalizeGameMode($gameMode);
        $modeFilter = $mode === null ? '' : ' AND l.game_mode = :game_mode';
        $stmt = $this->db->prepare(
            'SELECT l.id, l.lobby_code, l.name, l.status, l.visibility, l.game_mode, l.max_players, l.owner_user_id, u.username AS owner_username, u.avatar_url AS owner_avatar_url,
                    (SELECT COUNT(*) FROM mq_lobby_players lp WHERE lp.lobby_id = l.id AND lp.presence_status <> "removed") AS players_count
             FROM mq_lobbies l
             JOIN users u ON u.id = l.owner_user_id
             WHERE l.visibility = "public"
               ' . $modeFilter . '
               AND l.status IN ("waiting", "playing")
             ORDER BY l.updated_at DESC
             LIMIT 100'
        );
        if ($mode !== null) {
            $stmt->bindValue('game_mode', $mode);
        }
        $stmt->execute();

        $items = $this->hydrateAvatarRows($stmt->fetchAll(), 'owner_avatar_url', 'owner_user_id');

        return ['items' => $items];
    }

    private function touchLobbyMemberSeen(int $lobbyId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET last_seen_at = NOW()
             WHERE lobby_id = :lobby_id
               AND user_id = :user_id
               AND presence_status <> "removed"'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
        ]);
    }

    private function touchLobbyMember(int $lobbyId, int $userId, string $presenceStatus = 'active'): bool
    {
        $presenceStatus = $this->normalizePresenceStatus($presenceStatus);
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET last_seen_at = NOW(),
                 presence_status = :presence_status,
                 is_ready = CASE WHEN :presence_status_ready = "active" THEN is_ready ELSE 0 END
             WHERE lobby_id = :lobby_id
               AND user_id = :user_id
               AND presence_status <> "removed"
               AND presence_status <> :presence_status_current'
        );
        $stmt->execute([
            'presence_status' => $presenceStatus,
            'presence_status_ready' => $presenceStatus,
            'presence_status_current' => $presenceStatus,
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
        ]);
        $changed = $stmt->rowCount() > 0;

        if (!$changed) {
            $stmt = $this->db->prepare(
                'UPDATE mq_lobby_players
                 SET last_seen_at = NOW()
                 WHERE lobby_id = :lobby_id
                   AND user_id = :user_id
                   AND presence_status <> "removed"'
            );
            $stmt->execute([
                'lobby_id' => $lobbyId,
                'user_id' => $userId,
            ]);
        }

        return $changed;
    }

    private function setLobbyMemberPresence(int $lobbyId, int $userId, string $presenceStatus): bool
    {
        $presenceStatus = $this->normalizePresenceStatus($presenceStatus);
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET presence_status = :presence_status,
                 is_ready = CASE WHEN :presence_status_ready = "active" THEN is_ready ELSE 0 END
             WHERE lobby_id = :lobby_id
               AND user_id = :user_id
               AND presence_status <> "removed"
               AND presence_status <> :presence_status_current'
        );
        $stmt->execute([
            'presence_status' => $presenceStatus,
            'presence_status_ready' => $presenceStatus,
            'presence_status_current' => $presenceStatus,
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function normalizePresenceStatus(string $status): string
    {
        $value = strtolower(trim($status));
        return in_array($value, ['active', 'away'], true) ? $value : 'active';
    }

    private function syncLobbyPlayerRoles(int $lobbyId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players lp
             JOIN mq_lobbies l ON l.id = lp.lobby_id
             SET lp.role = CASE WHEN lp.user_id = l.owner_user_id THEN "owner" ELSE "player" END
             WHERE lp.lobby_id = :lobby_id
               AND lp.presence_status <> "removed"'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
    }

    private function touchLobbyActivity(int $lobbyId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_lobbies
             SET sync_revision = sync_revision + 1,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $lobbyId]);
    }

    private function cleanupStaleOwnerLobbies(): void
    {
        if (!$this->shouldRunStaleOwnerCleanup()) {
            return;
        }

        $timeout = max(1, (int)MQ_OWNER_STALE_TIMEOUT_SECONDS);
        $stmt = $this->db->prepare(
            'SELECT l.id, l.status
             FROM mq_lobbies l
             JOIN mq_lobby_players lp
               ON lp.lobby_id = l.id
              AND lp.user_id = l.owner_user_id
              AND lp.presence_status <> "removed"
             WHERE l.status IN ("waiting", "playing", "finished")
               AND lp.last_seen_at < (NOW() - INTERVAL ' . $timeout . ' SECOND)'
        );
        $stmt->execute();
        $staleLobbies = $stmt->fetchAll();

        if (empty($staleLobbies)) {
            return;
        }

        $lobbyIds = array_map('intval', array_column($staleLobbies, 'id'));
        $placeholders = implode(',', array_fill(0, count($lobbyIds), '?'));
        $this->db->beginTransaction();
        try {
            foreach ($staleLobbies as $staleLobby) {
                $this->gameSessions->archiveLobbyGame(
                    (int)$staleLobby['id'],
                    strtolower((string)$staleLobby['status']) === 'finished' ? 'finished' : 'cancelled'
                );
            }

            $delete = $this->db->prepare('DELETE FROM mq_lobbies WHERE id IN (' . $placeholders . ')');
            foreach ($lobbyIds as $index => $lobbyId) {
                $delete->bindValue($index + 1, $lobbyId, PDO::PARAM_INT);
            }
            $delete->execute();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function shouldRunStaleOwnerCleanup(): bool
    {
        $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'melodyquest-stale-owner-cleanup.timestamp';
        $now = time();
        $lastRun = is_file($file) ? (int)@file_get_contents($file) : 0;
        if ($lastRun > 0 && ($now - $lastRun) < 10) {
            return false;
        }

        @file_put_contents($file, (string)$now, LOCK_EX);
        return true;
    }

    private function computeLobbyRevision(int $lobbyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT l.sync_revision,
                    COALESCE(UNIX_TIMESTAMP(l.updated_at), 0) AS lobby_updated,
                    COALESCE((SELECT MAX(UNIX_TIMESTAMP(lp.last_seen_at)) FROM mq_lobby_players lp WHERE lp.lobby_id = l.id), 0) AS players_updated,
                    COALESCE((SELECT MAX(UNIX_TIMESTAMP(p.added_at)) FROM mq_lobby_track_pool p WHERE p.lobby_id = l.id), 0) AS pool_updated,
                    COALESCE((SELECT MAX(r.id) FROM mq_rounds r WHERE r.lobby_id = l.id), 0) AS max_round_id,
                    COALESCE((SELECT MAX(a.id)
                              FROM mq_round_answers a
                              JOIN mq_rounds r2 ON r2.id = a.round_id
                              WHERE r2.lobby_id = l.id), 0) AS max_answer_id,
                    COALESCE((SELECT MAX(aa.id)
                              FROM mq_round_answer_attempts aa
                              JOIN mq_rounds r_attempt ON r_attempt.id = aa.round_id
                              WHERE r_attempt.lobby_id = l.id), 0) AS max_attempt_id,
                    COALESCE((SELECT MAX(UNIX_TIMESTAMP(v.voted_at))
                              FROM mq_round_reveal_votes v
                              JOIN mq_rounds r3 ON r3.id = v.round_id
                              WHERE r3.lobby_id = l.id), 0) AS max_reveal_vote,
                    COALESCE((SELECT COUNT(*)
                              FROM mq_round_reveal_votes v2
                              JOIN mq_rounds r4 ON r4.id = v2.round_id
                              WHERE r4.lobby_id = l.id), 0) AS reveal_vote_count
             FROM mq_lobbies l
             WHERE l.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $lobbyId]);
        $row = $stmt->fetch();

        if (!$row) {
            return 0;
        }

        $seed = implode(':', [
            (int)$row['sync_revision'],
            (int)$row['lobby_updated'],
            (int)$row['players_updated'],
            (int)$row['pool_updated'],
            (int)$row['max_round_id'],
            (int)$row['max_answer_id'],
            (int)$row['max_attempt_id'],
            (int)$row['max_reveal_vote'],
            (int)$row['reveal_vote_count'],
        ]);

        return abs((int)crc32($seed));
    }

    private function computePublicLobbiesRevision(?string $gameMode = 'participative'): int
    {
        $mode = $gameMode === null ? null : $this->normalizeGameMode($gameMode);
        $modeFilter = $mode === null ? '' : ' AND l.game_mode = :game_mode';
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c,
                    COALESCE(MAX(UNIX_TIMESTAMP(l.updated_at)), 0) AS max_updated,
                    COALESCE(SUM(l.sync_revision), 0) AS sync_sum,
                    COALESCE(SUM(p.players_count), 0) AS players_total
             FROM mq_lobbies l
             LEFT JOIN (
                SELECT lobby_id, COUNT(*) AS players_count
                FROM mq_lobby_players
                GROUP BY lobby_id
             ) p ON p.lobby_id = l.id
             WHERE l.visibility = "public"
               ' . $modeFilter . '
               AND l.status IN ("waiting", "playing")'
        );
        if ($mode !== null) {
            $stmt->bindValue('game_mode', $mode);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }

        $seed = implode(':', [
            (int)$row['c'],
            (int)$row['max_updated'],
            (int)$row['sync_sum'],
            (int)$row['players_total'],
        ]);

        return abs((int)crc32($seed));
    }
    private function requireLobby(int $lobbyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mq_lobbies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $lobbyId]);
        $lobby = $stmt->fetch();
        if (!$lobby) {
            throw new RuntimeException('Lobby introuvable');
        }
        return $lobby;
    }

    private function requireLobbyForUpdate(int $lobbyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mq_lobbies WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $lobbyId]);
        $lobby = $stmt->fetch();
        if (!$lobby) {
            throw new RuntimeException('Lobby introuvable');
        }

        return $lobby;
    }

    private function requireOwner(array $lobby, int $userId): void
    {
        if ((int)$lobby['owner_user_id'] !== $userId) {
            throw new RuntimeException('Seul le créateur peut effectuer cette action');
        }
    }

    private function requireLobbyMember(int $lobbyId, int $userId): void
    {
        if (!$this->isLobbyMember($lobbyId, $userId)) {
            throw new RuntimeException('Utilisateur non présent dans ce lobby');
        }
    }

    private function isLobbyMember(int $lobbyId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM mq_lobby_players
             WHERE lobby_id = :lobby_id
               AND user_id = :user_id
               AND presence_status <> "removed"
             LIMIT 1'
        );
        $stmt->execute(['lobby_id' => $lobbyId, 'user_id' => $userId]);
        return (bool)$stmt->fetch();
    }

    private function isKnownLobbyPlayer(int $lobbyId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM mq_lobby_players WHERE lobby_id = :lobby_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['lobby_id' => $lobbyId, 'user_id' => $userId]);
        return (bool)$stmt->fetch();
    }

    private function getCurrentRoundRow(int $lobbyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*,
                    COALESCE(UNIX_TIMESTAMP(r.started_at), 0) AS started_at_unix,
                    COALESCE(UNIX_TIMESTAMP(r.reveal_started_at), 0) AS reveal_started_at_unix,
                    COALESCE(UNIX_TIMESTAMP(r.ended_at), 0) AS ended_at_unix
             FROM mq_rounds r
             WHERE lobby_id = :lobby_id
               AND status IN ("running", "reveal")
             ORDER BY round_number DESC
             LIMIT 1'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getCurrentRoundRowForUpdate(int $lobbyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*,
                    COALESCE(UNIX_TIMESTAMP(r.started_at), 0) AS started_at_unix,
                    COALESCE(UNIX_TIMESTAMP(r.reveal_started_at), 0) AS reveal_started_at_unix,
                    COALESCE(UNIX_TIMESTAMP(r.ended_at), 0) AS ended_at_unix
             FROM mq_rounds r
             WHERE lobby_id = :lobby_id
               AND status IN ("running", "reveal")
             ORDER BY round_number DESC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getNextRoundNumber(int $lobbyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(round_number), 0) + 1 AS next_round
             FROM mq_rounds
             WHERE lobby_id = :lobby_id'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return max(1, (int)($stmt->fetch()['next_round'] ?? 1));
    }

    private function pickTrackForLobby(int $lobbyId): int
    {
        $lobby = $this->requireLobby($lobbyId);
        $selectedCategoryIds = $this->decodeCategoryIds($lobby['selected_category_ids'] ?? null);
        if (empty($selectedCategoryIds)) {
            throw new RuntimeException('Sélectionne au moins une catégorie avant de lancer la partie');
        }

        $totalRounds = $this->validateTotalRoundsValue($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS);
        $usedTrackIds = $this->getScheduledTrackIds($lobbyId);
        $usedFamilyIds = $this->getScheduledFamilyIds($lobbyId);

        $recentTracks = $this->trackHistory->getRecentTrackBucketsForLobby($lobbyId);
        $exclusionTiers = $this->buildTrackSelectionExclusionTiers(
            $usedTrackIds,
            $recentTracks['lookback'] ?? [],
            $recentTracks['strict'] ?? []
        );

        foreach ($exclusionTiers as $excludedTrackIds) {
            $trackId = $this->pickBalancedEligibleTrack(
                $lobbyId,
                $selectedCategoryIds,
                $totalRounds,
                $excludedTrackIds,
                $usedFamilyIds
            );
            if ($trackId !== null) {
                return $trackId;
            }

            $trackId = $this->pickBalancedEligibleTrack(
                $lobbyId,
                $selectedCategoryIds,
                $totalRounds,
                $excludedTrackIds,
                []
            );
            if ($trackId !== null) {
                return $trackId;
            }
        }

        throw new RuntimeException('Aucune musique disponible pour les catégories sélectionnées');
    }

    private function buildTrackSelectionExclusionTiers(
        array $usedTrackIds,
        array $lookbackTrackIds,
        array $strictTrackIds
    ): array {
        $usedTrackIds = $this->normalizePositiveIds($usedTrackIds);
        $lookbackTrackIds = $this->normalizePositiveIds($lookbackTrackIds);
        $strictTrackIds = $this->normalizePositiveIds($strictTrackIds);

        $tiers = [
            array_values(array_unique(array_merge($usedTrackIds, $lookbackTrackIds))),
            array_values(array_unique(array_merge($usedTrackIds, $strictTrackIds))),
            $usedTrackIds,
            [],
        ];

        $uniqueTiers = [];
        $seen = [];
        foreach ($tiers as $tier) {
            sort($tier, SORT_NUMERIC);
            $key = implode(',', $tier);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniqueTiers[] = $tier;
        }

        return $uniqueTiers;
    }

    private function normalizePositiveIds(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $value): bool => $value > 0
        )));
    }

    private function getPlayedTrackIds(int $lobbyId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT track_id FROM mq_rounds WHERE lobby_id = :lobby_id');
        $stmt->execute(['lobby_id' => $lobbyId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'track_id'));
    }

    private function getPlayedFamilyIds(int $lobbyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT t.family_id
             FROM mq_rounds r
             JOIN mq_tracks t ON t.id = r.track_id
             WHERE r.lobby_id = :lobby_id'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return array_values(array_filter(array_map('intval', array_column($stmt->fetchAll(), 'family_id'))));
    }

    private function getScheduledTrackIds(int $lobbyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT track_id
             FROM (
                SELECT track_id FROM mq_rounds WHERE lobby_id = :round_lobby_id
                UNION ALL
                SELECT track_id FROM mq_round_preloads WHERE lobby_id = :preload_lobby_id
             ) scheduled'
        );
        $stmt->execute([
            'round_lobby_id' => $lobbyId,
            'preload_lobby_id' => $lobbyId,
        ]);

        return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function getScheduledFamilyIds(int $lobbyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT t.family_id
             FROM (
                SELECT track_id FROM mq_rounds WHERE lobby_id = :round_lobby_id
                UNION ALL
                SELECT track_id FROM mq_round_preloads WHERE lobby_id = :preload_lobby_id
             ) scheduled
             JOIN mq_tracks t ON t.id = scheduled.track_id'
        );
        $stmt->execute([
            'round_lobby_id' => $lobbyId,
            'preload_lobby_id' => $lobbyId,
        ]);

        return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function pickBalancedEligibleTrack(
        int $lobbyId,
        array $selectedCategoryIds,
        int $totalRounds,
        array $excludedTrackIds,
        array $excludedFamilyIds = []
    ): ?int {
        $availableByCategory = $this->getPlayableTrackCountsByCategory($lobbyId, $selectedCategoryIds);
        $quotas = $this->calculateBalancedCategoryQuotas($availableByCategory, $totalRounds, $lobbyId);
        $scheduledCounts = $this->getScheduledCategoryCounts($lobbyId);
        $orderedCategoryIds = $this->orderCategoriesForNextPick($selectedCategoryIds, $availableByCategory, $quotas, $scheduledCounts, $lobbyId);

        foreach ($orderedCategoryIds as $categoryId) {
            $trackId = $this->pickEligibleTrack($lobbyId, [$categoryId], $excludedTrackIds, $excludedFamilyIds);
            if ($trackId !== null) {
                return $trackId;
            }
        }

        return null;
    }

    private function getPlayableTrackCountsByCategory(int $lobbyId, array $selectedCategoryIds): array
    {
        $selectedCategoryIds = $this->normalizeCategoryIds($selectedCategoryIds);
        if (empty($selectedCategoryIds)) {
            return [];
        }

        $placeholders = [];
        $params = [
            'pool_lobby_id_exists' => $lobbyId,
            'pool_lobby_id_empty' => $lobbyId,
        ];
        foreach ($selectedCategoryIds as $index => $categoryId) {
            $key = 'category_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $categoryId;
        }

        $stmt = $this->db->prepare(
            'SELECT f.category_id, COUNT(DISTINCT t.id) AS track_count
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             WHERE f.category_id IN (' . implode(', ', $placeholders) . ')
               AND f.is_active = 1
               AND t.is_active = 1
               AND t.is_validated = 1
               AND (
                 EXISTS (SELECT 1 FROM mq_lobby_track_pool p WHERE p.lobby_id = :pool_lobby_id_exists AND p.track_id = t.id)
                 OR NOT EXISTS (SELECT 1 FROM mq_lobby_track_pool p2 WHERE p2.lobby_id = :pool_lobby_id_empty)
               )
             GROUP BY f.category_id'
        );
        $stmt->execute($params);

        $counts = array_fill_keys($selectedCategoryIds, 0);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int)$row['category_id']] = (int)$row['track_count'];
        }

        return $counts;
    }

    private function calculateBalancedCategoryQuotas(array $availableByCategory, int $totalRounds, int $lobbyId): array
    {
        $totalRounds = max(0, $totalRounds);
        $quotas = [];
        $categoryIds = [];
        foreach ($availableByCategory as $categoryId => $availableTracks) {
            $categoryId = (int)$categoryId;
            $availableTracks = max(0, (int)$availableTracks);
            $quotas[$categoryId] = 0;
            if ($availableTracks > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        if ($totalRounds <= 0 || empty($categoryIds)) {
            return $quotas;
        }

        $orderIndexes = $this->buildStableCategoryOrderIndexes($categoryIds, $lobbyId);
        for ($round = 0; $round < $totalRounds; $round++) {
            $candidates = array_values(array_filter(
                $categoryIds,
                fn (int $categoryId): bool => $quotas[$categoryId] < (int)$availableByCategory[$categoryId]
            ));
            if (empty($candidates)) {
                break;
            }

            usort($candidates, function (int $a, int $b) use ($quotas, $orderIndexes): int {
                if ($quotas[$a] !== $quotas[$b]) {
                    return $quotas[$a] <=> $quotas[$b];
                }

                return ($orderIndexes[$a] ?? 0) <=> ($orderIndexes[$b] ?? 0);
            });

            $quotas[$candidates[0]]++;
        }

        return $quotas;
    }

    private function getScheduledCategoryCounts(int $lobbyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.category_id, COUNT(*) AS scheduled_count
             FROM (
                SELECT track_id FROM mq_rounds WHERE lobby_id = :round_lobby_id
                UNION ALL
                SELECT track_id FROM mq_round_preloads WHERE lobby_id = :preload_lobby_id
             ) scheduled
             JOIN mq_tracks t ON t.id = scheduled.track_id
             JOIN mq_families f ON f.id = t.family_id
             GROUP BY f.category_id'
        );
        $stmt->execute([
            'round_lobby_id' => $lobbyId,
            'preload_lobby_id' => $lobbyId,
        ]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int)$row['category_id']] = (int)$row['scheduled_count'];
        }

        return $counts;
    }

    private function orderCategoriesForNextPick(
        array $selectedCategoryIds,
        array $availableByCategory,
        array $quotas,
        array $scheduledCounts,
        int $lobbyId
    ): array {
        $categoryIds = array_values(array_filter(
            $this->normalizeCategoryIds($selectedCategoryIds),
            fn (int $categoryId): bool => (int)($availableByCategory[$categoryId] ?? 0) > 0
        ));
        if (empty($categoryIds)) {
            return [];
        }

        $orderIndexes = $this->buildStableCategoryOrderIndexes($categoryIds, $lobbyId);
        usort($categoryIds, function (int $a, int $b) use ($quotas, $scheduledCounts, $availableByCategory, $orderIndexes): int {
            $quotaA = (int)($quotas[$a] ?? 0);
            $quotaB = (int)($quotas[$b] ?? 0);
            $scheduledA = (int)($scheduledCounts[$a] ?? 0);
            $scheduledB = (int)($scheduledCounts[$b] ?? 0);
            $deficitA = $quotaA - $scheduledA;
            $deficitB = $quotaB - $scheduledB;
            $hasDeficitA = $deficitA > 0;
            $hasDeficitB = $deficitB > 0;

            if ($hasDeficitA !== $hasDeficitB) {
                return $hasDeficitA ? -1 : 1;
            }

            if ($deficitA !== $deficitB) {
                return $deficitB <=> $deficitA;
            }

            if ($scheduledA !== $scheduledB) {
                return $scheduledA <=> $scheduledB;
            }

            $availableA = (int)($availableByCategory[$a] ?? 0);
            $availableB = (int)($availableByCategory[$b] ?? 0);
            if ($availableA !== $availableB) {
                return $availableA <=> $availableB;
            }

            return ($orderIndexes[$a] ?? 0) <=> ($orderIndexes[$b] ?? 0);
        });

        return $categoryIds;
    }

    private function buildStableCategoryOrderIndexes(array $categoryIds, int $lobbyId): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        usort($categoryIds, function (int $a, int $b) use ($lobbyId): int {
            $hashA = hexdec(substr(sha1($lobbyId . ':' . $a), 0, 8));
            $hashB = hexdec(substr(sha1($lobbyId . ':' . $b), 0, 8));
            if ($hashA !== $hashB) {
                return $hashA <=> $hashB;
            }

            return $a <=> $b;
        });

        $indexes = [];
        foreach ($categoryIds as $index => $categoryId) {
            $indexes[$categoryId] = $index;
        }

        return $indexes;
    }

    private function pickEligibleTrack(int $lobbyId, array $selectedCategoryIds, array $excludedTrackIds, array $excludedFamilyIds = []): ?int
    {
        $where = ['f.is_active = 1', 't.is_active = 1', 't.is_validated = 1'];
        $params = [
            'pool_lobby_id_exists' => $lobbyId,
            'pool_lobby_id_empty' => $lobbyId,
        ];

        if (!empty($selectedCategoryIds)) {
            $categoryPlaceholders = [];
            foreach ($selectedCategoryIds as $index => $categoryId) {
                $key = 'category_' . $index;
                $categoryPlaceholders[] = ':' . $key;
                $params[$key] = $categoryId;
            }
            $where[] = 'f.category_id IN (' . implode(', ', $categoryPlaceholders) . ')';
        }

        if (!empty($excludedTrackIds)) {
            $trackPlaceholders = [];
            foreach ($excludedTrackIds as $index => $trackId) {
                $key = 'track_' . $index;
                $trackPlaceholders[] = ':' . $key;
                $params[$key] = $trackId;
            }
            $where[] = 't.id NOT IN (' . implode(', ', $trackPlaceholders) . ')';
        }

        if (!empty($excludedFamilyIds)) {
            $familyPlaceholders = [];
            foreach ($excludedFamilyIds as $index => $familyId) {
                $key = 'family_' . $index;
                $familyPlaceholders[] = ':' . $key;
                $params[$key] = $familyId;
            }
            $where[] = 't.family_id NOT IN (' . implode(', ', $familyPlaceholders) . ')';
        }

        $where[] = '(
            EXISTS (SELECT 1 FROM mq_lobby_track_pool p WHERE p.lobby_id = :pool_lobby_id_exists AND p.track_id = t.id)
            OR NOT EXISTS (SELECT 1 FROM mq_lobby_track_pool p2 WHERE p2.lobby_id = :pool_lobby_id_empty)
        )';

        $baseSql = 'FROM mq_tracks t
                    JOIN mq_families f ON f.id = t.family_id
                    WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare('SELECT COUNT(*) AS c ' . $baseSql);
        $countStmt->execute($params);
        $count = (int)($countStmt->fetch()['c'] ?? 0);
        if ($count <= 0) {
            return null;
        }

        $offset = random_int(0, $count - 1);
        $sql = 'SELECT t.id
                ' . $baseSql . '
                ORDER BY t.id ASC
                LIMIT 1 OFFSET ' . $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row && !empty($row['id']) ? (int)$row['id'] : null;
    }

    private function countFinishedRounds(int $lobbyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM mq_rounds WHERE lobby_id = :lobby_id AND status = "finished"'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function countLobbyPlayers(int $lobbyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c
             FROM mq_lobby_players
             WHERE lobby_id = :lobby_id
               AND presence_status <> "removed"'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function countEligibleLobbyPlayers(int $lobbyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c
             FROM mq_lobby_players
             WHERE lobby_id = :lobby_id
               AND presence_status <> "removed"
               AND presence_status <> "away"'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function countReadyVotes(int $lobbyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c
             FROM mq_lobby_players
             WHERE lobby_id = :lobby_id
               AND is_ready = 1
               AND presence_status <> "removed"
               AND presence_status <> "away"'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function countSolvedPlayersForRound(int $roundId, int $lobbyId = 0): int
    {
        if ($lobbyId > 0) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c
                 FROM mq_round_answers a
                 JOIN mq_lobby_players lp
                   ON lp.lobby_id = :lobby_id
                  AND lp.user_id = a.user_id
                  AND lp.presence_status <> "removed"
                  AND lp.presence_status <> "away"
                 WHERE a.round_id = :round_id
                   AND a.score_awarded > 0'
            );
            $stmt->execute(['round_id' => $roundId, 'lobby_id' => $lobbyId]);

            return (int)($stmt->fetch()['c'] ?? 0);
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c
             FROM mq_round_answers
             WHERE round_id = :round_id
               AND score_awarded > 0'
        );
        $stmt->execute(['round_id' => $roundId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function hasSolvedPlayersForRoundForUpdate(int $roundId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM mq_round_answers
             WHERE round_id = :round_id
               AND score_awarded > 0
             ORDER BY answered_at ASC, id ASC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['round_id' => $roundId]);

        return (bool)$stmt->fetch();
    }

    private function awardAwayBonusToAbsentPlayers(int $lobbyId, int $roundId, int $sourceUserId, int $sourceScore): array
    {
        if ($sourceScore <= 0 || MQ_AWAY_BONUS_PERCENT <= 0) {
            return ['score_awarded' => 0, 'players_count' => 0];
        }

        $bonus = max(1, (int)round($sourceScore * MQ_AWAY_BONUS_PERCENT / 100));
        $playersStmt = $this->db->prepare(
            'SELECT user_id
             FROM mq_lobby_players
             WHERE lobby_id = :lobby_id
               AND presence_status = "away"
               AND user_id <> :source_user_id
             FOR UPDATE'
        );
        $playersStmt->execute([
            'lobby_id' => $lobbyId,
            'source_user_id' => $sourceUserId,
        ]);
        $awayUserIds = array_map('intval', array_column($playersStmt->fetchAll(), 'user_id'));
        if (!$awayUserIds) {
            return ['score_awarded' => $bonus, 'players_count' => 0];
        }

        $insert = $this->db->prepare(
            'INSERT IGNORE INTO mq_round_away_bonuses
             (round_id, user_id, source_user_id, score_awarded, awarded_at)
             VALUES (:round_id, :user_id, :source_user_id, :score_awarded, NOW(3))'
        );
        $scoreUpdate = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET score = score + :score_awarded
             WHERE lobby_id = :lobby_id
               AND user_id = :user_id
               AND presence_status <> "removed"'
        );

        $awardedCount = 0;
        foreach ($awayUserIds as $awayUserId) {
            $insert->execute([
                'round_id' => $roundId,
                'user_id' => $awayUserId,
                'source_user_id' => $sourceUserId,
                'score_awarded' => $bonus,
            ]);
            if ($insert->rowCount() <= 0) {
                continue;
            }

            $scoreUpdate->execute([
                'score_awarded' => $bonus,
                'lobby_id' => $lobbyId,
                'user_id' => $awayUserId,
            ]);
            $awardedCount++;
        }

        return ['score_awarded' => $bonus, 'players_count' => $awardedCount];
    }

    private function countEarlyRevealVotes(int $roundId, int $lobbyId = 0): int
    {
        if ($lobbyId > 0) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c
                 FROM mq_round_reveal_votes v
                 JOIN mq_lobby_players lp
                   ON lp.lobby_id = :lobby_id
                  AND lp.user_id = v.user_id
                  AND lp.presence_status <> "removed"
                  AND lp.presence_status <> "away"
                 WHERE v.round_id = :round_id'
            );
            $stmt->execute(['round_id' => $roundId, 'lobby_id' => $lobbyId]);

            return (int)($stmt->fetch()['c'] ?? 0);
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c
             FROM mq_round_reveal_votes
             WHERE round_id = :round_id'
        );
        $stmt->execute(['round_id' => $roundId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function resetLobbyReadyVotes(int $lobbyId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET is_ready = 0
             WHERE lobby_id = :lobby_id
               AND presence_status <> "removed"'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
    }

    private function isTrackPlayableForLobby(int $lobbyId, int $trackId): bool
    {
        if ($trackId <= 0) {
            return false;
        }

        $lobby = $this->requireLobby($lobbyId);
        $selectedCategoryIds = $this->decodeCategoryIds($lobby['selected_category_ids'] ?? null);
        if (empty($selectedCategoryIds)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT t.id, f.category_id
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             WHERE t.id = :track_id
               AND f.is_active = 1
               AND t.is_active = 1
               AND t.is_validated = 1
               AND (
                 EXISTS (SELECT 1 FROM mq_lobby_track_pool p WHERE p.lobby_id = :pool_lobby_id_exists AND p.track_id = :pool_track_id)
                 OR NOT EXISTS (SELECT 1 FROM mq_lobby_track_pool p2 WHERE p2.lobby_id = :pool_lobby_id_empty)
               )
             LIMIT 1'
        );
        $stmt->execute([
            'track_id' => $trackId,
            'pool_track_id' => $trackId,
            'pool_lobby_id_exists' => $lobbyId,
            'pool_lobby_id_empty' => $lobbyId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        return in_array((int)$row['category_id'], $selectedCategoryIds, true);
    }

    private function consumeRoundPreloadLocked(int $lobbyId, int $roundNumber): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT track_id
             FROM mq_round_preloads
             WHERE lobby_id = :lobby_id
               AND round_number = :round_number
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'round_number' => $roundNumber,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $trackId = (int)$row['track_id'];
        $this->clearRoundPreload($lobbyId, $roundNumber);

        return $this->isTrackPlayableForLobby($lobbyId, $trackId) ? $trackId : null;
    }

    private function ensureRoundPreloadLocked(int $lobbyId, int $roundNumber, int $totalRounds): void
    {
        if ($roundNumber <= 0 || $roundNumber > $totalRounds) {
            $this->clearRoundPreload($lobbyId, $roundNumber);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT track_id
             FROM mq_round_preloads
             WHERE lobby_id = :lobby_id
               AND round_number = :round_number
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'round_number' => $roundNumber,
        ]);
        $existing = $stmt->fetch();
        if ($existing && $this->isTrackPlayableForLobby($lobbyId, (int)$existing['track_id'])) {
            return;
        }
        if ($existing) {
            $this->clearRoundPreload($lobbyId, $roundNumber);
        }

        try {
            $trackId = $this->pickTrackForLobby($lobbyId);
        } catch (RuntimeException) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO mq_round_preloads (lobby_id, round_number, track_id, created_at)
             VALUES (:lobby_id, :round_number, :track_id, NOW(3))
             ON DUPLICATE KEY UPDATE track_id = VALUES(track_id), created_at = VALUES(created_at)'
        );
        $insert->execute([
            'lobby_id' => $lobbyId,
            'round_number' => $roundNumber,
            'track_id' => $trackId,
        ]);
    }

    private function ensureUpcomingRoundPreloadsLocked(int $lobbyId, int $firstRoundNumber, int $totalRounds): void
    {
        $firstRoundNumber = max(1, $firstRoundNumber);
        $lastRoundNumber = min($totalRounds, $firstRoundNumber + MQ_TV_PRELOAD_LOOKAHEAD - 1);
        for ($roundNumber = $firstRoundNumber; $roundNumber <= $lastRoundNumber; $roundNumber++) {
            $this->ensureRoundPreloadLocked($lobbyId, $roundNumber, $totalRounds);
        }
    }

    private function clearRoundPreload(int $lobbyId, int $roundNumber): void
    {
        if ($roundNumber <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM mq_round_preloads
             WHERE lobby_id = :lobby_id
               AND round_number = :round_number'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'round_number' => $roundNumber,
        ]);
    }

    private function clearRoundPreloadsForTrack(int $lobbyId, int $trackId): void
    {
        if ($trackId <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM mq_round_preloads
             WHERE lobby_id = :lobby_id
               AND track_id = :track_id'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'track_id' => $trackId,
        ]);
    }

    private function clearRoundPreloadsForLobby(int $lobbyId): void
    {
        if ($lobbyId <= 0) {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM mq_round_preloads WHERE lobby_id = :lobby_id');
        $stmt->execute(['lobby_id' => $lobbyId]);
    }

    private function createRunningRoundLocked(int $lobbyId, int $trackId, int $roundNumber): void
    {
        $startExpression = 'DATE_ADD(NOW(3), INTERVAL ' . MQ_ROUND_PRELOAD_SECONDS . ' SECOND)';
        $insert = $this->db->prepare(
            'INSERT INTO mq_rounds (lobby_id, round_number, track_id, started_at, status)
             VALUES (:lobby_id, :round_number, :track_id, ' . $startExpression . ', "running")'
        );
        $insert->execute([
            'lobby_id' => $lobbyId,
            'round_number' => $roundNumber,
            'track_id' => $trackId,
        ]);

        $updateLobby = $this->db->prepare(
            'UPDATE mq_lobbies
             SET status = "playing",
                 current_track_id = :track_id,
                 playback_state = "playing",
                 playback_started_at = ' . $startExpression . ',
                 playback_offset_seconds = 0,
                 sync_revision = sync_revision + 1
             WHERE id = :id'
        );
        $updateLobby->execute([
            'track_id' => $trackId,
            'id' => $lobbyId,
        ]);

        $this->clearRoundPreloadsForTrack($lobbyId, $trackId);
        $this->resetLobbyReadyVotes($lobbyId);
    }

    private function transitionRoundToReveal(int $lobbyId, int $roundId, bool $unlockNextVoteImmediately = false, ?array $lobby = null): bool
    {
        $revealStartedAtExpression = 'COALESCE(reveal_started_at, NOW(3))';
        $params = ['id' => $roundId];

        if ($unlockNextVoteImmediately) {
            $revealDelaySeconds = $this->getRevealDelaySeconds($lobby ?? $this->requireLobby($lobbyId));
            $revealStartedAt = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
            $revealStartedAt = $revealStartedAt->sub(new DateInterval('PT' . $revealDelaySeconds . 'S'));

            $revealStartedAtExpression = ':reveal_started_at';
            $params['reveal_started_at'] = $revealStartedAt->format('Y-m-d H:i:s.u');
        }

        $upd = $this->db->prepare(
            'UPDATE mq_rounds
             SET status = "reveal",
                 reveal_started_at = ' . $revealStartedAtExpression . '
             WHERE id = :id
               AND status = "running"'
        );
        $upd->execute($params);

        if ($upd->rowCount() <= 0) {
            return false;
        }

        $this->db->prepare(
            'UPDATE mq_lobbies
             SET playback_state = "paused",
                 sync_revision = sync_revision + 1
             WHERE id = :id'
        )->execute(['id' => $lobbyId]);

        return true;
    }

    private function isRoundAnswerWindowOpen(array $lobby, array $round): bool
    {
        $status = strtolower((string)($round['status'] ?? ''));
        if ($status !== 'running') {
            return false;
        }

        $now = microtime(true);

        return $now >= $this->resolveRoundTimestamp($round, 'started_at')
            && $now < $this->getAnswerDeadlineTimestamp($lobby, $round);
    }

    private function isRoundWaitingToStart(array $round): bool
    {
        $startedAt = $this->resolveRoundTimestamp($round, 'started_at');

        return $startedAt > 0 && microtime(true) < $startedAt;
    }

    private function isNextVoteWindowOpen(array $lobby, array $round): bool
    {
        return microtime(true) >= $this->getNextVoteAvailableTimestamp($lobby, $round);
    }

    private function calculateTimedScore(array $lobby, array $round): int
    {
        $startedAt = $this->resolveRoundTimestamp($round, 'started_at');
        if ($startedAt <= 0) {
            return MQ_MIN_CORRECT_ANSWER_SCORE;
        }

        $duration = max(1, (int)($lobby['round_duration_seconds'] ?? MQ_DEFAULT_ROUND_DURATION));
        $elapsed = max(0.0, microtime(true) - $startedAt);
        if ($elapsed >= $duration) {
            return 0;
        }

        if ($elapsed < 1) {
            return MQ_MAX_CORRECT_ANSWER_SCORE;
        }

        $remainingRatio = max(0.0, 1 - ($elapsed / $duration));
        $score = MQ_MIN_CORRECT_ANSWER_SCORE
            + ((MQ_MAX_CORRECT_ANSWER_SCORE - MQ_MIN_CORRECT_ANSWER_SCORE) * ($remainingRatio ** 2));

        return max(MQ_MIN_CORRECT_ANSWER_SCORE, min(MQ_MAX_CORRECT_ANSWER_SCORE, (int)round($score)));
    }

    private function getAnswerDeadlineTimestamp(array $lobby, array $round): float
    {
        return $this->resolveRoundTimestamp($round, 'started_at')
            + max(1, (int)($lobby['round_duration_seconds'] ?? MQ_DEFAULT_ROUND_DURATION));
    }

    private function getNextVoteAvailableTimestamp(array $lobby, array $round): float
    {
        $revealStartedAt = $this->resolveRoundTimestamp($round, 'reveal_started_at');
        if ($revealStartedAt > 0) {
            return $revealStartedAt + $this->getRevealDelaySeconds($lobby);
        }

        return $this->getAnswerDeadlineTimestamp($lobby, $round) + $this->getRevealDelaySeconds($lobby);
    }

    private function getRevealDelaySeconds(array $lobby): int
    {
        return max(MQ_MIN_NEXT_VOTE_DELAY_SECONDS, (int)($lobby['reveal_duration_seconds'] ?? MQ_DEFAULT_REVEAL_DURATION));
    }

    private function parseSqlTimestampToUnix(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        $formats = ['Y-m-d H:i:s.u', 'Y-m-d H:i:s', DATE_ATOM];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone(date_default_timezone_get()));
            if ($parsed instanceof DateTimeImmutable) {
                return (float)$parsed->format('U.u');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? (float)$timestamp : 0.0;
    }

    private function resolveRoundTimestamp(array $round, string $baseField): float
    {
        $unixKey = $baseField . '_unix';
        $unixValue = isset($round[$unixKey]) ? (float)$round[$unixKey] : 0.0;
        if ($unixValue > 0) {
            return $unixValue;
        }

        return $this->parseSqlTimestampToUnix((string)($round[$baseField] ?? ''));
    }

    private function validateTotalRoundsValue($raw): int
    {
        if (!is_numeric($raw)) {
            throw new RuntimeException('Le nombre de manches doit etre un entier valide');
        }

        $value = (int)$raw;
        if ($value < MQ_MIN_TOTAL_ROUNDS || $value > MQ_MAX_TOTAL_ROUNDS) {
            throw new RuntimeException(
                sprintf('Le nombre de manches doit etre compris entre %d et %d', MQ_MIN_TOTAL_ROUNDS, MQ_MAX_TOTAL_ROUNDS)
            );
        }

        return $value;
    }

    private function validateRoundDurationValue($raw): int
    {
        if (!is_numeric($raw)) {
            throw new RuntimeException('Le chrono doit etre un entier valide');
        }

        $value = (int)$raw;
        if ($value < MQ_MIN_ROUND_DURATION || $value > MQ_MAX_ROUND_DURATION) {
            throw new RuntimeException(
                sprintf('Le chrono doit etre compris entre %d et %d secondes', MQ_MIN_ROUND_DURATION, MQ_MAX_ROUND_DURATION)
            );
        }

        return $value;
    }

    private function validateAnswerSimilarityThreshold($raw): int
    {
        if (!is_numeric($raw)) {
            throw new RuntimeException('Le seuil de validation doit etre un pourcentage valide');
        }

        $value = (int)$raw;
        if ($value < MQ_MIN_ANSWER_SIMILARITY_THRESHOLD || $value > MQ_MAX_ANSWER_SIMILARITY_THRESHOLD) {
            throw new RuntimeException(
                sprintf(
                    'Le seuil de validation doit etre compris entre %d%% et %d%%',
                    MQ_MIN_ANSWER_SIMILARITY_THRESHOLD,
                    MQ_MAX_ANSWER_SIMILARITY_THRESHOLD
                )
            );
        }

        return $value;
    }

    private function getAnswerSimilarityThreshold(array $lobby): int
    {
        return $this->validateAnswerSimilarityThreshold(
            $lobby['answer_similarity_threshold'] ?? MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD
        );
    }

    private function assertLobbyCanStart(array $lobby): array
    {
        $totalRounds = $this->validateTotalRoundsValue($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS);
        $roundDuration = $this->validateRoundDurationValue($lobby['round_duration_seconds'] ?? MQ_DEFAULT_ROUND_DURATION);
        $selectedCategoryIds = $this->decodeCategoryIds($lobby['selected_category_ids'] ?? null);

        if (empty($selectedCategoryIds)) {
            throw new RuntimeException('Sélectionne au moins une catégorie avant de lancer la partie');
        }

        $availableTracks = $this->countAvailableTracksForCategories($selectedCategoryIds);
        if ($availableTracks <= 0) {
            throw new RuntimeException('Aucune musique valide n\'est disponible dans les catégories sélectionnées');
        }

        if ($availableTracks < $totalRounds) {
            throw new RuntimeException(
                sprintf(
                    'Pas assez de musiques disponibles: %d musique(s) valide(s) pour %d manche(s) ciblee(s)',
                    $availableTracks,
                    $totalRounds
                )
            );
        }

        return [
            'total_rounds' => $totalRounds,
            'round_duration_seconds' => $roundDuration,
            'selected_category_ids' => $selectedCategoryIds,
            'available_tracks' => $availableTracks,
        ];
    }

    private function getDefaultSelectedCategoryIds(): array
    {
        $stmt = $this->db->query(
            'SELECT id
             FROM mq_categories
             WHERE is_active = 1
             ORDER BY name ASC'
        );

        return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function countAvailableTracksForCategories(array $categoryIds): int
    {
        $categoryIds = $this->normalizeCategoryIds($categoryIds);
        if (empty($categoryIds)) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($categoryIds as $index => $categoryId) {
            $key = 'category_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $categoryId;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT t.id) AS c
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             WHERE f.category_id IN (' . implode(', ', $placeholders) . ')
               AND f.is_active = 1
               AND t.is_active = 1
               AND t.is_validated = 1'
        );
        $stmt->execute($params);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function normalizeCategoryIds($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function normalizeLobbyName($raw, string $fallback = 'Nouveau lobby'): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string)$raw));
        $name = trim((string)$name);

        if ($name === '') {
            return $fallback;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 120);
        }

        return substr($name, 0, 120);
    }

    private function normalizeBoolean($raw): int
    {
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }

        if (is_numeric($raw)) {
            return ((int)$raw) === 1 ? 1 : 0;
        }

        $value = strtolower(trim((string)$raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    private function normalizeGameMode($raw): string
    {
        $mode = strtolower(trim((string)$raw));
        return in_array($mode, ['participative', 'autoplay'], true) ? $mode : 'participative';
    }

    private function encodeCategoryIds(array $ids): ?string
    {
        if (empty($ids)) {
            return null;
        }

        return json_encode(array_values($ids), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeCategoryIds($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeCategoryIds($decoded);
    }

    private function getExpectedTitleVariants(int $familyId, string $familyName): array
    {
        $variants = [];
        foreach ([$familyName, ...$this->listFamilyAliases($familyId)] as $value) {
            $label = trim((string)$value);
            if ($label === '') {
                continue;
            }

            $normalized = $this->normalize($label);
            if ($normalized === '' || isset($variants[$normalized])) {
                continue;
            }

            $variants[$normalized] = $label;
        }

        return array_values($variants);
    }

    private function listFamilyAliases(int $familyId): array
    {
        if ($familyId <= 0 || !$this->hasFamilyAliasesTable()) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT alias
             FROM mq_family_aliases
             WHERE family_id = :family_id
             ORDER BY alias ASC'
        );
        $stmt->execute(['family_id' => $familyId]);

        return array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['alias'] ?? '')),
            $stmt->fetchAll()
        ), static fn(string $value): bool => $value !== ''));
    }

    private function hasFamilyAliasesTable(): bool
    {
        if (self::$familyAliasesTableExistsCache !== null) {
            $this->familyAliasesTableExists = self::$familyAliasesTableExistsCache;
            return self::$familyAliasesTableExistsCache;
        }

        if ($this->familyAliasesTableExists !== null) {
            return $this->familyAliasesTableExists;
        }

        $stmt = $this->db->query(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'mq_family_aliases'
             LIMIT 1"
        );

        $this->familyAliasesTableExists = (bool)$stmt->fetchColumn();
        self::$familyAliasesTableExistsCache = $this->familyAliasesTableExists;
        return $this->familyAliasesTableExists;
    }

    private function getTrackPoolSnapshotItems(int $lobbyId): array
    {
        $mediaSelect = $this->buildTrackMediaSelect('t');
        $stmt = $this->db->prepare(
            'SELECT p.track_id, t.title, t.artist, ' . $mediaSelect . ', t.family_id
             FROM mq_lobby_track_pool p
             JOIN mq_tracks t ON t.id = p.track_id AND t.is_active = 1 AND t.is_validated = 1
             WHERE p.lobby_id = :lobby_id
             ORDER BY p.added_at ASC'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return $this->hydrateTrackRows($stmt->fetchAll());
    }

    private function buildRoundStateSnapshot(int $lobbyId, array $options = []): array
    {
        $lobby = $this->requireLobby($lobbyId);
        $viewerUserId = (int)($options['viewer_user_id'] ?? 0);
        $totalRounds = $this->validateTotalRoundsValue($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS);
        $preloadLimit = max(1, min(MQ_TV_PRELOAD_LOOKAHEAD, (int)($options['preload_limit'] ?? 1)));
        $includeWaitingPreloads = !empty($options['include_waiting_preloads']);
        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round) {
            $snapshot = [
                'server_time_unix' => microtime(true),
                'round' => null,
                'answers' => [],
            ];

            if ($includeWaitingPreloads) {
                $nextRoundNumber = $this->getNextRoundNumber($lobbyId);
                $upcomingTracks = $this->buildRoundPreloadTrackSnapshots($lobbyId, $nextRoundNumber, $totalRounds, $preloadLimit);
                $snapshot['next_round_number'] = $upcomingTracks[0]['round_number'] ?? null;
                $snapshot['next_track'] = $upcomingTracks[0] ?? null;
                $snapshot['upcoming_tracks'] = $upcomingTracks;
            }

            return $snapshot;
        }

        $track = $this->getTrackSnapshotById((int)$round['track_id']);
        $nextRoundNumber = (int)$round['round_number'] + 1;
        $upcomingTracks = $this->buildRoundPreloadTrackSnapshots($lobbyId, $nextRoundNumber, $totalRounds, $preloadLimit);
        $nextTrack = $upcomingTracks[0] ?? null;

        $answersStmt = $this->db->prepare(
            'SELECT a.user_id, u.username, a.guess_title, a.guess_artist, a.is_correct_title, a.is_correct_artist, a.score_awarded, a.answered_at
             FROM mq_round_answers a
             JOIN users u ON u.id = a.user_id
             WHERE a.round_id = :round_id
             ORDER BY a.answered_at ASC'
        );
        $answersStmt->execute(['round_id' => $round['id']]);
        $answers = $answersStmt->fetchAll();
        $solvedPlayers = $this->buildSolvedPlayerSnapshot($answers);
        $viewerSolved = $viewerUserId > 0 && $this->hasUserSolvedRoundAnswers($answers, $viewerUserId);

        $answerDeadline = $this->getAnswerDeadlineTimestamp($lobby, $round);
        $nextVoteAt = $this->getNextVoteAvailableTimestamp($lobby, $round);
        $isAcceptingAnswers = $this->isRoundAnswerWindowOpen($lobby, $round);
        $isWaitingToStart = $this->isRoundWaitingToStart($round);
        $isRevealVisible = (!$isWaitingToStart && !$isAcceptingAnswers) || strtolower((string)($round['status'] ?? '')) === 'reveal';
        $includeSolution = $isRevealVisible || $viewerSolved;
        $showTrackCategory = !empty($lobby['show_track_category']);
        $this->cleanupExpiredSuggestionHolds();

        return [
            'server_time_unix' => microtime(true),
            'round' => [
                'id' => (int)$round['id'],
                'lobby_id' => (int)$round['lobby_id'],
                'round_number' => (int)$round['round_number'],
                'status' => $round['status'],
                'started_at' => $round['started_at'],
                'started_at_unix' => $this->resolveRoundTimestamp($round, 'started_at'),
                'reveal_started_at' => $round['reveal_started_at'],
                'reveal_started_at_unix' => $this->resolveRoundTimestamp($round, 'reveal_started_at'),
                'ended_at' => $round['ended_at'],
                'ended_at_unix' => $this->resolveRoundTimestamp($round, 'ended_at'),
                'answer_deadline_at' => gmdate('c', (int)$answerDeadline),
                'answer_deadline_unix' => $answerDeadline,
                'next_vote_available_at' => gmdate('c', (int)$nextVoteAt),
                'next_vote_available_unix' => $nextVoteAt,
                'preload_seconds' => MQ_ROUND_PRELOAD_SECONDS,
                'reveal_delay_seconds' => $this->getRevealDelaySeconds($lobby),
                'is_accepting_answers' => $isAcceptingAnswers,
                'is_waiting_to_start' => $isWaitingToStart,
                'starts_in_seconds' => max(0.0, $this->resolveRoundTimestamp($round, 'started_at') - microtime(true)),
                'is_reveal_visible' => $isRevealVisible,
                'track' => $this->redactTrackSnapshot($track ?: null, $includeSolution, $showTrackCategory),
            ],
            'next_round_number' => $nextTrack['round_number'] ?? null,
            'next_track' => $nextTrack,
            'upcoming_tracks' => $upcomingTracks,
            'answers' => $this->filterRoundAnswersForViewer($answers, $isRevealVisible, $viewerUserId, $viewerSolved),
            'answer_attempts' => $this->filterRoundAttemptsForViewer(
                $this->buildRoundAnswerAttemptSnapshot((int)$round['id']),
                $isRevealVisible,
                $viewerUserId,
                $viewerSolved
            ),
            'solved_players' => $solvedPlayers,
            'early_reveal_votes' => $this->buildEarlyRevealVoteSnapshot((int)$round['id']),
            'suggestion_holds' => $this->buildSuggestionHoldSnapshot((int)$round['id']),
        ];
    }

    private function buildRoundPreloadTrackSnapshots(int $lobbyId, int $firstRoundNumber, int $totalRounds, int $limit): array
    {
        if ($firstRoundNumber <= 0 || $firstRoundNumber > $totalRounds || $limit <= 0) {
            return [];
        }

        $items = [];
        $lastRoundNumber = min($totalRounds, $firstRoundNumber + $limit - 1);
        for ($roundNumber = $firstRoundNumber; $roundNumber <= $lastRoundNumber; $roundNumber++) {
            $track = $this->buildRoundPreloadTrackSnapshot($lobbyId, $roundNumber, $totalRounds);
            if (!$track) {
                continue;
            }

            $track = $this->redactTrackSnapshot($track, false, false) ?? [];
            $track['round_number'] = $roundNumber;
            $items[] = $track;
        }

        return $items;
    }

    private function buildRoundPreloadTrackSnapshot(int $lobbyId, int $roundNumber, int $totalRounds): ?array
    {
        if ($roundNumber <= 0 || $roundNumber > $totalRounds) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT track_id
             FROM mq_round_preloads
             WHERE lobby_id = :lobby_id
               AND round_number = :round_number
             LIMIT 1'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'round_number' => $roundNumber,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $trackId = (int)$row['track_id'];
        if (!$this->isTrackPlayableForLobby($lobbyId, $trackId)) {
            return null;
        }

        return $this->getTrackSnapshotById($trackId);
    }

    private function getTrackSnapshotById(int $trackId): ?array
    {
        if ($trackId <= 0) {
            return null;
        }

        $mediaSelect = $this->buildTrackMediaSelect('t');
        $trackStmt = $this->db->prepare(
            'SELECT t.id, t.title, t.artist, t.start_offset_seconds, ' . $mediaSelect . ', f.id AS family_id, f.name AS family_name, c.id AS category_id, c.name AS category_name
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             JOIN mq_categories c ON c.id = f.category_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $trackStmt->execute(['id' => $trackId]);
        $track = $trackStmt->fetch();

        return $track ? $this->hydrateTrackRow($track) : null;
    }

    private function redactTrackSnapshot(?array $track, bool $includeSolution, bool $includeCategory): ?array
    {
        if (!$track) {
            return null;
        }

        if ($includeSolution) {
            return $track;
        }

        $redacted = [
            'youtube_video_id' => $track['youtube_video_id'] ?? null,
            'start_offset_seconds' => isset($track['start_offset_seconds']) ? (int)$track['start_offset_seconds'] : 0,
        ];

        if ($includeCategory) {
            $redacted['category_id'] = isset($track['category_id']) ? (int)$track['category_id'] : null;
            $redacted['category_name'] = $track['category_name'] ?? null;
        }

        return $redacted;
    }

    private function buildScoreboardSnapshot(int $lobbyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT lp.user_id, u.username, u.avatar_url, lp.role, lp.score, lp.presence_status
             FROM mq_lobby_players lp
             JOIN users u ON u.id = lp.user_id
             WHERE lp.lobby_id = :lobby_id
               AND lp.presence_status <> "removed"
             ORDER BY lp.score DESC, lp.joined_at ASC'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return ['items' => $this->hydrateAvatarRows($stmt->fetchAll(), 'avatar_url', 'user_id')];
    }

    private function buildEarlyRevealVoteSnapshot(int $roundId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.user_id, u.username, u.avatar_url, v.voted_at
             FROM mq_round_reveal_votes v
             JOIN users u ON u.id = v.user_id
             JOIN mq_rounds r ON r.id = v.round_id
             JOIN mq_lobby_players lp
               ON lp.lobby_id = r.lobby_id
              AND lp.user_id = v.user_id
              AND lp.presence_status <> "removed"
              AND lp.presence_status <> "away"
             WHERE v.round_id = :round_id
             ORDER BY v.voted_at ASC'
        );
        $stmt->execute(['round_id' => $roundId]);

        return $this->hydrateAvatarRows($stmt->fetchAll(), 'avatar_url', 'user_id');
    }

    private function buildSolvedPlayerSnapshot(array $answers): array
    {
        $items = [];
        foreach ($answers as $answer) {
            if ((int)($answer['score_awarded'] ?? 0) <= 0) {
                continue;
            }

            $items[] = [
                'user_id' => (int)($answer['user_id'] ?? 0),
                'username' => (string)($answer['username'] ?? ''),
                'score_awarded' => (int)($answer['score_awarded'] ?? 0),
                'answered_at' => $answer['answered_at'] ?? null,
            ];
        }

        return $items;
    }

    private function hasUserSolvedRoundAnswers(array $answers, int $userId): bool
    {
        foreach ($answers as $answer) {
            if ((int)($answer['user_id'] ?? 0) === $userId && (int)($answer['score_awarded'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function filterRoundAnswersForViewer(array $answers, bool $isRevealVisible, int $viewerUserId, bool $viewerSolved): array
    {
        if ($isRevealVisible) {
            return $answers;
        }

        return array_values(array_filter($answers, static function (array $answer) use ($viewerUserId, $viewerSolved): bool {
            $userId = (int)($answer['user_id'] ?? 0);
            if ($viewerUserId > 0 && $userId === $viewerUserId) {
                return true;
            }

            return $viewerSolved && (int)($answer['score_awarded'] ?? 0) > 0;
        }));
    }

    private function buildRoundAnswerAttemptSnapshot(int $roundId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.user_id, u.username, a.guess_title, a.guess_artist, a.is_correct, a.score_awarded, a.created_at
             FROM mq_round_answer_attempts a
             JOIN users u ON u.id = a.user_id
             WHERE a.round_id = :round_id
             ORDER BY a.created_at ASC, a.id ASC
             LIMIT 200'
        );
        $stmt->execute(['round_id' => $roundId]);

        return $stmt->fetchAll();
    }

    private function filterRoundAttemptsForViewer(array $attempts, bool $isRevealVisible, int $viewerUserId, bool $viewerSolved): array
    {
        $visible = array_values(array_filter($attempts, static function (array $attempt) use ($isRevealVisible, $viewerUserId): bool {
            if ((int)($attempt['is_correct'] ?? 0) === 1 || (int)($attempt['score_awarded'] ?? 0) > 0) {
                return false;
            }

            $userId = (int)($attempt['user_id'] ?? 0);
            if (!$isRevealVisible && ($viewerUserId <= 0 || $userId !== $viewerUserId)) {
                return false;
            }

            return trim((string)($attempt['guess_title'] ?? '') . (string)($attempt['guess_artist'] ?? '')) !== '';
        }));

        return array_slice($visible, -MQ_VISIBLE_MISSED_ATTEMPTS_LIMIT);
    }

    private function buildSuggestionHoldSnapshot(int $roundId): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.user_id, u.username, u.avatar_url, h.expires_at
             FROM mq_round_suggestion_holds h
             JOIN users u ON u.id = h.user_id
             WHERE h.round_id = :round_id
               AND h.expires_at > NOW(3)
             ORDER BY h.updated_at ASC'
        );
        $stmt->execute(['round_id' => $roundId]);

        return $this->hydrateAvatarRows($stmt->fetchAll(), 'avatar_url', 'user_id');
    }

    private function countActiveSuggestionHolds(int $roundId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c
             FROM mq_round_suggestion_holds
             WHERE round_id = :round_id
               AND expires_at > NOW(3)'
        );
        $stmt->execute(['round_id' => $roundId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    }

    private function recordRoundAnswerAttempt(
        int $roundId,
        int $userId,
        string $guessTitle,
        string $guessArtist,
        bool $isCorrect,
        int $scoreAwarded
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_round_answer_attempts
             (round_id, user_id, guess_title, guess_artist, is_correct, score_awarded, created_at)
             VALUES (:round_id, :user_id, :guess_title, :guess_artist, :is_correct, :score_awarded, NOW(3))'
        );
        $stmt->execute([
            'round_id' => $roundId,
            'user_id' => $userId,
            'guess_title' => trim($guessTitle) !== '' ? trim($guessTitle) : null,
            'guess_artist' => trim($guessArtist) !== '' ? trim($guessArtist) : null,
            'is_correct' => $isCorrect ? 1 : 0,
            'score_awarded' => $scoreAwarded,
        ]);
    }

    private function cleanupExpiredSuggestionHolds(): void
    {
        $this->db->exec('DELETE FROM mq_round_suggestion_holds WHERE expires_at <= NOW(3)');
    }

    private function buildTrackMediaSelect(string $alias): string
    {
        if ($this->hasYoutubeUrlColumn()) {
            return $alias . '.youtube_video_id, ' . $alias . '.youtube_url';
        }

        return $alias . '.youtube_video_id, NULL AS youtube_url';
    }

    private function hydrateAvatarRows(array $rows, string $avatarColumn, string $userIdColumn): array
    {
        return array_map(function (array $row) use ($avatarColumn, $userIdColumn): array {
            $row[$avatarColumn] = $this->normalizeAvatarUrl(
                $row[$avatarColumn] ?? null,
                isset($row[$userIdColumn]) ? (int)$row[$userIdColumn] : 0
            );

            return $row;
        }, $rows);
    }

    private function normalizeAvatarUrl($avatarUrl, int $userId): string
    {
        $avatarUrl = trim((string)($avatarUrl ?? ''));
        if ($avatarUrl === '') {
            return '';
        }

        if (!str_contains($avatarUrl, 'action=getAvatar')) {
            return $avatarUrl;
        }

        if ($userId <= 0) {
            return $avatarUrl;
        }

        $version = null;
        $query = parse_url($avatarUrl, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (isset($params['v']) && (string)$params['v'] !== '') {
                $version = (string)$params['v'];
            }
        }

        return $this->buildAuthAvatarUrl($userId, $version);
    }

    private function buildAuthAvatarUrl(int $userId, ?string $version = null): string
    {
        $base = rtrim(MQ_AUTH_BASE_API, '/');
        $separator = str_ends_with($base, 'index.php') ? '?' : '/?';
        $params = [
            'action' => 'getAvatar',
            'user_id' => $userId,
        ];

        if ($version !== null && $version !== '') {
            $params['v'] = $version;
        }

        return $base . $separator . http_build_query($params);
    }

    private function hydrateTrackRows(array $rows): array
    {
        return array_map(fn(array $row): array => $this->hydrateTrackRow($row), $rows);
    }

    private function hydrateTrackRow(array $row): array
    {
        $videoId = mq_normalize_youtube_video_id((string)($row['youtube_video_id'] ?? ''));
        if ($videoId === '' && $this->hasYoutubeUrlColumn()) {
            $videoId = mq_normalize_youtube_video_id((string)($row['youtube_url'] ?? ''));
        }

        $row['youtube_video_id'] = $videoId;
        $row['youtube_url'] = mq_build_youtube_watch_url($videoId);
        $row['start_offset_seconds'] = max(0, (int)($row['start_offset_seconds'] ?? 0));

        return $row;
    }

    private function hasYoutubeUrlColumn(): bool
    {
        if (self::$youtubeUrlColumnExistsCache !== null) {
            $this->youtubeUrlColumnExists = self::$youtubeUrlColumnExistsCache;
            return self::$youtubeUrlColumnExistsCache;
        }

        if ($this->youtubeUrlColumnExists !== null) {
            return $this->youtubeUrlColumnExists;
        }

        $stmt = $this->db->query(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'mq_tracks'
               AND column_name = 'youtube_url'
             LIMIT 1"
        );

        $this->youtubeUrlColumnExists = (bool)$stmt->fetchColumn();
        self::$youtubeUrlColumnExistsCache = $this->youtubeUrlColumnExists;
        return $this->youtubeUrlColumnExists;
    }

    private function isGuessCorrectAgainstVariants(string $guess, array $expectedValues, int $threshold = 100): bool
    {
        $normalizedGuess = $this->normalize($guess);
        if ($normalizedGuess === '') {
            return false;
        }

        foreach ($expectedValues as $expectedValue) {
            if ($this->isNormalizedGuessAccepted($normalizedGuess, $this->normalize((string)$expectedValue), $threshold)) {
                return true;
            }
        }

        return false;
    }

    private function isGuessCorrect(string $guess, string $expected, int $threshold = 100): bool
    {
        $g = $this->normalize($guess);
        $e = $this->normalize($expected);
        return $this->isNormalizedGuessAccepted($g, $e, $threshold);
    }

    private function isNormalizedGuessAccepted(string $normalizedGuess, string $normalizedExpected, int $threshold): bool
    {
        if ($normalizedGuess === '' || $normalizedExpected === '') {
            return false;
        }

        if ($normalizedGuess === $normalizedExpected) {
            return true;
        }

        if ($threshold >= MQ_MAX_ANSWER_SIMILARITY_THRESHOLD) {
            return false;
        }

        $guessLength = strlen($normalizedGuess);
        $expectedLength = strlen($normalizedExpected);
        $maxLength = max($guessLength, $expectedLength);
        if ($maxLength === 0) {
            return false;
        }

        $lengthRatio = min($guessLength, $expectedLength) / $maxLength;
        if ($lengthRatio < 0.65) {
            return false;
        }

        $effectiveThreshold = $this->getEffectiveAnswerThreshold($threshold, $expectedLength);
        return $this->calculateAnswerSimilarityPercent($normalizedGuess, $normalizedExpected) >= $effectiveThreshold;
    }

    private function getEffectiveAnswerThreshold(int $requestedThreshold, int $expectedLength): int
    {
        if ($expectedLength <= 4) {
            return MQ_MAX_ANSWER_SIMILARITY_THRESHOLD;
        }

        if ($expectedLength <= 7) {
            return max($requestedThreshold, 94);
        }

        if ($expectedLength <= 10) {
            return max($requestedThreshold, 90);
        }

        return $requestedThreshold;
    }

    private function calculateAnswerSimilarityPercent(string $guess, string $expected): float
    {
        $maxLength = max(strlen($guess), strlen($expected));
        if ($maxLength === 0) {
            return 0.0;
        }

        $levenshteinDistance = levenshtein($guess, $expected);
        $levenshteinScore = max(0.0, (1 - ($levenshteinDistance / $maxLength)) * 100);

        similar_text($guess, $expected, $similarTextScore);
        $jaroWinklerScore = $this->calculateJaroWinklerSimilarity($guess, $expected) * 100;

        return max($levenshteinScore, (float)$similarTextScore, $jaroWinklerScore);
    }

    private function calculateJaroWinklerSimilarity(string $source, string $target): float
    {
        if ($source === $target) {
            return 1.0;
        }

        $sourceLength = strlen($source);
        $targetLength = strlen($target);
        if ($sourceLength === 0 || $targetLength === 0) {
            return 0.0;
        }

        $matchDistance = max(0, intdiv(max($sourceLength, $targetLength), 2) - 1);
        $sourceMatches = array_fill(0, $sourceLength, false);
        $targetMatches = array_fill(0, $targetLength, false);
        $matches = 0;

        for ($i = 0; $i < $sourceLength; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $targetLength);

            for ($j = $start; $j < $end; $j++) {
                if ($targetMatches[$j] || $source[$i] !== $target[$j]) {
                    continue;
                }

                $sourceMatches[$i] = true;
                $targetMatches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $targetIndex = 0;
        for ($i = 0; $i < $sourceLength; $i++) {
            if (!$sourceMatches[$i]) {
                continue;
            }

            while ($targetIndex < $targetLength && !$targetMatches[$targetIndex]) {
                $targetIndex++;
            }

            if ($targetIndex < $targetLength && $source[$i] !== $target[$targetIndex]) {
                $transpositions++;
            }
            $targetIndex++;
        }

        $jaro = (
            ($matches / $sourceLength)
            + ($matches / $targetLength)
            + (($matches - ($transpositions / 2)) / $matches)
        ) / 3;

        $prefixLength = 0;
        $maxPrefix = min(4, $sourceLength, $targetLength);
        for ($i = 0; $i < $maxPrefix; $i++) {
            if ($source[$i] !== $target[$i]) {
                break;
            }
            $prefixLength++;
        }

        return $jaro + ($prefixLength * 0.1 * (1 - $jaro));
    }

    private function normalize(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        if (class_exists('Transliterator')) {
            $transliterated = transliterator_transliterate(
                'Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC',
                $v
            );
            if (is_string($transliterated) && $transliterated !== '') {
                $v = $transliterated;
            }
        } else {
            $iconvValue = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
            if ($iconvValue !== false && $iconvValue !== '') {
                $v = $iconvValue;
            }
        }

        if (function_exists('mb_strtolower')) {
            $v = mb_strtolower($v, 'UTF-8');
        } else {
            $v = strtolower($v);
        }

        $v = preg_replace('/[^a-z0-9]+/i', '', $v);

        return (string)($v ?? '');
    }

    private function generateLobbyCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $stmt = $this->db->prepare('SELECT 1 FROM mq_lobbies WHERE lobby_code = :code LIMIT 1');
            $stmt->execute(['code' => $code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }

        throw new RuntimeException('Impossible de générer un code de lobby unique');
    }
}

