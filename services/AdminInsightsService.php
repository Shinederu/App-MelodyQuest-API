<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/AnswerInsightAnalyzer.php';

class AdminInsightsService
{
    private const MAX_ATTEMPTS = 200;
    private const MAX_EXACT_GROUPS = 400;
    private const DEFAULT_PERIOD_DAYS = 90;
    private const ALLOWED_PERIODS = [7, 30, 90, 365];

    private PDO $db;
    private AnswerInsightAnalyzer $analyzer;

    public function __construct(?PDO $db = null, ?AnswerInsightAnalyzer $analyzer = null)
    {
        $this->db = $db ?? DatabaseService::getInstance();
        $this->analyzer = $analyzer ?? new AnswerInsightAnalyzer();
    }

    public function listAnswerAttempts(array $filters): array
    {
        $outcome = (string)($filters['outcome'] ?? 'wrong');
        if (!in_array($outcome, ['wrong', 'correct', 'scored', 'all'], true)) {
            $outcome = 'wrong';
        }

        $search = $this->limitText(trim((string)($filters['search'] ?? '')), 120);
        $categoryId = max(0, (int)($filters['category_id'] ?? 0));
        $period = $this->normalizePeriod($filters['period'] ?? self::DEFAULT_PERIOD_DAYS);
        $where = $this->buildAttemptWhere($outcome, $search, $categoryId, $period);
        $exactGroups = $this->hydrateAcceptedAliases(
            $this->listExactGroups($where['sql'], $where['params'])
        );
        $analysis = $this->analyzer->analyze($exactGroups);
        $items = $this->listAttemptItems($where['sql'], $where['params']);

        return [
            'filters' => [
                'outcome' => $outcome,
                'search' => $search,
                'category_id' => $categoryId,
                'period' => $period === null ? 'all' : $period,
            ],
            'summary' => $this->buildSummary($exactGroups, $analysis, $items),
            'groups' => $analysis['alias_candidates'],
            'alias_candidates' => $analysis['alias_candidates'],
            'content_ideas' => $analysis['content_ideas'],
            'items' => $items,
        ];
    }

