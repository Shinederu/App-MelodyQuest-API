<?php

require_once __DIR__ . '/RealtimeOutboxRepository.php';

class PdoRealtimeOutboxRepository implements RealtimeOutboxRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function enqueue(
        string $streamKey,
        string $eventKind,
        ?int $lobbyId,
        ?string $lobbyCode,
        ?array $payload
    ): void {
        $encodedPayload = $payload === null
            ? null
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare(
            'INSERT INTO mq_realtime_outbox (
                stream_key,
                event_kind,
                lobby_id,
                lobby_code,
                payload
             ) VALUES (
                :stream_key,
                :event_kind,
                :lobby_id,
                :lobby_code,
                :payload
             )
             ON DUPLICATE KEY UPDATE
                event_kind = VALUES(event_kind),
                lobby_id = VALUES(lobby_id),
                lobby_code = VALUES(lobby_code),
                payload = VALUES(payload),
                generation = generation + 1,
                attempts = 0,
                requested_at = CURRENT_TIMESTAMP(6),
                available_at = CURRENT_TIMESTAMP(6),
                last_error = NULL'
        );
        $stmt->bindValue(':stream_key', $streamKey);
        $stmt->bindValue(':event_kind', $eventKind);
        $stmt->bindValue(':lobby_id', $lobbyId, $lobbyId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':lobby_code', $lobbyCode, $lobbyCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':payload', $encodedPayload, $encodedPayload === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }

    public function claimNext(int $lockTimeoutSeconds): ?array
    {
        $lockTimeoutSeconds = max(5, min(300, $lockTimeoutSeconds));
        $this->db->exec(
            'UPDATE mq_realtime_outbox
             SET locked_at = NULL
             WHERE locked_at IS NOT NULL
               AND locked_at < DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL ' . $lockTimeoutSeconds . ' SECOND)'
        );

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->query(
                'SELECT *
                 FROM mq_realtime_outbox
                 WHERE available_at <= CURRENT_TIMESTAMP(6)
                   AND locked_at IS NULL
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED'
            );
            $row = $stmt->fetch();
            if (!$row) {
                $this->db->commit();
                return null;
            }

            $claim = $this->db->prepare(
                'UPDATE mq_realtime_outbox
                 SET locked_at = CURRENT_TIMESTAMP(6), attempts = attempts + 1
                 WHERE id = :id AND generation = :generation'
            );
            $claim->execute([
                'id' => (int)$row['id'],
                'generation' => (int)$row['generation'],
            ]);
            $this->db->commit();

            $row['attempts'] = (int)$row['attempts'] + 1;
            return $row;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function acknowledge(int $id, int $generation): void
    {
        $delete = $this->db->prepare(
            'DELETE FROM mq_realtime_outbox
             WHERE id = :id AND generation = :generation'
        );
        $delete->execute(['id' => $id, 'generation' => $generation]);

        if ($delete->rowCount() === 0) {
            $release = $this->db->prepare(
                'UPDATE mq_realtime_outbox
                 SET locked_at = NULL, available_at = CURRENT_TIMESTAMP(6), last_error = NULL
                 WHERE id = :id'
            );
            $release->execute(['id' => $id]);
        }
    }

    public function retry(int $id, int $generation, int $delayMilliseconds, string $error): void
    {
        $delayMicroseconds = max(100, min(30000, $delayMilliseconds)) * 1000;
        $error = trim((string)preg_replace('/\s+/u', ' ', $error));
        $error = substr($error, 0, 1000);

        $retry = $this->db->prepare(
            'UPDATE mq_realtime_outbox
             SET locked_at = NULL,
                 available_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ' . $delayMicroseconds . ' MICROSECOND),
                 last_error = :last_error
             WHERE id = :id AND generation = :generation'
        );
        $retry->execute([
            'id' => $id,
            'generation' => $generation,
            'last_error' => $error,
        ]);

        if ($retry->rowCount() === 0) {
            $release = $this->db->prepare(
                'UPDATE mq_realtime_outbox
                 SET locked_at = NULL, available_at = CURRENT_TIMESTAMP(6), last_error = NULL
                 WHERE id = :id'
            );
            $release->execute(['id' => $id]);
        }
    }

    public function countPending(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM mq_realtime_outbox')->fetchColumn();
    }
}
