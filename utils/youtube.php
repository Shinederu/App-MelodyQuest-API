<?php

function mq_normalize_youtube_video_id(?string $value): string
{
    $input = trim((string)$value);
    if ($input === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_-]{6,32}$/', $input) === 1) {
        return $input;
    }

    $parts = @parse_url($input);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    parse_str((string)($parts['query'] ?? ''), $query);

    if ($host !== '' && str_contains($host, 'youtu.be')) {
        return mq_sanitize_youtube_video_id(mq_first_path_segment($path));
    }

    if (isset($query['v'])) {
        return mq_sanitize_youtube_video_id((string)$query['v']);
    }

    $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
    foreach (['embed', 'shorts', 'live'] as $needle) {
        $index = array_search($needle, $segments, true);
        if ($index !== false && isset($segments[$index + 1])) {
            return mq_sanitize_youtube_video_id($segments[$index + 1]);
        }
    }

    return '';
}

function mq_build_youtube_watch_url(?string $videoId): string
{
    $normalizedVideoId = mq_normalize_youtube_video_id($videoId);
    if ($normalizedVideoId === '') {
        return '';
    }

    return 'https://www.youtube.com/watch?v=' . rawurlencode($normalizedVideoId);
}

function mq_sanitize_youtube_video_id(?string $value): string
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_-]{6,32}$/', $normalized) !== 1) {
        return '';
    }

    return $normalized;
}

function mq_first_path_segment(string $path): string
{
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
    return $segments[0] ?? '';
}
