<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/../config/config.php';

final class PlayerSessionService
{
    private const RESERVED_NICKNAMES = [
        'admin',
        'administrateur',
        'moderateur',
        'modérateur',
        'melodyquest',
        'shinederu',
        'support',
        'systeme',
        'système',
    ];

    private const NICKNAME_PREFIXES = [
        'Dark', 'Super', 'Pixel', 'Cosmic', 'Neon', 'Lunaire',
        'Turbo', 'Mystic', 'Nova', 'Retro', 'Sonic', 'Zen',
    ];

    private const NICKNAME_WORDS = [
        'Armoire', 'Casque', 'Clavier', 'Comete', 'Disque', 'Etoile',
        'Manette', 'Melodie', 'Nuage', 'Pixel', 'Tempo', 'Vinyle',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseService::getInstance();
    }

    public function resolveCurrentGuest(bool $touch = true): ?array
    {
        $this->cleanupExpiredSessions();

        $token = $this->readCookieToken();
        if ($token === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, nickname, created_at, last_seen_at, expires_at
             FROM mq_guest_sessions
             WHERE token_hash = :token_hash
               AND expires_at > NOW(3)
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $session = $stmt->fetch();
        if (!$session) {
            $this->clearCookie();
            return null;
        }

        if ($touch && $this->shouldTouch($session['last_seen_at'] ?? null)) {
            $this->touchSession((int)$session['id']);
            $session['last_seen_at'] = date('Y-m-d H:i:s.u');
            $session['expires_at'] = date('Y-m-d H:i:s.u', time() + MQ_GUEST_SESSION_TTL_SECONDS);
            $this->writeCookie($token);
        }

        return $this->formatIdentity($session);
    }

    public function createGuest(?string $requestedNickname = null): array
    {
        $nickname = $requestedNickname !== null && trim($requestedNickname) !== ''
            ? $this->validateNickname($requestedNickname)
            : $this->generateNickname();

        $token = $this->generateToken();
        $stmt = $this->db->prepare(
            'INSERT INTO mq_guest_sessions
                (token_hash, nickname, created_at, last_seen_at, expires_at)
             VALUES
                (:token_hash, :nickname, NOW(3), NOW(3), DATE_ADD(NOW(3), INTERVAL :ttl SECOND))'
        );
        $stmt->bindValue(':token_hash', hash('sha256', $token));
        $stmt->bindValue(':nickname', $nickname);
        $stmt->bindValue(':ttl', MQ_GUEST_SESSION_TTL_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        $sessionId = (int)$this->db->lastInsertId();
        $this->writeCookie($token);

        return [
            'actor_id' => -$sessionId,
            'user_id' => null,
            'guest_session_id' => $sessionId,
            'username' => $nickname,
            'avatar_url' => '',
            'role' => 'guest',
            'is_guest' => true,
            'is_authenticated' => false,
            'expires_in_seconds' => MQ_GUEST_SESSION_TTL_SECONDS,
        ];
    }

    public function renameCurrentGuest(string $nickname): array
    {
        $identity = $this->resolveCurrentGuest(false);
        if ($identity === null) {
            return $this->createGuest($nickname);
        }

        $nickname = $this->validateNickname($nickname, (int)$identity['guest_session_id']);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE mq_guest_sessions
                 SET nickname = :nickname,
                     last_seen_at = NOW(3),
                     expires_at = DATE_ADD(NOW(3), INTERVAL :ttl SECOND)
                 WHERE id = :id'
            );
            $stmt->bindValue(':nickname', $nickname);
            $stmt->bindValue(':ttl', MQ_GUEST_SESSION_TTL_SECONDS, PDO::PARAM_INT);
            $stmt->bindValue(':id', (int)$identity['guest_session_id'], PDO::PARAM_INT);
            $stmt->execute();

            $this->db->prepare(
                'UPDATE mq_lobby_players
                 SET display_name_snapshot = :nickname
                 WHERE guest_session_id = :guest_session_id'
            )->execute([
                'nickname' => $nickname,
                'guest_session_id' => (int)$identity['guest_session_id'],
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $identity['username'] = $nickname;
        $identity['expires_in_seconds'] = MQ_GUEST_SESSION_TTL_SECONDS;
        $token = $this->readCookieToken();
        if ($token !== null) {
            $this->writeCookie($token);
        }

        return $identity;
    }

    public function endCurrentGuest(): void
    {
        $token = $this->readCookieToken();
        if ($token === null) {
            $this->clearCookie();
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id
             FROM mq_guest_sessions
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $sessionId = (int)($stmt->fetchColumn() ?: 0);
        if ($sessionId > 0) {
            $this->removeGuestFromActiveLobbies($sessionId);
            $this->db->prepare('DELETE FROM mq_guest_sessions WHERE id = :id')->execute(['id' => $sessionId]);
        }

        $this->clearCookie();
    }

    public function getIdentityByActorId(int $actorId): array
    {
        if ($actorId === 0) {
            throw new RuntimeException('Identité joueur invalide');
        }

        if ($actorId > 0) {
            $stmt = $this->db->prepare(
                'SELECT id, username, avatar_url, role
                 FROM users
                 WHERE id = :id
                   AND COALESCE(is_banned, 0) = 0
                 LIMIT 1'
            );
            $stmt->execute(['id' => $actorId]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('Compte joueur introuvable');
            }

            return [
                'actor_id' => $actorId,
                'user_id' => $actorId,
                'guest_session_id' => null,
                'username' => (string)$user['username'],
                'avatar_url' => (string)($user['avatar_url'] ?? ''),
                'role' => (string)($user['role'] ?? 'user'),
                'is_guest' => false,
                'is_authenticated' => true,
            ];
        }

        $guestId = abs($actorId);
        $stmt = $this->db->prepare(
            'SELECT id, nickname, created_at, last_seen_at, expires_at
             FROM mq_guest_sessions
             WHERE id = :id
               AND expires_at > NOW(3)
             LIMIT 1'
        );
        $stmt->execute(['id' => $guestId]);
        $guest = $stmt->fetch();
        if (!$guest) {
            throw new RuntimeException('Session invitée expirée');
        }

        return $this->formatIdentity($guest);
    }

    private function validateNickname(string $nickname, int $excludeGuestId = 0): string
    {
        $nickname = preg_replace('/\s+/u', '_', trim($nickname)) ?? '';
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_-]{2,31}$/u', $nickname)) {
            throw new RuntimeException('Le pseudo doit contenir 3 à 32 lettres, chiffres, tirets ou underscores');
        }

        $normalized = mb_strtolower($nickname, 'UTF-8');
        if (in_array($normalized, self::RESERVED_NICKNAMES, true)) {
            throw new RuntimeException('Ce pseudo est réservé');
        }

        $userStmt = $this->db->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(:nickname) LIMIT 1');
        $userStmt->execute(['nickname' => $nickname]);
        if ($userStmt->fetchColumn()) {
            throw new RuntimeException('Ce pseudo appartient déjà à un compte');
        }

        $guestStmt = $this->db->prepare(
            'SELECT 1
             FROM mq_guest_sessions
             WHERE LOWER(nickname) = LOWER(:nickname)
               AND expires_at > NOW(3)
               AND (:exclude_id = 0 OR id <> :exclude_id_match)
             LIMIT 1'
        );
        $guestStmt->execute([
            'nickname' => $nickname,
            'exclude_id' => $excludeGuestId,
            'exclude_id_match' => $excludeGuestId,
        ]);
        if ($guestStmt->fetchColumn()) {
            throw new RuntimeException('Ce pseudo est déjà utilisé par un invité actif');
        }

        return $nickname;
    }

    private function generateNickname(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $nickname = self::NICKNAME_PREFIXES[array_rand(self::NICKNAME_PREFIXES)]
                . '_'
                . self::NICKNAME_WORDS[array_rand(self::NICKNAME_WORDS)];
            if ($attempt >= 12) {
                $nickname .= '_' . random_int(10, 99);
            }

            try {
                return $this->validateNickname($nickname);
            } catch (RuntimeException) {
                // Try another friendly combination.
            }
        }

        return 'Invite_' . random_int(100000, 999999);
    }

    private function formatIdentity(array $session): array
    {
        $sessionId = (int)$session['id'];
        return [
            'actor_id' => -$sessionId,
            'user_id' => null,
            'guest_session_id' => $sessionId,
            'username' => (string)$session['nickname'],
            'avatar_url' => '',
            'role' => 'guest',
            'is_guest' => true,
            'is_authenticated' => false,
            'expires_in_seconds' => max(0, strtotime((string)$session['expires_at']) - time()),
        ];
    }

    private function cleanupExpiredSessions(): void
    {
        if (!$this->shouldRunExpiredCleanup()) {
            return;
        }

        $this->db->exec(
            'UPDATE mq_lobby_players lp
             JOIN mq_guest_sessions guest ON guest.id = lp.guest_session_id
             SET lp.presence_status = "removed",
                 lp.is_ready = 0,
                 lp.removed_at = COALESCE(lp.removed_at, NOW(3))
             WHERE guest.expires_at <= NOW(3)
               AND lp.presence_status <> "removed"'
        );
        $this->db->exec('DELETE FROM mq_guest_sessions WHERE expires_at <= NOW(3)');
    }

    private function shouldRunExpiredCleanup(): bool
    {
        $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'melodyquest-guest-cleanup.timestamp';
        $lastRun = is_file($file) ? (int)(filemtime($file) ?: 0) : 0;
        if ($lastRun > time() - 60) {
            return false;
        }

        @touch($file);
        return true;
    }

    private function removeGuestFromActiveLobbies(int $guestSessionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_lobby_players
             SET presence_status = "removed",
                 is_ready = 0,
                 removed_at = COALESCE(removed_at, NOW(3))
             WHERE guest_session_id = :guest_session_id
               AND presence_status <> "removed"'
        );
        $stmt->execute(['guest_session_id' => $guestSessionId]);
    }

