<?php

class AnswerInsightAnalyzer
{
    private const ALIAS_CLUSTER_SIMILARITY = 0.84;
    private const IDEA_CLUSTER_SIMILARITY = 0.88;
    private const MAX_ALIAS_CANDIDATES = 80;
    private const MAX_CONTENT_IDEAS = 40;

    public function analyze(array $exactGroups): array
    {
        return [
            'alias_candidates' => $this->buildAliasCandidates($exactGroups),
            'content_ideas' => $this->buildContentIdeas($exactGroups),
        ];
    }

    private function buildAliasCandidates(array $exactGroups): array
    {
        $clustersByFamily = [];

        foreach ($exactGroups as $row) {
            if ((int)($row['wrong_count'] ?? 0) <= 0) {
                continue;
            }

            $familyKey = $this->familyKey($row);
            $guess = trim((string)($row['guess_text'] ?? ''));
            $guessKey = $this->normalize($guess);
            if ($familyKey === '' || $guessKey === '') {
                continue;
            }

            $clusterIndex = null;
            foreach ($clustersByFamily[$familyKey] ?? [] as $index => $cluster) {
                if ($this->similarity($guessKey, $cluster['representative_key']) >= self::ALIAS_CLUSTER_SIMILARITY) {
                    $clusterIndex = $index;
                    break;
                }
            }

            if ($clusterIndex === null) {
                $clustersByFamily[$familyKey][] = $this->newCluster($row, $guess, $guessKey);
                continue;
            }

            $this->mergeRowIntoCluster($clustersByFamily[$familyKey][$clusterIndex], $row, $guess, $guessKey);
        }

        $candidates = [];
        foreach ($clustersByFamily as $clusters) {
            foreach ($clusters as $cluster) {
                $acceptedAnswers = array_values(array_unique(array_filter(array_merge(
                    [(string)$cluster['family_name']],
                    $cluster['accepted_aliases']
                ))));
                $acceptedKeys = array_map(fn (string $value): string => $this->normalize($value), $acceptedAnswers);
                $isAlreadyAccepted = in_array($cluster['representative_key'], $acceptedKeys, true);
                $expectedSimilarity = 0.0;
                foreach ($acceptedKeys as $acceptedKey) {
                    $expectedSimilarity = max($expectedSimilarity, $this->similarity($cluster['representative_key'], $acceptedKey));
                }

                $attemptCount = (int)$cluster['attempt_count'];
                $userCount = count($cluster['user_ids']);
                if ($attemptCount < 2 && $userCount < 2) {
                    continue;
                }
                $confidence = $attemptCount >= 4 || $userCount >= 3
                    ? 'strong'
                    : (($attemptCount >= 2 || $userCount >= 2) ? 'medium' : 'review');

                $candidates[] = [
                    'guess_text' => $cluster['representative'],
                    'variants' => array_values($cluster['variants']),
                    'attempt_count' => $attemptCount,
                    'user_count' => $userCount,
                    'track_count' => count($cluster['track_ids']),
                    'family_id' => $cluster['family_id'],
                    'family_name' => $cluster['family_name'],
                    'category_id' => $cluster['category_id'],
                    'category_name' => $cluster['category_name'],
                    'track_titles' => array_values($cluster['track_titles']),
                    'accepted_aliases' => $cluster['accepted_aliases'],
                    'expected_similarity' => (int)round($expectedSimilarity * 100),
                    'last_at' => $cluster['last_at'],
                    'confidence' => $confidence,
                    'already_accepted' => $isAlreadyAccepted,
                    'can_add_alias' => $cluster['family_id'] > 0 && !$isAlreadyAccepted,
                    'score' => ($attemptCount * 4) + ($userCount * 7) + (int)round($expectedSimilarity * 10),
                ];
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            return ((int)$right['score'] <=> (int)$left['score'])
                ?: strcmp((string)$right['last_at'], (string)$left['last_at']);
        });

        return array_slice($candidates, 0, self::MAX_ALIAS_CANDIDATES);
    }

