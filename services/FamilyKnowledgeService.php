<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/LobbyService.php';

class FamilyKnowledgeService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function forRound(int $actorId, array $payload, bool $save = false): array
    {
        if ($save && !is_bool($payload['known'] ?? null)) {
            throw new RuntimeException('Choisis Oui ou Non.');
        }
        // Reuse the authoritative solution visibility and membership rules.
        $state = (new LobbyService())->getRoundState($actorId, (int)($payload['lobby_id'] ?? 0));
        $round = $state['round'] ?? null;
        $familyId = (int)($round['track']['family_id'] ?? 0);
        if (!$round || (int)$round['id'] !== (int)($payload['round_id'] ?? 0)
            || $familyId <= 0 || !empty($round['unavailable_skip_at_unix'])) {
            throw new RuntimeException('Le vote est disponible quand la réponse est révélée.');
        }

        $column = $actorId > 0 ? 'user_id' : 'guest_session_id';
        if ($save) {
            $stmt = $this->db->prepare(
                "INSERT INTO mq_family_knowledge (family_id, $column, known)
                 VALUES (:family, :actor, :known)
                 ON DUPLICATE KEY UPDATE known = :choice"
            );
            $stmt->execute(['family' => $familyId, 'actor' => abs($actorId),
                'known' => (int)$payload['known'], 'choice' => (int)$payload['known']]);
        }
        $stmt = $this->db->prepare("SELECT known FROM mq_family_knowledge WHERE family_id = :family AND $column = :actor");
        $stmt->execute(['family' => $familyId, 'actor' => abs($actorId)]);
        $choice = $stmt->fetchColumn();
        $summary = $this->summaries([$familyId])[$familyId] ?? self::summary(0, 0);
        return ['family_id' => $familyId, 'choice' => $choice === false ? null : (bool)$choice] + $summary;
    }

    public static function summary(int $known, int $total): array
    {
        return ['known_count' => $known, 'vote_count' => $total,
            'known_percent' => $total > 0 ? (int)round(100 * $known / $total) : null];
    }

    public function summaries(array $familyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $familyIds), static fn($id) => $id > 0)));
        if (!$ids) return [];
        $stmt = $this->db->prepare('SELECT family_id, SUM(known) AS known_count, COUNT(*) AS vote_count
            FROM mq_family_knowledge WHERE family_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY family_id');
        $stmt->execute($ids);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['family_id']] = self::summary((int)$row['known_count'], (int)$row['vote_count']);
        }
        return $result;
    }
}
