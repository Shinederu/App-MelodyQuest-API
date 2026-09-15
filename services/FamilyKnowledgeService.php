<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/LobbyService.php';
require_once __DIR__ . '/../utils/notoriety.php';

class FamilyKnowledgeService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function forRound(int $actorId, array $payload, bool $save = false): array
    {
        if ($actorId <= 0) {
            throw new RuntimeException('Un compte connecté est nécessaire pour voter.');
        }
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

        if ($save) {
            $stmt = $this->db->prepare(
                "INSERT INTO mq_family_knowledge (family_id, user_id, known)
                 VALUES (:family, :actor, :known)
                 ON DUPLICATE KEY UPDATE id = id"
            );
            // The unique work/account key makes the first choice win, including concurrent requests.
            $stmt->execute(['family' => $familyId, 'actor' => $actorId, 'known' => (int)$payload['known']]);
        }
        $stmt = $this->db->prepare('SELECT known FROM mq_family_knowledge WHERE family_id = :family AND user_id = :actor');
        $stmt->execute(['family' => $familyId, 'actor' => $actorId]);
        $choice = $stmt->fetchColumn();
        $summary = $this->summaries([$familyId])[$familyId] ?? self::summary(0, 0);
        return ['family_id' => $familyId, 'choice' => $choice === false ? null : (bool)$choice,
            'can_vote' => $choice === false] + $summary;
    }

    public static function summary(int $known, int $total, int $seed = MQ_NOTORIETY_DEFAULT): array
    {
        return ['known_count' => $known, 'vote_count' => $total,
            'known_percent' => $total > 0 ? (int)round(100 * $known / $total) : null,
            'notoriety_seed' => $seed, 'notoriety_percent' => mq_notoriety_percent($seed, $known, $total)];
    }

    public function summaries(array $familyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $familyIds), static fn($id) => $id > 0)));
        if (!$ids) return [];
        $stmt = $this->db->prepare('SELECT f.id AS family_id, f.notoriety_seed, COALESCE(SUM(k.known), 0) AS known_count, COUNT(k.id) AS vote_count
            FROM mq_families f LEFT JOIN mq_family_knowledge k ON k.family_id = f.id
            WHERE f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY f.id, f.notoriety_seed');
        $stmt->execute($ids);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['family_id']] = self::summary((int)$row['known_count'], (int)$row['vote_count'], (int)$row['notoriety_seed']);
        }
        return $result;
    }
}
