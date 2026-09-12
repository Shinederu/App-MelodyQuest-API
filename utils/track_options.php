<?php

function mq_optional_seconds($value): ?int
{
    if ($value === null || $value === '') return null;
    $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($number === false || $number < 0 || $number > 86400) {
        throw new RuntimeException('Le timestamp doit être un nombre entier entre 0 et 86400 secondes');
    }
    return $number;
}

function mq_track_bounds(array $payload, array $current = []): array
{
    $start = mq_optional_seconds($payload['start_offset_seconds'] ?? $current['start_offset_seconds'] ?? 0) ?? 0;
    $end = mq_optional_seconds(array_key_exists('end_offset_seconds', $payload) ? $payload['end_offset_seconds'] : ($current['end_offset_seconds'] ?? null));
    if ($end !== null && $end <= $start) {
        throw new RuntimeException('La fin de la musique doit être après son début');
    }
    return ['start_offset_seconds' => $start, 'end_offset_seconds' => $end];
}

function mq_familiarity($value, bool $optional = true): ?int
{
    if ($optional && ($value === null || $value === '')) return null;
    $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($number === false || $number < 1 || $number > 10) {
        throw new RuntimeException('La notoriété doit être comprise entre 1 et 10');
    }
    return $number;
}
