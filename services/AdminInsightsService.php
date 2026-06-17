<?php

require_once __DIR__ . '/DatabaseService.php';

class AdminInsightsService
{
    private const MAX_ATTEMPTS = 200;
    private const MAX_GROUPS = 80;

    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function listAnswerAttempts(array $filters): array
    {
        $outcome = (string)($filters['outcome'] ?? 'wrong');
        if (!in_array($outcome, ['wrong', 'correct', 'scored', 'all'], true)) {
            $outcome = 'wrong';
        }

        $search = trim((string)($filters['search'] ?? ''));
        $where = $this->buildAttemptWhere($outcome, $search);

        return [
            'filters' => [
                'outcome' => $outcome,
                'search' => $search,
            ],
            'groups' => $this->listAttemptGroups($where['sql'], $where['params']),
            'items' => $this->listAttemptItems($where['sql'], $where['params']),
        ];
    }

    private function listAttemptGroups(string $whereSql, array $params): array
    {
        $sql = 'SELECT
                    MIN(TRIM(CONCAT_WS(" ", aa.guess_title, aa.guess_artist))) AS guess_text,
                    COUNT(*) AS attempt_count,
                    SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count,
                    SUM(CASE WHEN aa.score_awarded > 0 THEN 1 ELSE 0 END) AS scored_count,
                    COUNT(DISTINCT aa.user_id) AS user_count,
                    COUNT(DISTINCT r.track_id) AS track_count,
                    MAX(aa.created_at) AS last_at,
                    GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ", ") AS usernames,
                    GROUP_CONCAT(DISTINCT f.name ORDER BY f.name SEPARATOR ", ") AS expected_answers,
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ", ") AS categories
                FROM mq_round_answer_attempts aa
                JOIN mq_rounds r ON r.id = aa.round_id
                JOIN mq_tracks t ON t.id = r.track_id
                JOIN mq_families f ON f.id = t.family_id
                JOIN mq_categories c ON c.id = f.category_id
                LEFT JOIN users u ON u.id = aa.user_id
                LEFT JOIN mq_lobbies l ON l.id = r.lobby_id
                ' . $whereSql . '
                GROUP BY LOWER(TRIM(CONCAT_WS(" ", aa.guess_title, aa.guess_artist)))
                HAVING guess_text IS NOT NULL AND guess_text <> ""
                ORDER BY attempt_count DESC, last_at DESC
                LIMIT ' . self::MAX_GROUPS;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function listAttemptItems(string $whereSql, array $params): array
    {
        $sql = 'SELECT
                    aa.id,
                    aa.round_id,
                    aa.user_id,
                    u.username,
                    TRIM(CONCAT_WS(" ", aa.guess_title, aa.guess_artist)) AS guess_text,
                    aa.guess_title,
                    aa.guess_artist,
                    aa.is_correct,
                    aa.score_awarded,
                    aa.created_at,
                    r.lobby_id,
                    r.round_number,
                    l.lobby_code,
                    t.id AS track_id,
                    t.title AS track_title,
                    t.artist AS track_artist,
                    f.id AS family_id,
                    f.name AS family_name,
                    c.id AS category_id,
                    c.name AS category_name
                FROM mq_round_answer_attempts aa
                JOIN mq_rounds r ON r.id = aa.round_id
                JOIN mq_tracks t ON t.id = r.track_id
                JOIN mq_families f ON f.id = t.family_id
                JOIN mq_categories c ON c.id = f.category_id
                LEFT JOIN users u ON u.id = aa.user_id
                LEFT JOIN mq_lobbies l ON l.id = r.lobby_id
                ' . $whereSql . '
                ORDER BY aa.created_at DESC
                LIMIT ' . self::MAX_ATTEMPTS;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function buildAttemptWhere(string $outcome, string $search): array
    {
        $clauses = ['TRIM(CONCAT_WS(" ", aa.guess_title, aa.guess_artist)) <> ""'];
        $params = [];

        if ($outcome === 'wrong') {
            $clauses[] = 'aa.is_correct = 0';
        } elseif ($outcome === 'correct') {
            $clauses[] = 'aa.is_correct = 1';
        } elseif ($outcome === 'scored') {
            $clauses[] = 'aa.score_awarded > 0';
        }

        if ($search !== '') {
            $clauses[] = '(aa.guess_title LIKE :search
                OR aa.guess_artist LIKE :search
                OR t.title LIKE :search
                OR t.artist LIKE :search
                OR f.name LIKE :search
                OR c.name LIKE :search
                OR u.username LIKE :search
                OR l.lobby_code LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        return [
            'sql' => 'WHERE ' . implode(' AND ', $clauses),
            'params' => $params,
        ];
    }
}
