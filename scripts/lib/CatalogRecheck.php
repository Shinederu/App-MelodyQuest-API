<?php

class CatalogRecheck
{
    public function __construct(private PDO $db) {}

    public function inspect(): array
    {
        return ['tracks' => (int)$this->db->query('SELECT COUNT(*) FROM mq_tracks')->fetchColumn(),
            'validated' => (int)$this->db->query('SELECT COUNT(*) FROM mq_tracks WHERE is_validated = 1')->fetchColumn(),
            'pending' => (int)$this->db->query('SELECT COUNT(*) FROM mq_tracks WHERE is_validated = 0')->fetchColumn(),
            'playing_lobbies' => (int)$this->db->query('SELECT COUNT(*) FROM mq_lobbies WHERE status = "playing"')->fetchColumn()];
    }

    public function apply(string $backupPath, int $expectedCount): array
    {
        if ($expectedCount <= 0 || $backupPath === '') throw new RuntimeException('Expected count and backup path required');
        $this->db->beginTransaction();
        try {
            // Prevent a concurrent start while retiring the current playable catalogue.
            $playing = $this->db->query('SELECT id, status FROM mq_lobbies ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($playing as $lobby) {
                if ($lobby['status'] === 'playing') throw new RuntimeException('A lobby is still playing; no changes made');
            }
            $rows = $this->db->query('SELECT * FROM mq_tracks ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== $expectedCount) throw new RuntimeException('Track count differs from the explicitly expected count');
            $json = json_encode(['database' => $this->db->query('SELECT DATABASE()')->fetchColumn(),
                'created_at_utc' => gmdate('c'), 'purpose' => 'Before manual timecode revalidation', 'tracks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_exists($backupPath)) throw new RuntimeException('Backup already exists; choose a new path');
            $file = fopen($backupPath, 'x');
            if ($file === false) throw new RuntimeException('Backup could not be created, or already exists');
            try {
                if (fwrite($file, $json) !== strlen($json) || !fflush($file)) throw new RuntimeException('Incomplete backup; no database change made');
            } finally {
                fclose($file);
            }
            $changed = $this->db->exec('UPDATE mq_tracks SET is_validated = 0, validated_by = NULL, validated_at = NULL WHERE is_validated = 1');
            $after = $this->db->query('SELECT * FROM mq_tracks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            if ($this->protectedRows($rows) !== $this->protectedRows($after)) throw new RuntimeException('Protected track data changed');
            $summary = $this->inspect();
            if ($summary['pending'] !== $expectedCount) throw new RuntimeException('Pending count mismatch');
            $this->db->commit();
            return $summary + ['changed' => $changed, 'backup' => $backupPath];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function protectedRows(array $rows): array
    {
        foreach ($rows as &$row) unset($row['is_validated'], $row['validated_by'], $row['validated_at'], $row['updated_at']);
        return $rows;
    }
}
