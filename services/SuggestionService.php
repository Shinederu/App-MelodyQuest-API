<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/CatalogService.php';
require_once __DIR__ . '/../utils/youtube.php';

class SuggestionService
{
    private const SUBMIT_RATE_LIMIT_MAX = 5;
    private const SUBMIT_RATE_LIMIT_WINDOW_SECONDS = 600;

    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function submit(?int $userId, array $payload): array
    {
        $this->enforceSubmitRateLimit($userId);

        $type = (string)($payload['suggestion_type'] ?? 'track_correction');
        if (!in_array($type, ['track_correction', 'new_track'], true)) {
            throw new RuntimeException('Type de suggestion invalide');
        }

        $track = null;
        $trackId = (int)($payload['track_id'] ?? 0);
        if ($type === 'track_correction') {
            if ($trackId <= 0) {
                throw new RuntimeException('track_id requis pour une correction');
            }
            $track = $this->getTrackContext($trackId);
            if (!$track) {
                throw new RuntimeException('Musique introuvable');
            }
        }

        $proposedTitle = $this->cleanText($payload['proposed_title'] ?? null, 220);
        $proposedArtist = $this->cleanText($payload['proposed_artist'] ?? null, 160);
        $proposedYoutubeUrl = $this->cleanText($payload['proposed_youtube_url'] ?? null, 255);
        $proposedAlias = $this->cleanText($payload['proposed_alias'] ?? null, 160);
        $note = $this->cleanText($payload['note'] ?? null, 2000);
        $videoId = $proposedYoutubeUrl !== null ? mq_normalize_youtube_video_id($proposedYoutubeUrl) : '';
        if ($proposedYoutubeUrl !== null && $videoId === '') {
            throw new RuntimeException('URL YouTube invalide');
        }

        if ($type === 'new_track' && $proposedTitle === null && $proposedYoutubeUrl === null) {
            throw new RuntimeException('Indique au moins un titre ou une URL');
        }

        if ($type === 'track_correction' && $proposedTitle === null && $proposedArtist === null && $proposedYoutubeUrl === null && $proposedAlias === null && $note === null) {
            throw new RuntimeException('Indique au moins une proposition');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mq_player_suggestions
             (suggestion_type, user_id, lobby_id, round_id, track_id, current_title, current_artist, current_youtube_video_id, current_family_name,
              proposed_title, proposed_artist, proposed_youtube_url, proposed_youtube_video_id, proposed_alias, note)
             VALUES
             (:suggestion_type, :user_id, :lobby_id, :round_id, :track_id, :current_title, :current_artist, :current_youtube_video_id, :current_family_name,
              :proposed_title, :proposed_artist, :proposed_youtube_url, :proposed_youtube_video_id, :proposed_alias, :note)'
        );
        $stmt->execute([
            'suggestion_type' => $type,
            'user_id' => $userId ?: null,
            'lobby_id' => (int)($payload['lobby_id'] ?? 0) ?: null,
            'round_id' => (int)($payload['round_id'] ?? 0) ?: null,
            'track_id' => $trackId ?: null,
            'current_title' => $track['title'] ?? null,
            'current_artist' => $track['artist'] ?? null,
            'current_youtube_video_id' => $track['youtube_video_id'] ?? null,
            'current_family_name' => $track['family_name'] ?? null,
            'proposed_title' => $proposedTitle,
            'proposed_artist' => $proposedArtist,
            'proposed_youtube_url' => $proposedYoutubeUrl,
            'proposed_youtube_video_id' => $videoId !== '' ? $videoId : null,
            'proposed_alias' => $proposedAlias,
            'note' => $note,
        ]);

        return ['id' => (int)$this->db->lastInsertId()];
    }