    private function buildContentIdeas(array $exactGroups): array
    {
        $clusters = [];

        foreach ($exactGroups as $row) {
            if ((int)($row['wrong_count'] ?? 0) <= 0) {
                continue;
            }

            $guess = trim((string)($row['guess_text'] ?? ''));
            $guessKey = $this->normalize($guess);
            if ($guessKey === '') {
                continue;
            }

            $clusterIndex = null;
            foreach ($clusters as $index => $cluster) {
                if ($this->similarity($guessKey, $cluster['representative_key']) >= self::IDEA_CLUSTER_SIMILARITY) {
                    $clusterIndex = $index;
                    break;
                }
            }

            if ($clusterIndex === null) {
                $clusters[] = $this->newIdeaCluster($row, $guess, $guessKey);
                continue;
            }

            $this->mergeRowIntoIdeaCluster($clusters[$clusterIndex], $row, $guess);
        }

        $ideas = [];
        foreach ($clusters as $cluster) {
            $attemptCount = (int)$cluster['attempt_count'];
            $userCount = count($cluster['user_ids']);
            $familyCount = count($cluster['family_keys']);
            $expectedSimilarity = 0.0;
            foreach ($cluster['expected_answers'] as $expectedAnswer) {
                $expectedSimilarity = max(
                    $expectedSimilarity,
                    $this->similarity($cluster['representative_key'], $this->normalize($expectedAnswer))
                );
            }

            if ($attemptCount < 2 || ($userCount < 2 && $familyCount < 2) || $expectedSimilarity >= 0.72) {
                continue;
            }

            $ideas[] = [
                'guess_text' => $cluster['representative'],
                'variants' => array_values($cluster['variants']),
                'attempt_count' => $attemptCount,
                'user_count' => $userCount,
                'family_count' => $familyCount,
                'category_ids' => array_values(array_map('intval', array_keys($cluster['category_ids']))),
                'recommended_category_id' => count($cluster['category_ids']) === 1
                    ? (int)array_key_first($cluster['category_ids'])
                    : null,
                'categories' => array_values($cluster['categories']),
                'expected_answers' => array_values($cluster['expected_answers']),
                'last_at' => $cluster['last_at'],
                'score' => ($attemptCount * 5) + ($userCount * 8) + ($familyCount * 4),
            ];
        }

        usort($ideas, static function (array $left, array $right): int {
            return ((int)$right['score'] <=> (int)$left['score'])
                ?: strcmp((string)$right['last_at'], (string)$left['last_at']);
        });

        return array_slice($ideas, 0, self::MAX_CONTENT_IDEAS);
    }

    private function newCluster(array $row, string $guess, string $guessKey): array
    {
        return [
            'representative' => $guess,
            'representative_key' => $guessKey,
            'representative_count' => (int)($row['attempt_count'] ?? 0),
            'variants' => [$guessKey => $guess],
            'attempt_count' => (int)($row['wrong_count'] ?? 0),
            'user_ids' => $this->csvSet((string)($row['user_ids'] ?? '')),
            'track_ids' => $this->csvSet((string)($row['track_ids'] ?? '')),
            'track_titles' => $this->csvTextSet((string)($row['track_titles'] ?? '')),
            'family_id' => (int)($row['family_id'] ?? 0),
            'family_name' => (string)($row['family_name'] ?? ''),
            'category_id' => (int)($row['category_id'] ?? 0),
            'category_name' => (string)($row['category_name'] ?? ''),
            'accepted_aliases' => array_values(array_filter($row['accepted_aliases'] ?? [])),
            'last_at' => (string)($row['last_at'] ?? ''),
        ];
    }

