<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/../utils/youtube.php';

class SuggestionService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function submit(?int $userId, array $payload): array
    {
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
            'SELECT s.*, u.username, reviewer.username AS reviewer_username
             FROM mq_player_suggestions s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN users reviewer ON reviewer.id = s.reviewed_by_user_id
             ' . $where . '
             ORDER BY s.created_at DESC
             LIMIT 200'
        );
        $params = $status === 'all' ? [] : ['status' => $status];
        $stmt->execute($params);

        return $stmt->fetchAll();
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
}
