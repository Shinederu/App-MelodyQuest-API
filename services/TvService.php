<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/LobbyService.php';

class TvService
{
    private const PAIRING_TTL_MINUTES = 10;
    private const LINKED_TTL_HOURS = 12;
    private PDO $db;
    private LobbyService $lobbyService;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
        $this->lobbyService = new LobbyService();
    }

    public function createPairing(): array
    {
        $this->cleanupExpiredPairings();

        $code = $this->generatePairingCode();
        $token = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            'INSERT INTO mq_tv_pairings (pairing_code, device_token, status, expires_at, last_seen_at)
             VALUES (:code, :token, "pending", DATE_ADD(NOW(3), INTERVAL ' . self::PAIRING_TTL_MINUTES . ' MINUTE), NOW(3))'
        );
        $stmt->execute([
            'code' => $code,
            'token' => $token,
        ]);

        return $this->formatPairing($this->requirePairingByToken($token));
    }

    public function getPairing(string $deviceToken): array
    {
        $this->cleanupExpiredPairings();
        $pairing = $this->requirePairingByToken($deviceToken);
        $this->touchPairing($deviceToken, (string)$pairing['status'] === 'linked');

        return $this->formatPairing($this->requirePairingByToken($deviceToken));
    }

    public function linkPairing(int $userId, string $pairingCode, int $lobbyId): array
    {
        $this->cleanupExpiredPairings();
        if ($lobbyId <= 0) {
            throw new RuntimeException('Salon requis');
        }

        $pairingCode = strtoupper(trim($pairingCode));
        if (!preg_match('/^[A-Z0-9]{6}$/', $pairingCode)) {
            throw new RuntimeException('Code TV invalide');
        }

        if (!$this->isLobbyMember($lobbyId, $userId)) {
            throw new RuntimeException('Tu dois être dans le salon pour lier une TV');
        }

        $this->requireLinkableLobby($lobbyId);
        $pairing = $this->requirePendingPairingByCode($pairingCode);
        $stmt = $this->db->prepare(
            'UPDATE mq_tv_pairings
             SET status = "linked",
                 lobby_id = :lobby_id,
                 linked_by_user_id = :user_id,
                 linked_at = NOW(3),
                 last_seen_at = NOW(3),
                 expires_at = DATE_ADD(NOW(3), INTERVAL ' . self::LINKED_TTL_HOURS . ' HOUR)
             WHERE id = :id'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
            'id' => (int)$pairing['id'],
        ]);

        return $this->formatPairing($this->requirePairingByToken((string)$pairing['device_token']));
    }

    public function getState(string $deviceToken): array
    {
        $this->cleanupExpiredPairings();
        $pairing = $this->requirePairingByToken($deviceToken);
        if ((string)$pairing['status'] !== 'linked' || empty($pairing['lobby_id'])) {
            throw new RuntimeException('TV non liée à un salon');
        }

        $this->touchPairing($deviceToken, true);
        $lobbyId = (int)$pairing['lobby_id'];
        $this->lobbyService->prepareTvPreloads($lobbyId);
        $snapshot = $this->lobbyService->buildLobbyRealtimeSnapshot($lobbyId, [
            'preload_limit' => MQ_TV_PRELOAD_LOOKAHEAD,
            'include_waiting_preloads' => true,
        ]);

        return [
            'pairing' => $this->formatPairing($this->requirePairingByToken($deviceToken)),
            'snapshot' => $snapshot,
        ];
    }

    private function formatPairing(array $pairing): array
    {
        $result = [
            'pairing_code' => (string)$pairing['pairing_code'],
            'device_token' => (string)$pairing['device_token'],
            'status' => (string)$pairing['status'],
            'expires_at' => $pairing['expires_at'],
            'linked_at' => $pairing['linked_at'] ?? null,
        ];

        if (!empty($pairing['lobby_id'])) {
            $result['lobby_id'] = (int)$pairing['lobby_id'];
            $result['lobby_code'] = $this->lobbyService->getLobbyCodeById((int)$pairing['lobby_id']);
        }

        return $result;
    }

    private function requirePairingByToken(string $deviceToken): array
    {
        $deviceToken = strtolower(trim($deviceToken));
        if (!preg_match('/^[a-f0-9]{64}$/', $deviceToken)) {
            throw new RuntimeException('Token TV invalide');
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM mq_tv_pairings
             WHERE device_token = :token
               AND status IN ("pending", "linked")
               AND expires_at > NOW(3)
             LIMIT 1'
        );
        $stmt->execute(['token' => $deviceToken]);
        $pairing = $stmt->fetch();
        if (!$pairing) {
            throw new RuntimeException('Session TV expirée');
        }

        return $pairing;
    }

    private function requirePendingPairingByCode(string $pairingCode): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM mq_tv_pairings
             WHERE pairing_code = :code
               AND status = "pending"
               AND expires_at > NOW(3)
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['code' => $pairingCode]);
        $pairing = $stmt->fetch();
        if (!$pairing) {
            throw new RuntimeException('Code TV introuvable ou expiré');
        }

        return $pairing;
    }

    private function isLobbyMember(int $lobbyId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM mq_lobby_players
             WHERE lobby_id = :lobby_id
               AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'lobby_id' => $lobbyId,
            'user_id' => $userId,
        ]);

        return (bool)$stmt->fetch();
    }

    private function requireLinkableLobby(int $lobbyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, lobby_code, name, status
             FROM mq_lobbies
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $lobbyId]);
        $lobby = $stmt->fetch();
        if (!$lobby) {
            throw new RuntimeException('Salon introuvable');
        }

        if (!in_array(strtolower((string)$lobby['status']), ['waiting', 'playing'], true)) {
            throw new RuntimeException('Ce salon ne peut plus lier de TV');
        }

        return $lobby;
    }

    private function touchPairing(string $deviceToken, bool $extend): void
    {
        $sql = 'UPDATE mq_tv_pairings SET last_seen_at = NOW(3)'
            . ($extend ? ', expires_at = DATE_ADD(NOW(3), INTERVAL ' . self::LINKED_TTL_HOURS . ' HOUR)' : '')
            . ' WHERE device_token = :token';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['token' => strtolower(trim($deviceToken))]);
    }

    private function cleanupExpiredPairings(): void
    {
        $this->db->exec(
            'UPDATE mq_tv_pairings
             SET status = "expired"
             WHERE status IN ("pending", "linked")
               AND expires_at <= NOW(3)'
        );
    }

    private function generatePairingCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $stmt = $this->db->prepare(
                'SELECT 1
                 FROM mq_tv_pairings
                 WHERE pairing_code = :code
                   AND status = "pending"
                   AND expires_at > NOW(3)
                 LIMIT 1'
            );
            $stmt->execute(['code' => $code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }

        throw new RuntimeException('Impossible de générer un code TV');
    }
}