    private function shouldTouch(mixed $lastSeenAt): bool
    {
        $lastSeen = strtotime((string)$lastSeenAt);
        return $lastSeen === false || $lastSeen <= time() - MQ_GUEST_SESSION_TOUCH_INTERVAL_SECONDS;
    }

    private function touchSession(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_guest_sessions
             SET last_seen_at = NOW(3),
                 expires_at = DATE_ADD(NOW(3), INTERVAL :ttl SECOND)
             WHERE id = :id'
        );
        $stmt->bindValue(':ttl', MQ_GUEST_SESSION_TTL_SECONDS, PDO::PARAM_INT);
        $stmt->bindValue(':id', $sessionId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function readCookieToken(): ?string
    {
        $token = trim((string)($_COOKIE[MQ_GUEST_COOKIE_NAME] ?? ''));
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) ? $token : null;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function writeCookie(string $token): void
    {
        setcookie(MQ_GUEST_COOKIE_NAME, $token, [
            'expires' => time() + MQ_GUEST_SESSION_TTL_SECONDS,
            'path' => '/melodyquest/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[MQ_GUEST_COOKIE_NAME] = $token;
    }

    private function clearCookie(): void
    {
        setcookie(MQ_GUEST_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/melodyquest/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[MQ_GUEST_COOKIE_NAME]);
    }
}
