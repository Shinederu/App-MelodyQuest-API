<?php

require_once __DIR__ . '/GameSessionRepository.php';

final class PdoGameSessionRepository implements GameSessionRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function archiveLobbyGame(int $lobbyId, string $completionStatus): ?int
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $source = $this->getArchiveSource($lobbyId);
            if ($source === null) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return null;
            }

            $existingSessionId = $this->findExistingSessionId((int)$source['source_last_round_id']);
            if ($existingSessionId !== null) {
                $this->completeArchivedPlayers($existingSessionId);
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return $existingSessionId;
            }

            $sessionId = $this->insertSession($source, $completionStatus);
            $this->archivePlayers($sessionId, $lobbyId);
            $this->archiveRounds($sessionId, $lobbyId);
            $this->archiveAnswers($sessionId);
            $this->archiveAnswerAttempts($sessionId);
            $this->archiveRevealVotes($sessionId);
            $this->archiveAwayBonuses($sessionId);
            $this->completeArchivedPlayers($sessionId);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $sessionId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function getArchiveSource(int $lobbyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*,
                    COALESCE(NULLIF(owner_player.display_name_snapshot, ""), owner.username, "Invité") AS owner_username,
                    (SELECT COUNT(*) FROM mq_rounds r WHERE r.lobby_id = l.id) AS rounds_count,
                    (SELECT MAX(r.id) FROM mq_rounds r WHERE r.lobby_id = l.id) AS source_last_round_id,
                    (SELECT MIN(r.started_at) FROM mq_rounds r WHERE r.lobby_id = l.id) AS first_round_started_at,
                    (SELECT MAX(COALESCE(r.ended_at, r.reveal_started_at, r.started_at))
                     FROM mq_rounds r
                     WHERE r.lobby_id = l.id) AS last_round_activity_at
             FROM mq_lobbies l
             JOIN mq_lobby_players owner_player
               ON owner_player.lobby_id = l.id
              AND owner_player.actor_id = l.owner_actor_id
             LEFT JOIN users owner ON owner.id = owner_player.user_id
             WHERE l.id = :lobby_id
             LIMIT 1'
        );
        $stmt->execute(['lobby_id' => $lobbyId]);
        $row = $stmt->fetch();

        return $row && (int)($row['rounds_count'] ?? 0) > 0 ? $row : null;
    }

    private function findExistingSessionId(int $sourceLastRoundId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM mq_game_sessions
             WHERE source_last_round_id = :source_last_round_id
             LIMIT 1'
        );
        $stmt->execute(['source_last_round_id' => $sourceLastRoundId]);
        $sessionId = (int)($stmt->fetchColumn() ?: 0);

        return $sessionId > 0 ? $sessionId : null;
    }

    private function insertSession(array $source, string $completionStatus): int
    {
        $configSnapshot = [
            'visibility' => (string)($source['visibility'] ?? 'private'),
            'game_mode' => (string)($source['game_mode'] ?? 'participative'),
            'max_players' => (int)($source['max_players'] ?? 0),
            'total_rounds' => (int)($source['total_rounds'] ?? 0),
            'round_duration_seconds' => (int)($source['round_duration_seconds'] ?? 0),
            'reveal_duration_seconds' => (int)($source['reveal_duration_seconds'] ?? 0),
            'guess_mode' => (string)($source['guess_mode'] ?? 'both'),
            'selected_category_ids' => $this->decodeCategoryIds($source['selected_category_ids'] ?? '[]'),
            'show_track_category' => !empty($source['show_track_category']),
            'allow_early_reveal_vote' => !empty($source['allow_early_reveal_vote']),
            'answer_similarity_threshold' => (int)($source['answer_similarity_threshold'] ?? 100),
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_sessions
                (source_lobby_id, source_last_round_id, lobby_code, lobby_name, owner_user_id, owner_actor_id, owner_is_guest,
                 owner_username_snapshot, game_mode, completion_status, config_snapshot,
                 started_at, finished_at, archived_at)
             VALUES
                (:source_lobby_id, :source_last_round_id, :lobby_code, :lobby_name, :owner_user_id, :owner_actor_id, :owner_is_guest,
                 :owner_username_snapshot, :game_mode, :completion_status, :config_snapshot,
                 :started_at, :finished_at, NOW(3))'
        );
        $stmt->execute([
            'source_lobby_id' => (int)$source['id'],
            'source_last_round_id' => (int)$source['source_last_round_id'],
            'lobby_code' => strtoupper(trim((string)$source['lobby_code'])),
            'lobby_name' => trim((string)$source['name']),
            'owner_user_id' => !empty($source['owner_user_id']) ? (int)$source['owner_user_id'] : null,
            'owner_actor_id' => (int)$source['owner_actor_id'],
            'owner_is_guest' => (int)$source['owner_actor_id'] < 0 ? 1 : 0,
            'owner_username_snapshot' => (string)$source['owner_username'],
            'game_mode' => (string)($source['game_mode'] ?? 'participative'),
            'completion_status' => $completionStatus,
            'config_snapshot' => json_encode(
                $configSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'started_at' => $source['first_round_started_at'],
            'finished_at' => $source['last_round_activity_at'],
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function decodeCategoryIds(mixed $value): array
    {
        $categoryIds = is_array($value)
            ? $value
            : json_decode((string)$value, true);

        if (!is_array($categoryIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            fn (int $categoryId): bool => $categoryId > 0
        )));
    }

    private function archivePlayers(int $sessionId, int $lobbyId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_session_players
                (game_session_id, user_id, actor_id, is_guest, username_snapshot, lobby_role, final_score,
                 presence_status, joined_at, removed_at)
             SELECT :session_id, lp.user_id, lp.actor_id, IF(lp.actor_id < 0, 1, 0),
                    COALESCE(NULLIF(lp.display_name_snapshot, ""), u.username, "Invité"), lp.role, lp.score,
                    lp.presence_status, lp.joined_at, lp.removed_at
             FROM mq_lobby_players lp
             LEFT JOIN users u ON u.id = lp.user_id
             WHERE lp.lobby_id = :lobby_id'
        );
        $stmt->execute(['session_id' => $sessionId, 'lobby_id' => $lobbyId]);
    }

    private function archiveRounds(int $sessionId, int $lobbyId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_session_rounds
                (game_session_id, source_round_id, round_number, track_id, category_name_snapshot,
                 family_name_snapshot, track_title_snapshot, track_artist_snapshot,
                 youtube_video_id_snapshot, started_at, reveal_started_at, ended_at, round_status)
             SELECT :session_id, r.id, r.round_number, r.track_id, c.name,
                    f.name, t.title, t.artist, t.youtube_video_id,
                    r.started_at, r.reveal_started_at, r.ended_at, r.status
             FROM mq_rounds r
             JOIN mq_tracks t ON t.id = r.track_id
             JOIN mq_families f ON f.id = t.family_id
             JOIN mq_categories c ON c.id = f.category_id
             WHERE r.lobby_id = :lobby_id
             ORDER BY r.round_number ASC'
        );
        $stmt->execute(['session_id' => $sessionId, 'lobby_id' => $lobbyId]);
    }

    private function archiveAnswers(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_session_answers
                (game_session_round_id, source_answer_id, user_id, actor_id, username_snapshot,
                 guess_title, guess_artist, is_correct_title, is_correct_artist,
                 score_awarded, answered_at)
             SELECT history_round.id, answer.id, answer.user_id, answer.actor_id,
                    COALESCE(NULLIF(lp.display_name_snapshot, ""), u.username, "Invité"),
                    answer.guess_title, answer.guess_artist, answer.is_correct_title, answer.is_correct_artist,
                    answer.score_awarded, answer.answered_at
             FROM mq_game_session_rounds history_round
             JOIN mq_round_answers answer ON answer.round_id = history_round.source_round_id
             JOIN mq_rounds live_round ON live_round.id = answer.round_id
             LEFT JOIN mq_lobby_players lp
               ON lp.lobby_id = live_round.lobby_id
              AND lp.actor_id = answer.actor_id
             LEFT JOIN users u ON u.id = answer.user_id
             WHERE history_round.game_session_id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);
    }

    private function archiveAnswerAttempts(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_session_answer_attempts
                (game_session_round_id, source_attempt_id, user_id, actor_id, username_snapshot,
                 guess_title, guess_artist, is_correct, score_awarded, attempted_at)
             SELECT history_round.id, attempt.id, attempt.user_id, attempt.actor_id,
                    COALESCE(NULLIF(lp.display_name_snapshot, ""), u.username, "Invité"),
                    attempt.guess_title, attempt.guess_artist, attempt.is_correct,
                    attempt.score_awarded, attempt.created_at
             FROM mq_game_session_rounds history_round
             JOIN mq_round_answer_attempts attempt ON attempt.round_id = history_round.source_round_id
             JOIN mq_rounds live_round ON live_round.id = attempt.round_id
             LEFT JOIN mq_lobby_players lp
               ON lp.lobby_id = live_round.lobby_id
              AND lp.actor_id = attempt.actor_id
             LEFT JOIN users u ON u.id = attempt.user_id
             WHERE history_round.game_session_id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);
    }

    private function archiveRevealVotes(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_session_reveal_votes
                (game_session_round_id, user_id, actor_id, username_snapshot, voted_at)
             SELECT history_round.id, vote.user_id, vote.actor_id,
                    COALESCE(NULLIF(lp.display_name_snapshot, ""), u.username, "Invité"), vote.voted_at
             FROM mq_game_session_rounds history_round
             JOIN mq_round_reveal_votes vote ON vote.round_id = history_round.source_round_id
             JOIN mq_rounds live_round ON live_round.id = vote.round_id
             LEFT JOIN mq_lobby_players lp
               ON lp.lobby_id = live_round.lobby_id
              AND lp.actor_id = vote.actor_id
             LEFT JOIN users u ON u.id = vote.user_id
             WHERE history_round.game_session_id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);
    }

    private function archiveAwayBonuses(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mq_game_session_away_bonuses
                (game_session_round_id, user_id, actor_id, username_snapshot, source_user_id, source_actor_id,
                 source_username_snapshot, score_awarded, awarded_at)
             SELECT history_round.id, bonus.user_id, bonus.actor_id,
                    COALESCE(NULLIF(target_player.display_name_snapshot, ""), target.username, "Invité"),
                    bonus.source_user_id, bonus.source_actor_id,
                    COALESCE(NULLIF(source_player.display_name_snapshot, ""), source_user.username, "Invité"),
                    bonus.score_awarded, bonus.awarded_at
             FROM mq_game_session_rounds history_round
             JOIN mq_round_away_bonuses bonus ON bonus.round_id = history_round.source_round_id
             JOIN mq_rounds live_round ON live_round.id = bonus.round_id
             LEFT JOIN mq_lobby_players target_player
               ON target_player.lobby_id = live_round.lobby_id
              AND target_player.actor_id = bonus.actor_id
             LEFT JOIN mq_lobby_players source_player
               ON source_player.lobby_id = live_round.lobby_id
              AND source_player.actor_id = bonus.source_actor_id
             LEFT JOIN users target ON target.id = bonus.user_id
             LEFT JOIN users source_user ON source_user.id = bonus.source_user_id
             WHERE history_round.game_session_id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);
    }

    private function completeArchivedPlayers(int $sessionId): void
    {
        $participants = $this->db->prepare(
            'INSERT IGNORE INTO mq_game_session_players
                (game_session_id, user_id, actor_id, is_guest, username_snapshot, lobby_role, final_score,
                 presence_status, joined_at, removed_at)
             SELECT participant.game_session_id,
                    participant.user_id,
                    participant.actor_id,
                    IF(participant.actor_id < 0, 1, 0),
                    participant.username_snapshot,
                    CASE
                        WHEN participant.actor_id = game_session.owner_actor_id THEN "owner"
                        ELSE "player"
                    END,
                    participant.final_score,
                    "unknown",
                    participant.first_activity_at,
                    NULL
             FROM mq_game_sessions game_session
             JOIN (
                 SELECT activity.game_session_id,
                        activity.user_id,
                        activity.actor_id,
                        MAX(activity.username_snapshot) AS username_snapshot,
                        SUM(activity.score_awarded) AS final_score,
                        MIN(activity.activity_at) AS first_activity_at
                 FROM (
                     SELECT history_round.game_session_id,
                            answer.user_id,
                            answer.actor_id,
                            answer.username_snapshot,
                            answer.score_awarded,
                            answer.answered_at AS activity_at
                     FROM mq_game_session_answers answer
                     JOIN mq_game_session_rounds history_round
                       ON history_round.id = answer.game_session_round_id
                     WHERE history_round.game_session_id = :session_answers

                     UNION ALL

                     SELECT history_round.game_session_id,
                            attempt.user_id,
                            attempt.actor_id,
                            attempt.username_snapshot,
                            0 AS score_awarded,
                            attempt.attempted_at AS activity_at
                     FROM mq_game_session_answer_attempts attempt
                     JOIN mq_game_session_rounds history_round
                       ON history_round.id = attempt.game_session_round_id
                     WHERE history_round.game_session_id = :session_attempts

                     UNION ALL

                     SELECT history_round.game_session_id,
                            vote.user_id,
                            vote.actor_id,
                            vote.username_snapshot,
                            0 AS score_awarded,
                            vote.voted_at AS activity_at
                     FROM mq_game_session_reveal_votes vote
                     JOIN mq_game_session_rounds history_round
                       ON history_round.id = vote.game_session_round_id
                     WHERE history_round.game_session_id = :session_votes

                     UNION ALL

                     SELECT history_round.game_session_id,
                            bonus.user_id,
                            bonus.actor_id,
                            bonus.username_snapshot,
                            bonus.score_awarded,
                            bonus.awarded_at AS activity_at
                     FROM mq_game_session_away_bonuses bonus
                     JOIN mq_game_session_rounds history_round
                       ON history_round.id = bonus.game_session_round_id
                     WHERE history_round.game_session_id = :session_bonus_target

                     UNION ALL

                     SELECT history_round.game_session_id,
                            bonus.source_user_id AS user_id,
                            bonus.source_actor_id AS actor_id,
                            bonus.source_username_snapshot AS username_snapshot,
                            0 AS score_awarded,
                            bonus.awarded_at AS activity_at
                     FROM mq_game_session_away_bonuses bonus
                     JOIN mq_game_session_rounds history_round
                       ON history_round.id = bonus.game_session_round_id
                     WHERE history_round.game_session_id = :session_bonus_source
                 ) activity
                 GROUP BY activity.game_session_id, activity.actor_id, activity.user_id
             ) participant ON participant.game_session_id = game_session.id
             WHERE game_session.id = :session_id'
        );
        $participants->execute([
            'session_answers' => $sessionId,
            'session_attempts' => $sessionId,
            'session_votes' => $sessionId,
            'session_bonus_target' => $sessionId,
            'session_bonus_source' => $sessionId,
            'session_id' => $sessionId,
        ]);

        $owner = $this->db->prepare(
            'INSERT IGNORE INTO mq_game_session_players
                (game_session_id, user_id, actor_id, is_guest, username_snapshot, lobby_role, final_score,
                 presence_status, joined_at, removed_at)
             SELECT id, owner_user_id, owner_actor_id, owner_is_guest, owner_username_snapshot, "owner", 0,
                    "unknown", started_at, NULL
             FROM mq_game_sessions
             WHERE id = :session_id'
        );
        $owner->execute(['session_id' => $sessionId]);
    }
}
