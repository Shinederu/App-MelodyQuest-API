<?php

require_once __DIR__ . '/DatabaseService.php';

class CatalogService
{
    private PDO $db;
    private ?bool $familyAliasesTableExists = null;

    public function __construct()
    {
        $this->db = DatabaseService::getInstance();
    }

    public function listCategories(): array
    {
        $stmt = $this->db->query(
            'SELECT id, name, slug, is_active, created_at, updated_at
             FROM mq_categories
             ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public function listFamilies(?int $categoryId = null): array
    {
        if ($categoryId) {
            $stmt = $this->db->prepare(
                'SELECT f.id, f.category_id, c.name AS category_name, f.name, f.slug, f.description, f.is_active
                 FROM mq_families f
                 JOIN mq_categories c ON c.id = f.category_id
                 WHERE f.category_id = :category_id
                 ORDER BY f.name ASC'
            );
            $stmt->execute(['category_id' => $categoryId]);
            return $this->hydrateFamilyAliases($stmt->fetchAll());
        }

        $stmt = $this->db->query(
            'SELECT f.id, f.category_id, c.name AS category_name, f.name, f.slug, f.description, f.is_active
             FROM mq_families f
             JOIN mq_categories c ON c.id = f.category_id
             ORDER BY c.name ASC, f.name ASC'
        );
        return $this->hydrateFamilyAliases($stmt->fetchAll());
    }

    public function listTracks(?int $familyId = null): array
    {
        if ($familyId) {
            $stmt = $this->db->prepare(
                'SELECT t.id, t.family_id, f.category_id, c.name AS category_name, f.name AS family_name,
                        t.title, t.artist, t.youtube_url, t.youtube_video_id, t.duration_seconds, t.is_active
                 FROM mq_tracks t
                 JOIN mq_families f ON f.id = t.family_id
                 JOIN mq_categories c ON c.id = f.category_id
                 WHERE t.family_id = :family_id
                 ORDER BY t.title ASC'
            );
            $stmt->execute(['family_id' => $familyId]);
            return $stmt->fetchAll();
        }

        $stmt = $this->db->query(
            'SELECT t.id, t.family_id, f.category_id, c.name AS category_name, f.name AS family_name,
                    t.title, t.artist, t.youtube_url, t.youtube_video_id, t.duration_seconds, t.is_active
             FROM mq_tracks t
             JOIN mq_families f ON f.id = t.family_id
             JOIN mq_categories c ON c.id = f.category_id
             ORDER BY c.name ASC, f.name ASC, t.title ASC'
        );
        return $stmt->fetchAll();
    }

    public function createCategory(int $userId, array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            throw new RuntimeException('name et slug sont requis');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mq_categories (name, slug, is_active, created_by)
             VALUES (:name, :slug, :is_active, :created_by)'
        );
        $stmt->execute([
            'name' => $name,
            'slug' => strtolower($slug),
            'is_active' => isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : 1,
            'created_by' => $userId,
        ]);

        return ['id' => (int)$this->db->lastInsertId()];
    }

    public function createFamily(int $userId, array $payload): array
    {
        $categoryId = (int)($payload['category_id'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($categoryId <= 0 || $name === '' || $slug === '') {
            throw new RuntimeException('category_id, name, slug requis');
        }

        $this->assertCategoryExists($categoryId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mq_families (category_id, name, slug, description, is_active, created_by)
                 VALUES (:category_id, :name, :slug, :description, :is_active, :created_by)'
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'name' => $name,
                'slug' => strtolower($slug),
                'description' => isset($payload['description']) ? (string)$payload['description'] : null,
                'is_active' => isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : 1,
                'created_by' => $userId,
            ]);

            $familyId = (int)$this->db->lastInsertId();
            $this->syncFamilyAliases($userId, $familyId, $name, $payload['aliases'] ?? []);

            $this->db->commit();
            return ['id' => $familyId];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function createTrack(int $userId, array $payload): array
    {
        $familyId = $this->resolveTrackFamilyId($userId, $payload, true);
        $title = trim((string)($payload['title'] ?? ''));
        $youtubeUrl = trim((string)($payload['youtube_url'] ?? ''));
        if ($title === '' || $youtubeUrl === '') {
            throw new RuntimeException('title et youtube_url requis');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mq_tracks
             (family_id, title, artist, youtube_url, youtube_video_id, duration_seconds, start_offset_seconds, end_offset_seconds, is_active, created_by)
             VALUES
             (:family_id, :title, :artist, :youtube_url, :youtube_video_id, :duration_seconds, :start_offset_seconds, :end_offset_seconds, :is_active, :created_by)'
        );
        $stmt->execute([
            'family_id' => $familyId,
            'title' => $title,
            'artist' => isset($payload['artist']) ? trim((string)$payload['artist']) : null,
            'youtube_url' => $youtubeUrl,
            'youtube_video_id' => isset($payload['youtube_video_id']) ? trim((string)$payload['youtube_video_id']) : null,
            'duration_seconds' => isset($payload['duration_seconds']) ? (int)$payload['duration_seconds'] : null,
            'start_offset_seconds' => isset($payload['start_offset_seconds']) ? (int)$payload['start_offset_seconds'] : 0,
            'end_offset_seconds' => isset($payload['end_offset_seconds']) ? (int)$payload['end_offset_seconds'] : null,
            'is_active' => isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : 1,
            'created_by' => $userId,
        ]);

        return ['id' => (int)$this->db->lastInsertId()];
    }

    public function updateCategory(array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('id categorie requis');
        }

        $sets = [];
        $params = ['id' => $id];

        if (array_key_exists('name', $payload)) {
            $name = trim((string)$payload['name']);
            if ($name === '') {
                throw new RuntimeException('name invalide');
            }
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('slug', $payload)) {
            $slug = strtolower(trim((string)$payload['slug']));
            if ($slug === '') {
                throw new RuntimeException('slug invalide');
            }
            $sets[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if (array_key_exists('is_active', $payload)) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = (int)((bool)$payload['is_active']);
        }

        if (empty($sets)) {
            throw new RuntimeException('Aucun champ a modifier');
        }

        $sql = 'UPDATE mq_categories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return ['id' => $id, 'updated' => $stmt->rowCount()];
    }

    public function updateFamily(int $userId, array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('id famille requis');
        }

        $existing = $this->requireFamilyRecord($id);

        $sets = [];
        $params = ['id' => $id];
        $familyName = (string)$existing['name'];

        if (array_key_exists('category_id', $payload)) {
            $categoryId = (int)$payload['category_id'];
            if ($categoryId <= 0) {
                throw new RuntimeException('category_id invalide');
            }
            $this->assertCategoryExists($categoryId);
            $sets[] = 'category_id = :category_id';
            $params['category_id'] = $categoryId;
        }
        if (array_key_exists('name', $payload)) {
            $name = trim((string)$payload['name']);
            if ($name === '') {
                throw new RuntimeException('name invalide');
            }
            $familyName = $name;
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('slug', $payload)) {
            $slug = strtolower(trim((string)$payload['slug']));
            if ($slug === '') {
                throw new RuntimeException('slug invalide');
            }
            $sets[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if (array_key_exists('description', $payload)) {
            $sets[] = 'description = :description';
            $params['description'] = (string)$payload['description'];
        }
        if (array_key_exists('is_active', $payload)) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = (int)((bool)$payload['is_active']);
        }

        if (empty($sets) && !array_key_exists('aliases', $payload)) {
            throw new RuntimeException('Aucun champ a modifier');
        }

        $updated = 0;

        $this->db->beginTransaction();
        try {
            if (!empty($sets)) {
                $sql = 'UPDATE mq_families SET ' . implode(', ', $sets) . ' WHERE id = :id';
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $updated = $stmt->rowCount();
            }

            if (array_key_exists('aliases', $payload)) {
                $this->syncFamilyAliases($userId, $id, $familyName, $payload['aliases']);
            }

            $this->db->commit();
            return ['id' => $id, 'updated' => $updated];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateTrack(int $userId, array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('id musique requis');
        }

        $sets = [];
        $params = ['id' => $id];

        if (
            array_key_exists('family_id', $payload)
            || array_key_exists('category_id', $payload)
            || array_key_exists('family_name', $payload)
        ) {
            $familyId = $this->resolveTrackFamilyId($userId, $payload, true);
            $sets[] = 'family_id = :family_id';
            $params['family_id'] = $familyId;
        }

        if (array_key_exists('title', $payload)) {
            $title = trim((string)$payload['title']);
            if ($title === '') {
                throw new RuntimeException('title invalide');
            }
            $sets[] = 'title = :title';
            $params['title'] = $title;
        }
        if (array_key_exists('artist', $payload)) {
            $sets[] = 'artist = :artist';
            $params['artist'] = trim((string)$payload['artist']);
        }
        if (array_key_exists('youtube_url', $payload)) {
            $url = trim((string)$payload['youtube_url']);
            if ($url === '') {
                throw new RuntimeException('youtube_url invalide');
            }
            $sets[] = 'youtube_url = :youtube_url';
            $params['youtube_url'] = $url;
        }
        if (array_key_exists('youtube_video_id', $payload)) {
            $sets[] = 'youtube_video_id = :youtube_video_id';
            $params['youtube_video_id'] = trim((string)$payload['youtube_video_id']);
        }
        if (array_key_exists('duration_seconds', $payload)) {
            $sets[] = 'duration_seconds = :duration_seconds';
            $params['duration_seconds'] = $payload['duration_seconds'] !== null ? (int)$payload['duration_seconds'] : null;
        }
        if (array_key_exists('start_offset_seconds', $payload)) {
            $sets[] = 'start_offset_seconds = :start_offset_seconds';
            $params['start_offset_seconds'] = max(0, (int)$payload['start_offset_seconds']);
        }
        if (array_key_exists('end_offset_seconds', $payload)) {
            $sets[] = 'end_offset_seconds = :end_offset_seconds';
            $params['end_offset_seconds'] = $payload['end_offset_seconds'] !== null ? (int)$payload['end_offset_seconds'] : null;
        }
        if (array_key_exists('is_active', $payload)) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = (int)((bool)$payload['is_active']);
        }

        if (empty($sets)) {
            throw new RuntimeException('Aucun champ a modifier');
        }

        $sql = 'UPDATE mq_tracks SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return ['id' => $id, 'updated' => $stmt->rowCount()];
    }

    public function deleteCategory(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('id categorie requis');
        }
        $stmt = $this->db->prepare('DELETE FROM mq_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return ['id' => $id, 'deleted' => $stmt->rowCount()];
    }

    public function deleteFamily(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('id famille requis');
        }
        $stmt = $this->db->prepare('DELETE FROM mq_families WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return ['id' => $id, 'deleted' => $stmt->rowCount()];
    }

    public function deleteTrack(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('id musique requis');
        }
        $stmt = $this->db->prepare('DELETE FROM mq_tracks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return ['id' => $id, 'deleted' => $stmt->rowCount()];
    }

    private function resolveTrackFamilyId(int $userId, array $payload, bool $required): ?int
    {
        $familyId = (int)($payload['family_id'] ?? 0);
        $categoryId = (int)($payload['category_id'] ?? 0);
        $familyName = trim((string)($payload['family_name'] ?? ''));

        if ($categoryId > 0 || $familyName !== '') {
            if ($categoryId <= 0 || $familyName === '') {
                throw new RuntimeException('category_id et family_name requis');
            }

            return $this->findOrCreateFamily($userId, $categoryId, $familyName);
        }

        if ($familyId > 0) {
            return $this->requireFamilyId($familyId);
        }

        if ($required) {
            throw new RuntimeException('family_id ou category_id + family_name requis');
        }

        return null;
    }

    private function findOrCreateFamily(int $userId, int $categoryId, string $familyName): int
    {
        $normalizedName = trim($familyName);
        $slug = $this->slugify($normalizedName);
        if ($normalizedName === '' || $slug === '') {
            throw new RuntimeException('family_name invalide');
        }

        $this->assertCategoryExists($categoryId);

        $existing = $this->db->prepare(
            'SELECT id
             FROM mq_families
             WHERE category_id = :category_id AND slug = :slug
             LIMIT 1'
        );
        $existing->execute([
            'category_id' => $categoryId,
            'slug' => $slug,
        ]);

        $row = $existing->fetch();
        if ($row && !empty($row['id'])) {
            return (int)$row['id'];
        }

        $insert = $this->db->prepare(
            'INSERT INTO mq_families (category_id, name, slug, description, is_active, created_by)
             VALUES (:category_id, :name, :slug, :description, :is_active, :created_by)'
        );
        $insert->execute([
            'category_id' => $categoryId,
            'name' => $normalizedName,
            'slug' => $slug,
            'description' => null,
            'is_active' => 1,
            'created_by' => $userId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function requireFamilyId(int $familyId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM mq_families WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $familyId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['id'])) {
            throw new RuntimeException('family_id invalide');
        }

        return (int)$row['id'];
    }

    private function requireFamilyRecord(int $familyId): array
    {
        $stmt = $this->db->prepare('SELECT id, category_id, name, slug FROM mq_families WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $familyId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('id famille requis');
        }

        return $row;
    }

    private function hydrateFamilyAliases(array $families): array
    {
        if (empty($families)) {
            return [];
        }

        foreach ($families as &$family) {
            $family['aliases'] = [];
            $family['alias_count'] = 0;
        }
        unset($family);

        if (!$this->hasFamilyAliasesTable()) {
            return $families;
        }

        $familyIds = array_values(array_filter(array_map(
            static fn(array $family): int => (int)($family['id'] ?? 0),
            $families
        )));
        if (empty($familyIds)) {
            return $families;
        }

        $placeholders = [];
        $params = [];
        foreach ($familyIds as $index => $familyId) {
            $key = 'family_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $familyId;
        }

        $sql = 'SELECT family_id, alias
                FROM mq_family_aliases
                WHERE family_id IN (' . implode(', ', $placeholders) . ')
                ORDER BY alias ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $aliasesByFamily = [];
        foreach ($stmt->fetchAll() as $row) {
            $familyId = (int)($row['family_id'] ?? 0);
            $alias = trim((string)($row['alias'] ?? ''));
            if ($familyId <= 0 || $alias === '') {
                continue;
            }
            $aliasesByFamily[$familyId][] = $alias;
        }

        foreach ($families as &$family) {
            $familyId = (int)($family['id'] ?? 0);
            $aliases = $aliasesByFamily[$familyId] ?? [];
            $family['aliases'] = $aliases;
            $family['alias_count'] = count($aliases);
        }
        unset($family);

        return $families;
    }

    private function syncFamilyAliases(int $userId, int $familyId, string $familyName, $rawAliases): void
    {
        $aliases = $this->normalizeAliasMap($familyName, $rawAliases);

        if (!$this->hasFamilyAliasesTable()) {
            if (!empty($aliases)) {
                throw new RuntimeException('La migration SQL des alias doit etre appliquee avant de sauvegarder des alias');
            }
            return;
        }

        $existingStmt = $this->db->prepare(
            'SELECT id, alias, slug
             FROM mq_family_aliases
             WHERE family_id = :family_id'
        );
        $existingStmt->execute(['family_id' => $familyId]);

        $existingBySlug = [];
        foreach ($existingStmt->fetchAll() as $row) {
            $slug = (string)($row['slug'] ?? '');
            if ($slug !== '') {
                $existingBySlug[$slug] = $row;
            }
        }

        $staleIds = [];
        foreach ($existingBySlug as $slug => $row) {
            if (!array_key_exists($slug, $aliases)) {
                $staleIds[] = (int)$row['id'];
            }
        }

        if (!empty($staleIds)) {
            $placeholders = [];
            $params = [];
            foreach ($staleIds as $index => $id) {
                $key = 'id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }

            $delete = $this->db->prepare(
                'DELETE FROM mq_family_aliases
                 WHERE id IN (' . implode(', ', $placeholders) . ')'
            );
            $delete->execute($params);
        }

        foreach ($aliases as $slug => $alias) {
            $existing = $existingBySlug[$slug] ?? null;
            if ($existing) {
                if ((string)$existing['alias'] !== $alias) {
                    $update = $this->db->prepare(
                        'UPDATE mq_family_aliases
                         SET alias = :alias
                         WHERE id = :id'
                    );
                    $update->execute([
                        'alias' => $alias,
                        'id' => (int)$existing['id'],
                    ]);
                }
                continue;
            }

            $insert = $this->db->prepare(
                'INSERT INTO mq_family_aliases (family_id, alias, slug, created_by)
                 VALUES (:family_id, :alias, :slug, :created_by)'
            );
            $insert->execute([
                'family_id' => $familyId,
                'alias' => $alias,
                'slug' => $slug,
                'created_by' => $userId,
            ]);
        }
    }

    private function normalizeAliasMap(string $familyName, $rawAliases): array
    {
        $familyNormalized = $this->normalizeForCompare($familyName);
        $aliases = [];

        foreach ($this->extractAliasValues($rawAliases) as $value) {
            $alias = trim($value);
            $slug = $this->slugify($alias);
            if ($alias === '' || $slug === '') {
                continue;
            }

            if ($this->normalizeForCompare($alias) === $familyNormalized) {
                continue;
            }

            if (!array_key_exists($slug, $aliases)) {
                $aliases[$slug] = $alias;
            }
        }

        return $aliases;
    }

    private function extractAliasValues($rawAliases): array
    {
        if (is_array($rawAliases)) {
            return array_values(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                $rawAliases
            ), static fn(string $value): bool => $value !== ''));
        }

        if (is_string($rawAliases)) {
            $parts = preg_split('/[\r\n,;]+/', $rawAliases) ?: [];
            return array_values(array_filter(array_map(
                static fn(string $value): string => trim($value),
                $parts
            ), static fn(string $value): bool => $value !== ''));
        }

        return [];
    }

    private function hasFamilyAliasesTable(): bool
    {
        if ($this->familyAliasesTableExists !== null) {
            return $this->familyAliasesTableExists;
        }

        $stmt = $this->db->query(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'mq_family_aliases'
             LIMIT 1"
        );

        $this->familyAliasesTableExists = (bool)$stmt->fetchColumn();
        return $this->familyAliasesTableExists;
    }

    private function assertCategoryExists(int $categoryId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM mq_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['id'])) {
            throw new RuntimeException('category_id invalide');
        }
    }

    private function normalizeForCompare(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = preg_replace('/[^a-z0-9 ]/', '', $value) ?? '';
        return trim($value);
    }

    private function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower((string)$value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
