<?php

require_once __DIR__ . '/../config/config.php';

class TrackSelectionHistoryService
{
    private PDO $db;
    private array $cache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getRecentTrackBucketsForLobby(int $lobbyId): array
    {
        if ($lobbyId <= 0) {
            return $this->emptyBuckets();
        }

        if (isset($this->cache[$lobbyId])) {
            return $this->cache[$lobbyId];
        }

        $lookbackCutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . MQ_TRACK_REPEAT_LOOKBACK_DAYS . ' days')
            ->format('Y-m-d H:i:s.u');
        $strictCutoffUnix = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . MQ_TRACK_REPEAT_STRICT_DAYS . ' days')
            ->getTimestamp();

        try {
            $stmt = $this->db->prepare(
                'SELECT history_round.track_id,
                        MAX(UNIX_TIMESTAMP(game_session.archived_at)) AS last_played_unix
                 FROM mq_game_session_rounds history_round
                 JOIN mq_game_sessions game_session
                   ON game_session.id = history_round.game_session_id
                 JOIN mq_game_session_players history_player
                   ON history_player.game_session_id = game_session.id
                 JOIN mq_lobby_players current_player
                   ON current_player.actor_id = history_player.actor_id
                  AND current_player.lobby_id = :lobby_id
                  AND current_player.presence_status <> "removed"
                 WHERE game_session.archived_at >= :lookback_cutoff
                   AND history_round.track_id > 0
                 GROUP BY history_round.track_id
                 ORDER BY last_played_unix DESC
                 LIMIT ' . MQ_TRACK_REPEAT_HISTORY_LIMIT
            );
            $stmt->execute([
                'lobby_id' => $lobbyId,
                'lookback_cutoff' => $lookbackCutoff,
            ]);
        } catch (PDOException $e) {
            error_log('MelodyQuest recent track history unavailable: ' . $e->getMessage());
            return $this->cache[$lobbyId] = $this->emptyBuckets();
        }

        $lookbackTrackIds = [];
        $strictTrackIds = [];
        foreach ($stmt->fetchAll() as $row) {
            $trackId = (int)($row['track_id'] ?? 0);
            if ($trackId <= 0) {
                continue;
            }

            $lookbackTrackIds[] = $trackId;
            if ((int)($row['last_played_unix'] ?? 0) >= $strictCutoffUnix) {
                $strictTrackIds[] = $trackId;
            }
        }

        return $this->cache[$lobbyId] = [
            'lookback' => array_values(array_unique($lookbackTrackIds)),
            'strict' => array_values(array_unique($strictTrackIds)),
        ];
    }

    private function emptyBuckets(): array
    {
        return [
            'lookback' => [],
            'strict' => [],
        ];
    }
}
