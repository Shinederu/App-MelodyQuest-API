<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/../config/config.php';

class LobbyService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function createLobby(int $ownerUserId, array $payload): array
    {
        $this->cleanupStaleOwnerLobbies();

        $name = trim((string)($payload['name'] ?? 'Lobby'));
        if ($name === '') {
            $name = 'Lobby';
        }

        $maxPlayers = (int)($payload['max_players'] ?? MQ_DEFAULT_MAX_PLAYERS);
        $maxPlayers = max(2, min($maxPlayers, MQ_MAX_MAX_PLAYERS));

        $totalRounds = (int)($payload['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS);
        $totalRounds = max(1, min($totalRounds, 50));

        $roundDuration = (int)($payload['round_duration_seconds'] ?? MQ_DEFAULT_ROUND_DURATION);
        $roundDuration = max(10, min($roundDuration, 300));

        $revealDuration = (int)($payload['reveal_duration_seconds'] ?? MQ_DEFAULT_REVEAL_DURATION);
        $revealDuration = max(3, min($revealDuration, 60));

        $visibility = strtolower((string)($payload['visibility'] ?? 'private'));
        if (!in_array($visibility, ['public', 'private'], true)) {
            $visibility = 'private';
        }

        $guessMode = strtolower((string)($payload['guess_mode'] ?? 'both'));
        if (!in_array($guessMode, ['title', 'artist', 'both'], true)) {
            $guessMode = 'both';
        }

        $selectedCategoryIds = $this->normalizeCategoryIds($payload['selected_category_ids'] ?? []);

        $code = $this->generateLobbyCode();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mq_lobbies
                (lobby_code, name, owner_user_id, status, visibility, max_players, total_rounds, round_duration_seconds, reveal_duration_seconds, guess_mode, selected_category_ids)
                VALUES (:code, :name, :owner, "waiting", :visibility, :max_players, :total_rounds, :round_duration, :reveal_duration, :guess_mode, :selected_category_ids)'
            );
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'owner' => $ownerUserId,
                'visibility' => $visibility,
                'max_players' => $maxPlayers,
                'total_rounds' => $totalRounds,
                'round_duration' => $roundDuration,
                'reveal_duration' => $revealDuration,
                'guess_mode' => $guessMode,
                'selected_category_ids' => $this->encodeCategoryIds($selectedCategoryIds),
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

        $countStmt = $this->db->prepare('SELECT COUNT(*) AS c FROM mq_lobby_players WHERE lobby_id = :id');
        $countStmt->execute(['id' => $lobby['id']]);
        $count = (int)$countStmt->fetch()['c'];
        if ($count >= (int)$lobby['max_players']) {
            throw new RuntimeException('Lobby plein');
        }

        $upsert = $this->db->prepare(
            'INSERT INTO mq_lobby_players (lobby_id, user_id, role, is_ready, score)
             VALUES (:lobby_id, :user_id, "player", 0, 0)
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
        );
        $upsert->execute([
            'lobby_id' => $lobby['id'],
            'user_id' => $userId,
        ]);

        return $this->getLobbyById((int)$lobby['id']);
    }

    public function leaveLobby(int $userId, int $lobbyId): array
    {
        $lobby = $this->requireLobby($lobbyId);
        $this->requireLobbyMember($lobbyId, $userId);

        $del = $this->db->prepare('DELETE FROM mq_lobby_players WHERE lobby_id = :lobby_id AND user_id = :user_id');
        $del->execute(['lobby_id' => $lobbyId, 'user_id' => $userId]);

        if ((int)$lobby['owner_user_id'] === $userId) {
            $nextStmt = $this->db->prepare(
                'SELECT user_id FROM mq_lobby_players WHERE lobby_id = :lobby_id ORDER BY joined_at ASC LIMIT 1'
            );
            $nextStmt->execute(['lobby_id' => $lobbyId]);
            $next = $nextStmt->fetch();

            if ($next && !empty($next['user_id'])) {
                $newOwner = (int)$next['user_id'];
                $upd = $this->db->prepare('UPDATE mq_lobbies SET owner_user_id = :owner WHERE id = :id');
                $upd->execute(['owner' => $newOwner, 'id' => $lobbyId]);
                $role = $this->db->prepare(
                    'UPDATE mq_lobby_players SET role = CASE WHEN user_id = :owner THEN "owner" ELSE "player" END WHERE lobby_id = :lobby_id'
                );
                $role->execute(['owner' => $newOwner, 'lobby_id' => $lobbyId]);
            } else {
                $close = $this->db->prepare('UPDATE mq_lobbies SET status = "closed" WHERE id = :id');
                $close->execute(['id' => $lobbyId]);
            }
        }

        return ['ok' => true];
    }

    public function touchLobbyPresence(int $userId, int $lobbyId): array
    {
        $this->requireLobbyMember($lobbyId, $userId);
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        return ['ok' => true];
    }

    public function kickPlayer(int $ownerUserId, int $lobbyId, int $targetUserId): array
    {
        $this->touchLobbyMember($lobbyId, $ownerUserId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $ownerUserId);

        if ($targetUserId === $ownerUserId) {
            throw new RuntimeException('Le createur ne peut pas s exclure lui-meme');
        }

        $this->requireLobbyMember($lobbyId, $targetUserId);

        $stmt = $this->db->prepare(
            'DELETE FROM mq_lobby_players WHERE lobby_id = :lobby_id AND user_id = :user_id'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $targetUserId,
        ]);

        return $this->getLobbyById($lobbyId);
    }

    public function deleteLobby(int $ownerUserId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $ownerUserId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $ownerUserId);

        $stmt = $this->db->prepare('DELETE FROM mq_lobbies WHERE id = :id');
        $stmt->execute(['id' => $lobbyId]);

        return ['ok' => true, 'deleted_lobby_id' => $lobbyId];
    }

    public function updateLobbyConfig(int $userId, int $lobbyId, array $payload): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $fields = [];
        $params = ['id' => $lobbyId];

        if (isset($payload['name'])) {
            $fields[] = 'name = :name';
            $params['name'] = trim((string)$payload['name']) ?: 'Lobby';
        }
        if (isset($payload['visibility'])) {
            $visibility = strtolower((string)$payload['visibility']);
            if (!in_array($visibility, ['public', 'private'], true)) {
                throw new RuntimeException('visibility invalide');
            }
            $fields[] = 'visibility = :visibility';
            $params['visibility'] = $visibility;
        }
        if (isset($payload['max_players'])) {
            $maxPlayers = max(2, min((int)$payload['max_players'], MQ_MAX_MAX_PLAYERS));
            $fields[] = 'max_players = :max_players';
            $params['max_players'] = $maxPlayers;
        }
        if (isset($payload['total_rounds'])) {
            $totalRounds = max(1, min((int)$payload['total_rounds'], 50));
            $fields[] = 'total_rounds = :total_rounds';
            $params['total_rounds'] = $totalRounds;
        }
        if (isset($payload['round_duration_seconds'])) {
            $duration = max(10, min((int)$payload['round_duration_seconds'], 300));
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
        }

        if (!empty($fields)) {
            $sql = 'UPDATE mq_lobbies SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return $this->getLobbyById($lobbyId);
    }

    public function addTrackToPool(int $userId, int $lobbyId, int $trackId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $trackStmt = $this->db->prepare('SELECT id FROM mq_tracks WHERE id = :id AND is_active = 1 LIMIT 1');
        $trackStmt->execute(['id' => $trackId]);
        if (!$trackStmt->fetch()) {
            throw new RuntimeException('Track introuvable ou inactive');
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

        return $this->listTrackPool($userId, $lobbyId);
    }

    public function listTrackPool(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobby($lobbyId);
        $this->requireLobbyMember($lobbyId, $userId);

        $stmt = $this->db->prepare(
            'SELECT p.track_id, t.title, t.artist, t.youtube_url, t.youtube_video_id, t.family_id
             FROM mq_lobby_track_pool p
             JOIN mq_tracks t ON t.id = p.track_id
             WHERE p.lobby_id = :lobby_id
             ORDER BY p.added_at ASC'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);

        return ['items' => $stmt->fetchAll()];
    }

    public function startRound(int $userId, int $lobbyId, array $payload = []): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireOwner($lobby, $userId);

        $running = $this->getCurrentRoundRow($lobbyId);
        if ($running) {
            throw new RuntimeException('Une manche est deja en cours');
        }

        $finishedRounds = $this->countFinishedRounds($lobbyId);
        $targetRounds = max(1, (int)($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS));
        if ($finishedRounds >= $targetRounds) {
            throw new RuntimeException('Toutes les manches de ce lobby ont deja ete jouees');
        }

        $trackId = isset($payload['track_id']) ? (int)$payload['track_id'] : 0;
        if ($trackId <= 0) {
            $trackId = $this->pickTrackForLobby($lobbyId);
        } else {
            $trackCheck = $this->db->prepare(
                'SELECT id FROM mq_tracks
                 WHERE id = :id
                   AND is_active = 1
                   AND (
                     EXISTS (SELECT 1 FROM mq_lobby_track_pool p WHERE p.lobby_id = :lobby_id AND p.track_id = :id)
                     OR NOT EXISTS (SELECT 1 FROM mq_lobby_track_pool p2 WHERE p2.lobby_id = :lobby_id)
                   )
                 LIMIT 1'
            );
            $trackCheck->execute(['id' => $trackId, 'lobby_id' => $lobbyId]);
            if (!$trackCheck->fetch()) {
                throw new RuntimeException('Track invalide pour ce lobby');
            }
        }

        $roundNumberStmt = $this->db->prepare(
            'SELECT COALESCE(MAX(round_number), 0) + 1 AS next_round FROM mq_rounds WHERE lobby_id = :lobby_id'
        );
        $roundNumberStmt->execute(['lobby_id' => $lobbyId]);
        $roundNumber = (int)$roundNumberStmt->fetch()['next_round'];

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO mq_rounds (lobby_id, round_number, track_id, started_at, status)
                 VALUES (:lobby_id, :round_number, :track_id, NOW(3), "running")'
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
                     playback_started_at = NOW(3),
                     playback_offset_seconds = 0,
                     sync_revision = sync_revision + 1
                 WHERE id = :id'
            );
            $updateLobby->execute([
                'track_id' => $trackId,
                'id' => $lobbyId,
            ]);

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

        $upd = $this->db->prepare(
            'UPDATE mq_rounds
             SET status = "reveal",
                 reveal_started_at = COALESCE(reveal_started_at, NOW(3))
             WHERE id = :id'
        );
        $upd->execute(['id' => $round['id']]);

        $this->db->prepare(
            'UPDATE mq_lobbies
             SET playback_state = "paused",
                 sync_revision = sync_revision + 1
             WHERE id = :id'
        )->execute(['id' => $lobbyId]);

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
            'status' => $this->countFinishedRounds($lobbyId) >= max(1, (int)($lobby['total_rounds'] ?? MQ_DEFAULT_TOTAL_ROUNDS))
                ? 'finished'
                : 'waiting',
            'id' => $lobbyId,
        ]);

        return [
            'round' => $this->getRoundState($userId, $lobbyId),
            'scoreboard' => $this->getScoreboard($userId, $lobbyId),
        ];
    }

    public function submitAnswer(int $userId, int $lobbyId, array $payload): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);
        $this->requireLobbyMember($lobbyId, $userId);

        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round) {
            throw new RuntimeException('Aucune manche en cours');
        }

        $trackStmt = $this->db->prepare('SELECT title, artist FROM mq_tracks WHERE id = :id LIMIT 1');
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

        if ($mode === 'title' || $mode === 'both') {
            $isCorrectTitle = $this->isGuessCorrect($guessTitle, (string)$track['title']) ? 1 : 0;
        }
        if ($mode === 'artist' || $mode === 'both') {
            $isCorrectArtist = $this->isGuessCorrect($guessArtist, (string)$track['artist']) ? 1 : 0;
        }

        $scoreAwarded = $isCorrectTitle + $isCorrectArtist;

        $prevStmt = $this->db->prepare(
            'SELECT id, score_awarded
             FROM mq_round_answers
             WHERE round_id = :round_id AND user_id = :user_id
             LIMIT 1'
        );
        $prevStmt->execute(['round_id' => $round['id'], 'user_id' => $userId]);
        $prev = $prevStmt->fetch();
        $previousScore = $prev ? (int)$prev['score_awarded'] : 0;
        $delta = $scoreAwarded - $previousScore;

        $this->db->beginTransaction();
        try {
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

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'score_awarded' => $scoreAwarded,
            'is_correct_title' => (bool)$isCorrectTitle,
            'is_correct_artist' => (bool)$isCorrectArtist,
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
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobbyMember($lobbyId, $userId);

        $round = $this->getCurrentRoundRow($lobbyId);
        if (!$round) {
            return ['round' => null, 'answers' => []];
        }

        $trackStmt = $this->db->prepare(
            'SELECT id, title, artist, youtube_url, youtube_video_id
             FROM mq_tracks
             WHERE id = :id
             LIMIT 1'
        );
        $trackStmt->execute(['id' => $round['track_id']]);
        $track = $trackStmt->fetch();

        $answersStmt = $this->db->prepare(
            'SELECT a.user_id, u.username, a.guess_title, a.guess_artist, a.is_correct_title, a.is_correct_artist, a.score_awarded, a.answered_at
             FROM mq_round_answers a
             JOIN users u ON u.id = a.user_id
             WHERE a.round_id = :round_id
             ORDER BY a.answered_at ASC'
        );
        $answersStmt->execute(['round_id' => $round['id']]);
        $answers = $answersStmt->fetchAll();

        return [
            'round' => [
                'id' => (int)$round['id'],
                'lobby_id' => (int)$round['lobby_id'],
                'round_number' => (int)$round['round_number'],
                'status' => $round['status'],
                'started_at' => $round['started_at'],
                'reveal_started_at' => $round['reveal_started_at'],
                'ended_at' => $round['ended_at'],
                'track' => $track ?: null,
            ],
            'answers' => $answers,
        ];
    }

    public function getScoreboard(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobbyMember($lobbyId, $userId);

        $stmt = $this->db->prepare(
            'SELECT lp.user_id, u.username, lp.role, lp.score
             FROM mq_lobby_players lp
             JOIN users u ON u.id = lp.user_id
             WHERE lp.lobby_id = :lobby_id
             ORDER BY lp.score DESC, lp.joined_at ASC'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
        return ['items' => $stmt->fetchAll()];
    }

    public function getLobbyById(int $lobbyId): array
    {
        $this->cleanupStaleOwnerLobbies();

        $lobby = $this->requireLobby($lobbyId);

        $playersStmt = $this->db->prepare(
            'SELECT lp.user_id, lp.role, lp.is_ready, lp.score, lp.joined_at, u.username
             FROM mq_lobby_players lp
             JOIN users u ON u.id = lp.user_id
             WHERE lp.lobby_id = :id
             ORDER BY lp.joined_at ASC'
        );
        $playersStmt->execute(['id' => $lobbyId]);
        $players = $playersStmt->fetchAll();

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
            throw new RuntimeException('Acces refuse a ce lobby prive');
        }

        if ($isMember) {
            $this->touchLobbyMember($lobbyId, $userId);
        }

        return $this->getLobbyById($lobbyId);
    }

    public function getLobbyRealtimeSnapshot(int $userId, int $lobbyId): array
    {
        $this->touchLobbyMember($lobbyId, $userId);
        $this->cleanupStaleOwnerLobbies();

        $this->requireLobbyMember($lobbyId, $userId);

        $detail = $this->getLobbyById($lobbyId);
        $pool = $this->listTrackPool($userId, $lobbyId);
        $round = $this->getRoundState($userId, $lobbyId);
        $playback = $this->getPlaybackState($lobbyId);
        $scoreboard = $this->getScoreboard($userId, $lobbyId);

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

    public function getPublicLobbiesRealtimeSnapshot(): array
    {
        $this->cleanupStaleOwnerLobbies();
        $data = $this->listPublicLobbies();

        return [
            'revision' => $this->computePublicLobbiesRevision(),
            'items' => $data['items'] ?? [],
            'server_time' => gmdate('c'),
        ];
    }
    public function listPublicLobbies(): array
    {
        $this->cleanupStaleOwnerLobbies();

        $stmt = $this->db->query(
            'SELECT l.id, l.lobby_code, l.name, l.status, l.max_players, l.owner_user_id, u.username AS owner_username,
                    (SELECT COUNT(*) FROM mq_lobby_players lp WHERE lp.lobby_id = l.id) AS players_count
             FROM mq_lobbies l
             JOIN users u ON u.id = l.owner_user_id
             WHERE l.visibility = "public"
               AND l.status IN ("waiting", "playing")
             ORDER BY l.updated_at DESC
             LIMIT 100'
        );

        return ['items' => $stmt->fetchAll()];
    }

    private function touchLobbyMember(int $lobbyId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET last_seen_at = NOW()
             WHERE lobby_id = :lobby_id AND user_id = :user_id'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
        ]);
    }

    private function cleanupStaleOwnerLobbies(): void
    {
        $timeout = max(1, (int)MQ_OWNER_STALE_TIMEOUT_SECONDS);
        $stmt = $this->db->prepare(
            'SELECT l.id
             FROM mq_lobbies l
             JOIN mq_lobby_players lp
               ON lp.lobby_id = l.id
              AND lp.user_id = l.owner_user_id
             WHERE l.status IN ("waiting", "playing", "finished")
               AND lp.last_seen_at < (NOW() - INTERVAL ' . $timeout . ' SECOND)'
        );
        $stmt->execute();
        $lobbyIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        if (empty($lobbyIds)) {
            return;
        }

        $delete = $this->db->prepare('DELETE FROM mq_lobbies WHERE id = :id');
        foreach ($lobbyIds as $lobbyId) {
            $delete->execute(['id' => $lobbyId]);
        }
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
                              WHERE r2.lobby_id = l.id), 0) AS max_answer_id
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
        ]);

        return abs((int)crc32($seed));
    }

    private function computePublicLobbiesRevision(): int
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) AS c,
                    COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS max_updated,
                    COALESCE(SUM(sync_revision), 0) AS sync_sum
             FROM mq_lobbies
             WHERE visibility = "public"
               AND status IN ("waiting", "playing")'
        );
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }

        $seed = implode(':', [
            (int)$row['c'],
            (int)$row['max_updated'],
            (int)$row['sync_sum'],
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

    private function requireOwner(array $lobby, int $userId): void
    {
        if ((int)$lobby['owner_user_id'] !== $userId) {
            throw new RuntimeException('Seul le createur peut effectuer cette action');
        }
    }

    private function requireLobbyMember(int $lobbyId, int $userId): void
    {
        if (!$this->isLobbyMember($lobbyId, $userId)) {
            throw new RuntimeException('Utilisateur non present dans ce lobby');
        }
    }

    private function isLobbyMember(int $lobbyId, int $userId): bool
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
            'SELECT *
             FROM mq_rounds
             WHERE lobby_id = :lobby_id
               AND status IN ("running", "reveal")
             ORDER BY round_number DESC
             LIMIT 1'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function pickTrackForLobby(int $lobbyId): int
    {
        $lobby = $this->requireLobby($lobbyId);
        $selectedCategoryIds = $this->decodeCategoryIds($lobby['selected_category_ids'] ?? null);
        $usedTrackIds = $this->getPlayedTrackIds($lobbyId);

        $trackId = $this->pickEligibleTrack($selectedCategoryIds, $usedTrackIds);
        if ($trackId !== null) {
            return $trackId;
        }

        $trackId = $this->pickEligibleTrack($selectedCategoryIds, []);
        if ($trackId !== null) {
            return $trackId;
        }

        throw new RuntimeException('Aucune musique disponible pour les categories selectionnees');
    }

    private function getPlayedTrackIds(int $lobbyId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT track_id FROM mq_rounds WHERE lobby_id = :lobby_id');
        $stmt->execute(['lobby_id' => $lobbyId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'track_id'));
    }

    private function pickEligibleTrack(array $selectedCategoryIds, array $excludedTrackIds): ?int
    {
        $where = ['t.is_active = 1'];
        $params = [];

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

        $sql = 'SELECT t.id
                FROM mq_tracks t
                JOIN mq_families f ON f.id = t.family_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY RAND()
                LIMIT 1';

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

    private function isGuessCorrect(string $guess, string $expected): bool
    {
        $g = $this->normalize($guess);
        $e = $this->normalize($expected);
        return $g !== '' && $e !== '' && $g === $e;
    }

    private function normalize(string $value): string
    {
        $v = strtolower(trim($value));
        $v = preg_replace('/\s+/', ' ', $v);
        $v = preg_replace('/[^a-z0-9 ]/i', '', $v);
        return trim($v ?? '');
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

        throw new RuntimeException('Impossible de generer un code de lobby unique');
    }
}