    private function listExactGroups(string $whereSql, array $params): array
    {
        $sql = 'SELECT
                    MIN(insight.guess_text) AS guess_text,
                    COUNT(*) AS attempt_count,
                    SUM(CASE WHEN insight.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_count,
                    SUM(CASE WHEN insight.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count,
                    SUM(CASE WHEN insight.score_awarded > 0 THEN 1 ELSE 0 END) AS scored_count,
                    SUM(CASE WHEN insight.source = "live" THEN 1 ELSE 0 END) AS live_count,
                    SUM(CASE WHEN insight.source = "history" THEN 1 ELSE 0 END) AS history_count,
                    COUNT(DISTINCT insight.actor_id) AS user_count,
                    COUNT(DISTINCT insight.track_id) AS track_count,
                    MAX(insight.attempted_at) AS last_at,
                    MAX(insight.family_id) AS family_id,
                    MIN(insight.family_name) AS family_name,
                    MAX(insight.category_id) AS category_id,
                    MIN(insight.category_name) AS category_name,
                    GROUP_CONCAT(DISTINCT insight.actor_id ORDER BY insight.actor_id SEPARATOR ",") AS user_ids,
                    GROUP_CONCAT(DISTINCT insight.track_id ORDER BY insight.track_id SEPARATOR ",") AS track_ids,
                    GROUP_CONCAT(DISTINCT insight.track_title ORDER BY insight.track_title SEPARATOR " || ") AS track_titles,
                    GROUP_CONCAT(DISTINCT insight.username ORDER BY insight.username SEPARATOR ", ") AS usernames
                FROM (' . $this->attemptDatasetSql() . ') insight
                ' . $whereSql . '
                GROUP BY CASE WHEN insight.family_id > 0 THEN CONCAT("id:", insight.family_id) ELSE CONCAT("snapshot:", LOWER(insight.category_name), ":", LOWER(insight.family_name)) END,
                         LOWER(TRIM(insight.guess_text))
                HAVING guess_text IS NOT NULL AND guess_text <> ""
                ORDER BY attempt_count DESC, last_at DESC
                LIMIT ' . self::MAX_EXACT_GROUPS;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function listAttemptItems(string $whereSql, array $params): array
    {
        $sql = 'SELECT insight.*
                FROM (' . $this->attemptDatasetSql() . ') insight
                ' . $whereSql . '
                ORDER BY insight.attempted_at DESC, insight.attempt_key DESC
                LIMIT ' . self::MAX_ATTEMPTS;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function attemptDatasetSql(): string
    {
        return $this->liveAttemptDatasetSql() . ' UNION ALL ' . $this->historyAttemptDatasetSql();
    }

    private function liveAttemptDatasetSql(): string
    {
        return 'SELECT
                    CONCAT("live:", answer_attempt.id) AS attempt_key,
                    "live" AS source,
                    answer_attempt.id AS source_attempt_id,
                    answer_attempt.round_id,
                    answer_attempt.actor_id,
                    answer_attempt.user_id,
                    COALESCE(NULLIF(lobby_player.display_name_snapshot, ""), user.username, "Invité") AS username,
                    CASE
                      WHEN LOWER(TRIM(COALESCE(answer_attempt.guess_title, ""))) = LOWER(TRIM(COALESCE(answer_attempt.guess_artist, "")))
                        THEN TRIM(COALESCE(answer_attempt.guess_title, answer_attempt.guess_artist, ""))
                      ELSE TRIM(CONCAT_WS(" ", answer_attempt.guess_title, answer_attempt.guess_artist))
                    END AS guess_text,
                    answer_attempt.guess_title,
                    answer_attempt.guess_artist,
                    answer_attempt.is_correct,
                    answer_attempt.score_awarded,
                    answer_attempt.created_at AS attempted_at,
                    round.lobby_id,
                    round.round_number,
                    lobby.lobby_code,
                    track.id AS track_id,
                    track.title AS track_title,
                    track.artist AS track_artist,
                    family.id AS family_id,
                    family.name AS family_name,
                    category.id AS category_id,
                    category.name AS category_name
                FROM mq_round_answer_attempts answer_attempt
                JOIN mq_rounds round ON round.id = answer_attempt.round_id
                JOIN mq_tracks track ON track.id = round.track_id
                JOIN mq_families family ON family.id = track.family_id
                JOIN mq_categories category ON category.id = family.category_id
                LEFT JOIN users user ON user.id = answer_attempt.user_id
                LEFT JOIN mq_lobby_players lobby_player
                  ON lobby_player.lobby_id = round.lobby_id
                 AND lobby_player.actor_id = answer_attempt.actor_id
                LEFT JOIN mq_lobbies lobby ON lobby.id = round.lobby_id
                LEFT JOIN mq_game_session_answer_attempts history_copy
                  ON history_copy.source_attempt_id = answer_attempt.id
                WHERE history_copy.id IS NULL';
    }

    private function historyAttemptDatasetSql(): string
    {
        return 'SELECT
                    CONCAT("history:", history_attempt.id) AS attempt_key,
                    "history" AS source,
                    history_attempt.source_attempt_id,
                    history_round.source_round_id AS round_id,
                    history_attempt.actor_id,
                    history_attempt.user_id,
                    history_attempt.username_snapshot AS username,
                    CASE
                      WHEN LOWER(TRIM(COALESCE(history_attempt.guess_title, ""))) = LOWER(TRIM(COALESCE(history_attempt.guess_artist, "")))
                        THEN TRIM(COALESCE(history_attempt.guess_title, history_attempt.guess_artist, ""))
                      ELSE TRIM(CONCAT_WS(" ", history_attempt.guess_title, history_attempt.guess_artist))
                    END AS guess_text,
                    history_attempt.guess_title,
                    history_attempt.guess_artist,
                    history_attempt.is_correct,
                    history_attempt.score_awarded,
                    history_attempt.attempted_at,
                    game_session.source_lobby_id AS lobby_id,
                    history_round.round_number,
                    game_session.lobby_code,
                    history_round.track_id,
                    history_round.track_title_snapshot AS track_title,
                    history_round.track_artist_snapshot AS track_artist,
                    current_family.id AS family_id,
                    COALESCE(current_family.name, history_round.family_name_snapshot) AS family_name,
                    current_category.id AS category_id,
                    COALESCE(current_category.name, history_round.category_name_snapshot) AS category_name
                FROM mq_game_session_answer_attempts history_attempt
                JOIN mq_game_session_rounds history_round
                  ON history_round.id = history_attempt.game_session_round_id
                JOIN mq_game_sessions game_session
                  ON game_session.id = history_round.game_session_id
                LEFT JOIN mq_tracks current_track ON current_track.id = history_round.track_id
                LEFT JOIN mq_families current_family ON current_family.id = current_track.family_id
                LEFT JOIN mq_categories current_category ON current_category.id = current_family.category_id';
    }

    private function hydrateAcceptedAliases(array $groups): array
    {
        $familyIds = array_values(array_unique(array_filter(array_map(
            static fn (array $group): int => (int)($group['family_id'] ?? 0),
            $groups
        ))));
        $aliasesByFamily = [];

        if (!empty($familyIds)) {
            $placeholders = [];
            $params = [];
            foreach ($familyIds as $index => $familyId) {
                $key = 'family_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $familyId;
            }

            $stmt = $this->db->prepare(
                'SELECT family_id, alias
                 FROM mq_family_aliases
                 WHERE family_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY alias ASC'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $aliasesByFamily[(int)$row['family_id']][] = (string)$row['alias'];
            }
        }

        foreach ($groups as &$group) {
            $group['accepted_aliases'] = $aliasesByFamily[(int)($group['family_id'] ?? 0)] ?? [];
        }
        unset($group);

        return $groups;
    }

    private function buildAttemptWhere(string $outcome, string $search, int $categoryId, ?int $period): array
    {
        $clauses = ['insight.guess_text <> ""'];
        $params = [];

        if ($outcome === 'wrong') {
            $clauses[] = 'insight.is_correct = 0';
        } elseif ($outcome === 'correct') {
            $clauses[] = 'insight.is_correct = 1';
        } elseif ($outcome === 'scored') {
            $clauses[] = 'insight.score_awarded > 0';
        }

        if ($search !== '') {
            $clauses[] = '(insight.guess_text LIKE :search
                OR insight.track_title LIKE :search
                OR insight.track_artist LIKE :search
                OR insight.family_name LIKE :search
                OR insight.category_name LIKE :search
                OR insight.username LIKE :search
                OR insight.lobby_code LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($categoryId > 0) {
            $clauses[] = 'insight.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        if ($period !== null) {
            $params['attempted_after'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('-' . $period . ' days')
                ->format('Y-m-d H:i:s.u');
            $clauses[] = 'insight.attempted_at >= :attempted_after';
        }

        return [
            'sql' => 'WHERE ' . implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    private function buildSummary(array $exactGroups, array $analysis, array $items): array
    {
        $attemptCount = 0;
        $liveCount = 0;
        $historyCount = 0;
        foreach ($exactGroups as $group) {
            $attemptCount += (int)($group['attempt_count'] ?? 0);
            $liveCount += (int)($group['live_count'] ?? 0);
            $historyCount += (int)($group['history_count'] ?? 0);
        }

        return [
            'attempt_count' => $attemptCount,
            'live_count' => $liveCount,
            'history_count' => $historyCount,
            'alias_candidate_count' => count($analysis['alias_candidates'] ?? []),
            'content_idea_count' => count($analysis['content_ideas'] ?? []),
            'visible_item_count' => count($items),
            'truncated' => count($exactGroups) >= self::MAX_EXACT_GROUPS || count($items) >= self::MAX_ATTEMPTS,
        ];
    }

    private function normalizePeriod(mixed $value): ?int
    {
        if ((string)$value === 'all') {
            return null;
        }

        $period = (int)$value;
        return in_array($period, self::ALLOWED_PERIODS, true)
            ? $period
            : self::DEFAULT_PERIOD_DAYS;
    }

    private function limitText(string $value, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return substr($value, 0, $maxLength);
    }
}
