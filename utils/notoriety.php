<?php

require_once __DIR__ . '/track_options.php';

const MQ_NOTORIETY_PRIOR_WEIGHT = 10;

function mq_notoriety_seed($value): int
{
    if (!in_array($value, [50, 75, 100, '50', '75', '100'], true)) {
        throw new RuntimeException('Estimation initiale attendue : 50, 75 ou 100 %.');
    }
    return (int)$value;
}

function mq_notoriety_minimum(array $payload): int
{
    if (array_key_exists('min_notoriety', $payload)) {
        if (!in_array($payload['min_notoriety'], [0, 60, 90, '0', '60', '90'], true)) {
            throw new RuntimeException('Notoriété minimale attendue : 0, 60 ou 90 %.');
        }
        return (int)$payload['min_notoriety'];
    }
    $legacy = mq_familiarity($payload['min_familiarity'] ?? 1, false);
    return $legacy <= 1 ? 0 : ($legacy >= 9 ? 90 : 60);
}

function mq_notoriety_percent(int $seed, int $known, int $total): int
{
    return (int)floor((MQ_NOTORIETY_PRIOR_WEIGHT * $seed + 100 * $known) / (MQ_NOTORIETY_PRIOR_WEIGHT + $total));
}

// Callers use the fixed family alias f and one aggregate row per family.
function mq_notoriety_join(): string
{
    return ' LEFT JOIN (SELECT family_id, SUM(known) AS known_count, COUNT(*) AS vote_count
        FROM mq_family_knowledge GROUP BY family_id) nk ON nk.family_id = f.id ';
}

function mq_notoriety_sql(): string
{
    $weight = MQ_NOTORIETY_PRIOR_WEIGHT;
    return "FLOOR(($weight * f.notoriety_seed + 100 * COALESCE(nk.known_count, 0)) / ($weight + COALESCE(nk.vote_count, 0)))";
}