    public function list(string $status = 'pending'): array
    {
        if (!in_array($status, ['pending', 'reviewed', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $where = $status === 'all' ? '' : 'WHERE s.status = :status';
        $stmt = $this->db->prepare(
            'SELECT s.*,
                    u.username,
                    reviewer.username AS reviewer_username,
                    applied.title AS applied_track_title,
                    applied.artist AS applied_track_artist
             FROM mq_player_suggestions s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN users reviewer ON reviewer.id = s.reviewed_by_user_id
             LEFT JOIN mq_tracks applied ON applied.id = s.applied_track_id
             ' . $where . '
             ORDER BY s.created_at DESC
             LIMIT 200'
        );
        $params = $status === 'all' ? [] : ['status' => $status];
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function update(int $id, array $payload): array
    {
        $suggestion = $this->requireSuggestion($id);
        $draft = $this->buildDraft($suggestion, $payload);
        $this->persistDraft($id, $draft);

        return ['id' => $id] + $draft;
    }

    public function apply(int $id, int $reviewerUserId, array $payload): array
    {
        $suggestion = $this->requireSuggestion($id);
        $draft = $this->buildDraft($suggestion, $payload);
        $this->persistDraft($id, $draft);

        $catalogService = new CatalogService();
        if ((string)$suggestion['suggestion_type'] === 'new_track') {
            $result = $this->applyNewTrackSuggestion($catalogService, $reviewerUserId, $suggestion, $draft);
        } else {
            $result = $this->applyTrackCorrectionSuggestion($catalogService, $reviewerUserId, $suggestion, $draft);
        }

        $trackId = (int)($result['track_id'] ?? $result['id'] ?? 0);
        $stmt = $this->db->prepare(
            'UPDATE mq_player_suggestions
             SET status = "reviewed",
                 reviewed_at = NOW(3),
                 reviewed_by_user_id = :reviewer,
                 applied_track_id = :track_id,
                 applied_at = NOW(3)
             WHERE id = :id'
        );
        $stmt->execute([
            'reviewer' => $reviewerUserId,
            'track_id' => $trackId ?: null,
            'id' => $id,
        ]);

        return [
            'id' => $id,
            'status' => 'reviewed',
            'track_id' => $trackId,
            'applied' => $result,
        ];
    }

    public function updateStatus(int $id, string $status, int $reviewerUserId): array
    {
        if ($id <= 0) {
            throw new RuntimeException('Suggestion introuvable');
        }
        if (!in_array($status, ['pending', 'reviewed', 'rejected'], true)) {
            throw new RuntimeException('Statut invalide');
        }

        $stmt = $this->db->prepare(
            'UPDATE mq_player_suggestions
             SET status = :status,
                 reviewed_at = CASE WHEN :status_reviewed = "pending" THEN NULL ELSE NOW(3) END,
                 reviewed_by_user_id = CASE WHEN :status_reviewer = "pending" THEN NULL ELSE :reviewer END
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'status_reviewed' => $status,
            'status_reviewer' => $status,
            'reviewer' => $reviewerUserId,
            'id' => $id,
        ]);

        return ['id' => $id, 'status' => $status];
    }

    private function applyTrackCorrectionSuggestion(CatalogService $catalogService, int $reviewerUserId, array $suggestion, array $draft): array
    {
        $trackId = (int)($suggestion['track_id'] ?? 0);
        if ($trackId <= 0) {
            throw new RuntimeException('Aucune musique liee a cette correction');
        }

        $payload = ['track_id' => $trackId];
        if ($draft['proposed_title'] !== null) {
            $payload['title'] = $draft['proposed_title'];
        }
        if ($draft['proposed_artist'] !== null) {
            $payload['artist'] = $draft['proposed_artist'];
        }
        if ($draft['proposed_youtube_url'] !== null) {
            $payload['youtube_url'] = $draft['proposed_youtube_url'];
        } elseif ($draft['proposed_youtube_video_id'] !== null) {
            $payload['youtube_video_id'] = $draft['proposed_youtube_video_id'];
        }
        if ($draft['admin_start_offset_seconds'] !== null) {
            $payload['start_offset_seconds'] = $draft['admin_start_offset_seconds'];
        }
        if ($draft['proposed_alias'] !== null) {
            $payload['aliases'] = $this->mergeTrackAliases($trackId, $draft['proposed_alias']);
        }

        if (count($payload) <= 1) {
            throw new RuntimeException('Aucune correction applicable');
        }

        $result = $catalogService->validateTrack($reviewerUserId, $trackId, $payload);
        $result['track_id'] = $trackId;
        $result['type'] = 'track_correction';

        return $result;
    }

    private function applyNewTrackSuggestion(CatalogService $catalogService, int $reviewerUserId, array $suggestion, array $draft): array
    {
        $categoryId = (int)($draft['admin_category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('Choisis une categorie avant de creer la musique');
        }

        $familyName = $draft['admin_family_name'] ?: $draft['proposed_alias'];
        if ($familyName === null || trim($familyName) === '') {
            throw new RuntimeException('Indique une oeuvre ou reponse attendue');
        }
        if ($draft['proposed_title'] === null) {
            throw new RuntimeException('Indique un libelle de piste');
        }
        if ($draft['proposed_youtube_url'] === null && $draft['proposed_youtube_video_id'] === null) {
            throw new RuntimeException('Indique une URL YouTube valide');
        }

        $createPayload = [
            'category_id' => $categoryId,
            'family_name' => $familyName,
            'title' => $draft['proposed_title'],
            'artist' => $draft['proposed_artist'],
            'youtube_url' => $draft['proposed_youtube_url'],
            'youtube_video_id' => $draft['proposed_youtube_video_id'],
            'start_offset_seconds' => $draft['admin_start_offset_seconds'] ?? 0,
            'is_active' => 1,
        ];

        $created = $catalogService->createTrack($reviewerUserId, $createPayload);
        $trackId = (int)($created['id'] ?? 0);
        if ($trackId <= 0) {
            throw new RuntimeException('Creation de musique impossible');
        }

        $validatePayload = ['track_id' => $trackId];
        if ($draft['proposed_alias'] !== null && $this->normalizeForCompare($draft['proposed_alias']) !== $this->normalizeForCompare($familyName)) {
            $validatePayload['aliases'] = [$draft['proposed_alias']];
        }

        $validated = $catalogService->validateTrack($reviewerUserId, $trackId, $validatePayload);

        return [
            'type' => 'new_track',
            'track_id' => $trackId,
            'created' => $created,
            'validated' => $validated,
        ];
    }

    private function requireSuggestion(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('Suggestion introuvable');
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM mq_player_suggestions
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Suggestion introuvable');
        }

        return $row;
    }

    private function buildDraft(array $suggestion, array $payload): array
    {
        $proposedYoutubeUrl = $this->cleanDraftText($payload, $suggestion, 'proposed_youtube_url', 255);
        $proposedVideoId = $this->cleanDraftText($payload, $suggestion, 'proposed_youtube_video_id', 32);

        if ($proposedYoutubeUrl !== null) {
            $normalized = mq_normalize_youtube_video_id($proposedYoutubeUrl);
            if ($normalized === '') {
                throw new RuntimeException('URL YouTube invalide');
            }
            $proposedVideoId = $normalized;
            $proposedYoutubeUrl = mq_build_youtube_watch_url($normalized);
        } elseif ($proposedVideoId !== null) {
            $normalized = mq_normalize_youtube_video_id($proposedVideoId);
            if ($normalized === '') {
                throw new RuntimeException('Identifiant YouTube invalide');
            }
            $proposedVideoId = $normalized;
            $proposedYoutubeUrl = mq_build_youtube_watch_url($normalized);
        }

        return [
            'proposed_title' => $this->cleanDraftText($payload, $suggestion, 'proposed_title', 220),
            'proposed_artist' => $this->cleanDraftText($payload, $suggestion, 'proposed_artist', 160),
            'proposed_youtube_url' => $proposedYoutubeUrl,
            'proposed_youtube_video_id' => $proposedVideoId,
            'proposed_alias' => $this->cleanDraftText($payload, $suggestion, 'proposed_alias', 160),
            'admin_category_id' => $this->cleanDraftInt($payload, $suggestion, 'admin_category_id'),
            'admin_family_name' => $this->cleanDraftText($payload, $suggestion, 'admin_family_name', 160),
            'admin_start_offset_seconds' => $this->cleanDraftInt($payload, $suggestion, 'admin_start_offset_seconds'),
            'note' => $this->cleanDraftText($payload, $suggestion, 'note', 2000),
        ];
    }

    private function persistDraft(int $id, array $draft): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mq_player_suggestions
             SET proposed_title = :proposed_title,
                 proposed_artist = :proposed_artist,
                 proposed_youtube_url = :proposed_youtube_url,
                 proposed_youtube_video_id = :proposed_youtube_video_id,
                 proposed_alias = :proposed_alias,
                 admin_category_id = :admin_category_id,
                 admin_family_name = :admin_family_name,
                 admin_start_offset_seconds = :admin_start_offset_seconds,
                 note = :note
             WHERE id = :id'
        );
        $stmt->execute([
            'proposed_title' => $draft['proposed_title'],
            'proposed_artist' => $draft['proposed_artist'],
            'proposed_youtube_url' => $draft['proposed_youtube_url'],
            'proposed_youtube_video_id' => $draft['proposed_youtube_video_id'],
            'proposed_alias' => $draft['proposed_alias'],
            'admin_category_id' => $draft['admin_category_id'],
            'admin_family_name' => $draft['admin_family_name'],
            'admin_start_offset_seconds' => $draft['admin_start_offset_seconds'],
            'note' => $draft['note'],
            'id' => $id,
        ]);
    }

    private function cleanDraftText(array $payload, array $suggestion, string $key, int $maxLength): ?string
    {
        if (array_key_exists($key, $payload)) {
            return $this->cleanText($payload[$key], $maxLength);
        }

        return $this->cleanText($suggestion[$key] ?? null, $maxLength);
    }

    private function cleanDraftInt(array $payload, array $suggestion, string $key): ?int
    {
        $value = array_key_exists($key, $payload) ? $payload[$key] : ($suggestion[$key] ?? null);
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int)$value);
    }

    private function mergeTrackAliases(int $trackId, string $proposedAlias): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.name AS family_name, a.alias
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             LEFT JOIN mq_family_aliases a ON a.family_id = f.id
             WHERE t.id = :track_id
             ORDER BY a.alias ASC'
        );
        $stmt->execute(['track_id' => $trackId]);

        $aliases = [];
        foreach ($stmt->fetchAll() as $row) {
            $alias = trim((string)($row['alias'] ?? ''));
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }

        $aliases[] = $proposedAlias;

        $unique = [];
        foreach ($aliases as $alias) {
            $key = $this->normalizeForCompare($alias);
            if ($key === '' || array_key_exists($key, $unique)) {
                continue;
            }
            $unique[$key] = $alias;
        }

        return array_values($unique);
    }

    private function getTrackContext(int $trackId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.title, t.artist, t.youtube_video_id, f.name AS family_name
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $trackId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function enforceSubmitRateLimit(?int $userId): void
    {
        $key = $this->getRateLimitKey($userId);
        if ($key === '') {
            return;
        }

        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'melodyquest-rate-limits';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'suggestion-' . hash('sha256', $key) . '.json';
        $now = time();
        $events = [];
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                $events = array_values(array_filter(
                    array_map('intval', $decoded),
                    static fn(int $timestamp): bool => $timestamp > $now - self::SUBMIT_RATE_LIMIT_WINDOW_SECONDS
                ));
            }
        }

        if (count($events) >= self::SUBMIT_RATE_LIMIT_MAX) {
            throw new RuntimeException('Trop de suggestions envoyees. Reessaie dans quelques minutes.');
        }

        $events[] = $now;
        $encoded = json_encode($events);
        if (is_string($encoded)) {
            @file_put_contents($file, $encoded, LOCK_EX);
        }
    }

    private function getRateLimitKey(?int $userId): string
    {
        if ($userId !== null && $userId > 0) {
            return 'user:' . $userId;
        }

        $forwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $ip = trim(explode(',', $forwardedFor)[0] ?? '');
        if ($ip === '') {
            $ip = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        }
        if ($ip === '') {
            $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        }

        return $ip !== '' ? 'ip:' . $ip : '';
    }

    private function cleanText($value, int $maxLength): ?string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string)($value ?? '')));
        $text = trim((string)$text);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function normalizeForCompare(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = preg_replace('/[^a-z0-9 ]/', '', $value) ?? '';
        return trim($value);
    }
}