    private function mergeRowIntoCluster(array &$cluster, array $row, string $guess, string $guessKey): void
    {
        $count = (int)($row['attempt_count'] ?? 0);
        if ($count > (int)$cluster['representative_count']) {
            $cluster['representative'] = $guess;
            $cluster['representative_key'] = $guessKey;
            $cluster['representative_count'] = $count;
        }

        $cluster['variants'][$guessKey] = $guess;
        $cluster['attempt_count'] += (int)($row['wrong_count'] ?? 0);
        $cluster['user_ids'] += $this->csvSet((string)($row['user_ids'] ?? ''));
        $cluster['track_ids'] += $this->csvSet((string)($row['track_ids'] ?? ''));
        $cluster['track_titles'] += $this->csvTextSet((string)($row['track_titles'] ?? ''));
        $cluster['accepted_aliases'] = array_values(array_unique(array_merge(
            $cluster['accepted_aliases'],
            array_values(array_filter($row['accepted_aliases'] ?? []))
        )));
        if ((string)($row['last_at'] ?? '') > $cluster['last_at']) {
            $cluster['last_at'] = (string)$row['last_at'];
        }
    }

    private function newIdeaCluster(array $row, string $guess, string $guessKey): array
    {
        return [
            'representative' => $guess,
            'representative_key' => $guessKey,
            'representative_count' => (int)($row['attempt_count'] ?? 0),
            'variants' => [$guessKey => $guess],
            'attempt_count' => (int)($row['wrong_count'] ?? 0),
            'user_ids' => $this->csvSet((string)($row['user_ids'] ?? '')),
            'family_keys' => [$this->familyKey($row) => true],
            'category_ids' => $this->idSet([(int)($row['category_id'] ?? 0)]),
            'categories' => $this->textSet([(string)($row['category_name'] ?? '')]),
            'expected_answers' => $this->textSet([(string)($row['family_name'] ?? '')]),
            'last_at' => (string)($row['last_at'] ?? ''),
        ];
    }

    private function mergeRowIntoIdeaCluster(array &$cluster, array $row, string $guess): void
    {
        $guessKey = $this->normalize($guess);
        $count = (int)($row['attempt_count'] ?? 0);
        if ($count > (int)$cluster['representative_count']) {
            $cluster['representative'] = $guess;
            $cluster['representative_key'] = $guessKey;
            $cluster['representative_count'] = $count;
        }

        $cluster['variants'][$guessKey] = $guess;
        $cluster['attempt_count'] += (int)($row['wrong_count'] ?? 0);
        $cluster['user_ids'] += $this->csvSet((string)($row['user_ids'] ?? ''));
        $cluster['family_keys'][$this->familyKey($row)] = true;
        $cluster['category_ids'] += $this->idSet([(int)($row['category_id'] ?? 0)]);
        $cluster['categories'] += $this->textSet([(string)($row['category_name'] ?? '')]);
        $cluster['expected_answers'] += $this->textSet([(string)($row['family_name'] ?? '')]);
        if ((string)($row['last_at'] ?? '') > $cluster['last_at']) {
            $cluster['last_at'] = (string)$row['last_at'];
        }
    }

    private function familyKey(array $row): string
    {
        $familyId = (int)($row['family_id'] ?? 0);
        if ($familyId > 0) {
            return 'id:' . $familyId;
        }

        $familyName = $this->normalize((string)($row['family_name'] ?? ''));
        return $familyName !== '' ? 'snapshot:' . $familyName : '';
    }

    private function csvSet(string $value): array
    {
        $set = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $set[$item] = true;
            }
        }
        return $set;
    }

    private function csvTextSet(string $value): array
    {
        return $this->textSet(explode(' || ', $value));
    }

    private function textSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $set[$value] = $value;
            }
        }
        return $set;
    }

    private function idSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value > 0) {
                $set[$value] = true;
            }
        }
        return $set;
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }

        $maxLength = max(strlen($left), strlen($right));
        $levenshtein = $maxLength > 0
            ? 1.0 - (levenshtein($left, $right) / $maxLength)
            : 0.0;
        similar_text($left, $right, $similarPercent);

        return max(0.0, min(1.0, max($levenshtein, $similarPercent / 100)));
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower(trim((string)$ascii));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
