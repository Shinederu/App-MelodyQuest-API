<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$authVendorAutoload = __DIR__ . '/../../auth/vendor/autoload.php';
if (file_exists($authVendorAutoload)) {
    require_once $authVendorAutoload;

    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../auth');
        $dotenv->safeLoad();
    }
}

require_once __DIR__ . '/../services/DatabaseService.php';
require_once __DIR__ . '/../utils/youtube.php';

main($argv);

function main(array $argv): void
{
    $options = getopt('', ['file:', 'dry-run', 'created-by::', 'help']);

    if (isset($options['help'])) {
        printUsage();
        exit(0);
    }

    $filePath = isset($options['file']) ? trim((string)$options['file']) : '';
    if ($filePath === '') {
        printUsage();
        fwrite(STDERR, "\nMissing required option: --file\n");
        exit(1);
    }

    $createdBy = null;
    if (array_key_exists('created-by', $options) && $options['created-by'] !== false && $options['created-by'] !== '') {
        $createdBy = (int)$options['created-by'];
        if ($createdBy <= 0) {
            fwrite(STDERR, "Option --created-by must be a positive integer.\n");
            exit(1);
        }
    }

    try {
        $importer = new BlindtestCatalogImporter(
            DatabaseService::getInstance(),
            isset($options['dry-run']),
            $createdBy
        );

        $result = $importer->run($filePath);

        fwrite(STDOUT, sprintf(
            "Import %s.\n",
            $result['committed'] ? 'completed' : ($result['dry_run'] ? 'simulated' : 'rolled back')
        ));
        fwrite(STDOUT, sprintf("Source file: %s\n", $result['file']));
        fwrite(STDOUT, sprintf("Tracks parsed: %d\n", $result['stats']['source_tracks'] ?? 0));
        fwrite(STDOUT, sprintf("Track links parsed: %d\n", $result['stats']['source_links'] ?? 0));
        fwrite(STDOUT, sprintf("Categories created: %d\n", $result['stats']['categories_created'] ?? 0));
        fwrite(STDOUT, sprintf("Categories updated: %d\n", $result['stats']['categories_updated'] ?? 0));
        fwrite(STDOUT, sprintf("Families created: %d\n", $result['stats']['families_created'] ?? 0));
        fwrite(STDOUT, sprintf("Families updated: %d\n", $result['stats']['families_updated'] ?? 0));
        fwrite(STDOUT, sprintf("Aliases created: %d\n", $result['stats']['aliases_created'] ?? 0));
        fwrite(STDOUT, sprintf("Aliases updated: %d\n", $result['stats']['aliases_updated'] ?? 0));
        fwrite(STDOUT, sprintf("Aliases skipped: %d\n", $result['stats']['aliases_skipped'] ?? 0));
        fwrite(STDOUT, sprintf("Tracks created: %d\n", $result['stats']['tracks_created'] ?? 0));
        fwrite(STDOUT, sprintf("Tracks updated: %d\n", $result['stats']['tracks_updated'] ?? 0));
        fwrite(STDOUT, sprintf("Tracks unchanged: %d\n", $result['stats']['tracks_unchanged'] ?? 0));

        if (!empty($result['errors'])) {
            fwrite(STDOUT, "\nErrors:\n");
            foreach ($result['errors'] as $error) {
                fwrite(STDOUT, ' - ' . $error . "\n");
            }
        }

        exit(empty($result['errors']) ? 0 : 2);
    } catch (Throwable $e) {
        fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

function printUsage(): void
{
    $usage = <<<TXT
Usage:
  php melodyquest/scripts/import_blindtest_catalog.php --file="P:\\DEV\\Temp\\blindtest with cat.csv" [--dry-run] [--created-by=1]

Options:
  --file         Path to the blindtest export file containing 4 CSV sections
  --dry-run      Parse and resolve the import without writing to the database
  --created-by   Optional users.id stored on created rows and validation metadata
  --help         Show this help

TXT;

    fwrite(STDOUT, $usage);
}

final class BlindtestCatalogImporter
{
    private PDO $db;
    private bool $dryRun;
    private ?int $createdBy;
    private int $nextVirtualId = -1;
    private ?bool $familyAliasesTableExists = null;
    private ?bool $youtubeUrlColumnExists = null;
    private array $categoryBySlug = [];
    private array $familyByKey = [];
    private array $aliasesByFamilyId = [];
    private array $trackByVideoId = [];
    private array $stats = [
        'source_tracks' => 0,
        'source_links' => 0,
        'categories_created' => 0,
        'categories_updated' => 0,
        'families_created' => 0,
        'families_updated' => 0,
        'aliases_created' => 0,
        'aliases_updated' => 0,
        'aliases_skipped' => 0,
        'tracks_created' => 0,
        'tracks_updated' => 0,
        'tracks_unchanged' => 0,
    ];
    private array $errors = [];

    public function __construct(PDO $db, bool $dryRun = false, ?int $createdBy = null)
    {
        $this->db = $db;
        $this->dryRun = $dryRun;
        $this->createdBy = $createdBy;
    }

    public function run(string $filePath): array
    {
        $items = $this->parseSourceFile($filePath);
        $this->stats['source_tracks'] = count($items);
        $this->stats['source_links'] = count($items);

        if ($this->createdBy !== null) {
            $this->assertUserExists($this->createdBy);
        }

        $this->primeExistingState($items);

        $committed = false;
        if (!$this->dryRun) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($items as $item) {
                $this->importTrackItem($item);
            }

            if (!$this->dryRun) {
                if (empty($this->errors)) {
                    $this->db->commit();
                    $committed = true;
                } elseif ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            }
        } catch (Throwable $e) {
            if (!$this->dryRun && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'file' => $filePath,
            'dry_run' => $this->dryRun,
            'committed' => $committed,
            'stats' => $this->stats,
            'errors' => $this->errors,
        ];
    }

    private function parseSourceFile(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Source file not found: ' . $filePath);
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || empty($lines)) {
            throw new RuntimeException('Unable to read source file: ' . $filePath);
        }

        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$lines[0]) ?? (string)$lines[0];

        $markers = [
            '"id","name","playlist_group_id"',
            '"id","name","icon"',
            '"playlist_id","track_id"',
            '"id","title","artist","year","season","youtube_url","info","alternative_title","preview_start_seconds","reveal_start_seconds","is_validated"',
        ];

        $sections = [];
        $current = [];
        foreach ($lines as $line) {
            if (in_array($line, $markers, true)) {
                if (!empty($current)) {
                    $sections[] = $current;
                }
                $current = [$line];
                continue;
            }

            if (!empty($current)) {
                $current[] = $line;
            }
        }

        if (!empty($current)) {
            $sections[] = $current;
        }

        if (count($sections) !== 4) {
            throw new RuntimeException('Expected 4 CSV sections in the export file.');
        }

        [$playlistSection, $groupSection, $linkSection, $trackSection] = $sections;
        $playlists = $this->parseCsvSection($playlistSection);
        $groups = $this->parseCsvSection($groupSection);
        $links = $this->parseCsvSection($linkSection);
        $tracks = $this->parseCsvSection($trackSection);

        $playlistById = [];
        foreach ($playlists as $playlist) {
            $playlistId = trim((string)($playlist['id'] ?? ''));
            if ($playlistId !== '') {
                $playlistById[$playlistId] = $playlist;
            }
        }

        $groupById = [];
        foreach ($groups as $group) {
            $groupId = trim((string)($group['id'] ?? ''));
            if ($groupId !== '') {
                $groupById[$groupId] = $group;
            }
        }

        $trackById = [];
        foreach ($tracks as $track) {
            $trackId = trim((string)($track['id'] ?? ''));
            if ($trackId !== '') {
                $trackById[$trackId] = $track;
            }
        }

        $playlistIdsByTrackId = [];
        foreach ($links as $link) {
            $playlistId = trim((string)($link['playlist_id'] ?? ''));
            $trackId = trim((string)($link['track_id'] ?? ''));
            if ($playlistId === '' || $trackId === '') {
                continue;
            }

            if (!isset($playlistById[$playlistId])) {
                throw new RuntimeException(sprintf('Unknown playlist_id %s referenced by track %s.', $playlistId, $trackId));
            }

            if (!isset($trackById[$trackId])) {
                throw new RuntimeException(sprintf('Unknown track_id %s referenced by playlist %s.', $trackId, $playlistId));
            }

            $playlistIdsByTrackId[$trackId] ??= [];
            $playlistIdsByTrackId[$trackId][$playlistId] = $playlistId;
        }

        $items = [];
        foreach ($trackById as $trackId => $track) {
            $playlistIds = array_values($playlistIdsByTrackId[$trackId] ?? []);
            if (count($playlistIds) !== 1) {
                throw new RuntimeException(sprintf(
                    'Track %s must map to exactly one playlist, got %d.',
                    $trackId,
                    count($playlistIds)
                ));
            }

            $playlist = $playlistById[$playlistIds[0]];
            $groupId = trim((string)($playlist['playlist_group_id'] ?? ''));
            $group = $groupById[$groupId] ?? null;
            if (!$group) {
                throw new RuntimeException(sprintf('Unknown playlist_group_id %s for playlist %s.', $groupId, $playlistIds[0]));
            }

            $youtubeVideoId = mq_normalize_youtube_video_id((string)($track['youtube_url'] ?? ''));
            if ($youtubeVideoId === '') {
                $this->errors[] = sprintf('Track %s has an invalid YouTube video id.', $trackId);
                continue;
            }

            $items[] = [
                'source_track_id' => $trackId,
                'group_name' => $this->cleanRequiredString($group['name'] ?? '', 'group name', $trackId),
                'playlist_name' => $this->cleanRequiredString($playlist['name'] ?? '', 'playlist name', $trackId),
                'family_name' => $this->cleanRequiredString($track['title'] ?? '', 'track title', $trackId),
                'artist' => $this->cleanNullableString($track['artist'] ?? null),
                'year' => $this->cleanNullableString($track['year'] ?? null),
                'season' => $this->cleanNullableString($track['season'] ?? null),
                'info' => $this->cleanNullableString($track['info'] ?? null),
                'alternative_title' => $this->cleanNullableString($track['alternative_title'] ?? null),
                'youtube_video_id' => $youtubeVideoId,
                'preview_start_seconds' => $this->parseNullableInteger($track['preview_start_seconds'] ?? null),
                'is_validated' => $this->parseBooleanFlag($track['is_validated'] ?? null),
            ];
        }

        if (!empty($this->errors)) {
            throw new RuntimeException('The source file contains invalid rows.');
        }

        return $items;
    }

    private function parseCsvSection(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        $header = str_getcsv((string)$lines[0], ',', '"', '\\');
        $rows = [];
        for ($index = 1; $index < count($lines); $index++) {
            $line = (string)$lines[$index];
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($header)) {
                throw new RuntimeException(sprintf(
                    'Malformed CSV row in section "%s" at offset %d.',
                    implode(', ', $header),
                    $index + 1
                ));
            }

            $rows[] = array_combine($header, $values);
        }

        return $rows;
    }

    private function primeExistingState(array $items): void
    {
        $categoryStmt = $this->db->query('SELECT id, name, slug, is_active FROM mq_categories');
        foreach ($categoryStmt->fetchAll() as $row) {
            $slug = (string)($row['slug'] ?? '');
            if ($slug !== '') {
                $this->categoryBySlug[$slug] = [
                    'id' => (int)$row['id'],
                    'name' => (string)($row['name'] ?? ''),
                    'is_active' => (int)($row['is_active'] ?? 0),
                ];
            }
        }

        $familyStmt = $this->db->query('SELECT id, category_id, name, slug, is_active FROM mq_families');
        foreach ($familyStmt->fetchAll() as $row) {
            $key = $this->buildFamilyKey((int)$row['category_id'], (string)($row['slug'] ?? ''));
            $this->familyByKey[$key] = [
                'id' => (int)$row['id'],
                'category_id' => (int)$row['category_id'],
                'name' => (string)($row['name'] ?? ''),
                'is_active' => (int)($row['is_active'] ?? 0),
            ];
        }

        $videoIds = array_values(array_unique(array_map(
            static fn(array $item): string => (string)$item['youtube_video_id'],
            $items
        )));

        if (empty($videoIds)) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($videoIds as $index => $videoId) {
            $key = 'video_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $videoId;
        }

        $trackStmt = $this->db->prepare(
            'SELECT id, family_id, title, artist, youtube_video_id, start_offset_seconds, is_active, is_validated, validated_at
             FROM mq_tracks
             WHERE youtube_video_id IN (' . implode(', ', $placeholders) . ')'
        );
        $trackStmt->execute($params);

        foreach ($trackStmt->fetchAll() as $row) {
            $videoId = mq_normalize_youtube_video_id((string)($row['youtube_video_id'] ?? ''));
            if ($videoId === '') {
                continue;
            }

            $this->trackByVideoId[$videoId] = [
                'id' => (int)$row['id'],
                'family_id' => (int)$row['family_id'],
                'title' => (string)($row['title'] ?? ''),
                'artist' => $this->cleanNullableString($row['artist'] ?? null),
                'start_offset_seconds' => (int)($row['start_offset_seconds'] ?? 0),
                'is_active' => (int)($row['is_active'] ?? 0),
                'is_validated' => (int)($row['is_validated'] ?? 0),
                'validated_at' => $this->cleanNullableString($row['validated_at'] ?? null),
            ];
        }
    }

    private function importTrackItem(array $item): void
    {
        $categoryId = $this->ensureCategory((string)$item['group_name']);
        $familyId = $this->ensureFamily($categoryId, (string)$item['family_name']);

        if (is_string($item['alternative_title']) && $item['alternative_title'] !== '') {
            $this->ensureFamilyAlias($familyId, (string)$item['family_name'], (string)$item['alternative_title']);
        }

        $this->upsertTrack($familyId, $item);
    }

    private function ensureCategory(string $name): int
    {
        $normalizedName = $this->truncate($this->normalizeSpaces($name), 120);
        $slug = $this->slugify($normalizedName);
        if ($normalizedName === '' || $slug === '') {
            throw new RuntimeException('Unable to resolve category name.');
        }

        $existing = $this->categoryBySlug[$slug] ?? null;
        if ($existing) {
            $needsUpdate = $existing['name'] !== $normalizedName || (int)$existing['is_active'] !== 1;
            if ($needsUpdate) {
                if (!$this->dryRun) {
                    $stmt = $this->db->prepare(
                        'UPDATE mq_categories
                         SET name = :name,
                             is_active = 1
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'name' => $normalizedName,
                        'id' => (int)$existing['id'],
                    ]);
                }

                $existing['name'] = $normalizedName;
                $existing['is_active'] = 1;
                $this->categoryBySlug[$slug] = $existing;
                $this->stats['categories_updated']++;
            }

            return (int)$existing['id'];
        }

        if ($this->dryRun) {
            $id = $this->allocateVirtualId();
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO mq_categories (name, slug, is_active, created_by)
                 VALUES (:name, :slug, 1, :created_by)'
            );
            $stmt->execute([
                'name' => $normalizedName,
                'slug' => $slug,
                'created_by' => $this->createdBy,
            ]);
            $id = (int)$this->db->lastInsertId();
        }

        $this->categoryBySlug[$slug] = [
            'id' => $id,
            'name' => $normalizedName,
            'is_active' => 1,
        ];
        $this->stats['categories_created']++;

        return $id;
    }

    private function ensureFamily(int $categoryId, string $name): int
    {
        $normalizedName = $this->truncate($this->normalizeSpaces($name), 140);
        $slug = $this->slugify($normalizedName);
        if ($categoryId === 0 || $normalizedName === '' || $slug === '') {
            throw new RuntimeException('Unable to resolve family name.');
        }

        $key = $this->buildFamilyKey($categoryId, $slug);
        $existing = $this->familyByKey[$key] ?? null;
        if ($existing) {
            $needsUpdate = $existing['name'] !== $normalizedName || (int)$existing['is_active'] !== 1;
            if ($needsUpdate) {
                if (!$this->dryRun) {
                    $stmt = $this->db->prepare(
                        'UPDATE mq_families
                         SET name = :name,
                             is_active = 1
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'name' => $normalizedName,
                        'id' => (int)$existing['id'],
                    ]);
                }

                $existing['name'] = $normalizedName;
                $existing['is_active'] = 1;
                $this->familyByKey[$key] = $existing;
                $this->stats['families_updated']++;
            }

            return (int)$existing['id'];
        }

        if ($this->dryRun) {
            $id = $this->allocateVirtualId();
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO mq_families (category_id, name, slug, description, is_active, created_by)
                 VALUES (:category_id, :name, :slug, NULL, 1, :created_by)'
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'name' => $normalizedName,
                'slug' => $slug,
                'created_by' => $this->createdBy,
            ]);
            $id = (int)$this->db->lastInsertId();
        }

        $this->familyByKey[$key] = [
            'id' => $id,
            'category_id' => $categoryId,
            'name' => $normalizedName,
            'is_active' => 1,
        ];
        $this->stats['families_created']++;

        return $id;
    }

    private function ensureFamilyAlias(int $familyId, string $familyName, string $alias): void
    {
        if (!$this->hasFamilyAliasesTable()) {
            $this->stats['aliases_skipped']++;
            return;
        }

        $normalizedAlias = $this->truncate($this->normalizeSpaces($alias), 160);
        $slug = $this->slugify($normalizedAlias);
        if ($normalizedAlias === '' || $slug === '') {
            return;
        }

        if ($this->normalizeForCompare($normalizedAlias) === $this->normalizeForCompare($familyName)) {
            return;
        }

        $this->loadFamilyAliases($familyId);

        $existing = $this->aliasesByFamilyId[$familyId][$slug] ?? null;
        if ($existing !== null) {
            if ($existing !== $normalizedAlias) {
                if (!$this->dryRun) {
                    $stmt = $this->db->prepare(
                        'UPDATE mq_family_aliases
                         SET alias = :alias
                         WHERE family_id = :family_id
                           AND slug = :slug'
                    );
                    $stmt->execute([
                        'alias' => $normalizedAlias,
                        'family_id' => $familyId,
                        'slug' => $slug,
                    ]);
                }

                $this->aliasesByFamilyId[$familyId][$slug] = $normalizedAlias;
                $this->stats['aliases_updated']++;
            }
            return;
        }

        if (!$this->dryRun) {
            $stmt = $this->db->prepare(
                'INSERT INTO mq_family_aliases (family_id, alias, slug, created_by)
                 VALUES (:family_id, :alias, :slug, :created_by)'
            );
            $stmt->execute([
                'family_id' => $familyId,
                'alias' => $normalizedAlias,
                'slug' => $slug,
                'created_by' => $this->createdBy,
            ]);
        }

        $this->aliasesByFamilyId[$familyId][$slug] = $normalizedAlias;
        $this->stats['aliases_created']++;
    }

    private function upsertTrack(int $familyId, array $item): void
    {
        $videoId = (string)$item['youtube_video_id'];
        $trackTitle = $this->buildTrackTitle($item);
        $artist = is_string($item['artist']) ? $this->truncate($item['artist'], 220) : null;
        $startOffset = max(0, (int)($item['preview_start_seconds'] ?? 0));
        $sourceValidated = !empty($item['is_validated']);

        $existing = $this->trackByVideoId[$videoId] ?? null;
        if ($existing) {
            $needsFieldUpdate = (int)$existing['family_id'] !== $familyId
                || (string)$existing['title'] !== $trackTitle
                || $this->nullableStringsDiffer($existing['artist'], $artist)
                || (int)$existing['start_offset_seconds'] !== $startOffset
                || (int)$existing['is_active'] !== 1;
            $promoteValidation = $sourceValidated && (int)$existing['is_validated'] !== 1;

            if (!$needsFieldUpdate && !$promoteValidation) {
                $this->stats['tracks_unchanged']++;
                return;
            }

            if (!$this->dryRun) {
                $sets = [
                    'family_id = :family_id',
                    'title = :title',
                    'artist = :artist',
                    'start_offset_seconds = :start_offset_seconds',
                    'is_active = 1',
                ];
                $params = [
                    'id' => (int)$existing['id'],
                    'family_id' => $familyId,
                    'title' => $trackTitle,
                    'artist' => $artist,
                    'start_offset_seconds' => $startOffset,
                ];

                if ($this->hasYoutubeUrlColumn()) {
                    $sets[] = 'youtube_url = :youtube_url';
                    $params['youtube_url'] = mq_build_youtube_watch_url($videoId);
                }

                if ($promoteValidation) {
                    $sets[] = 'is_validated = 1';
                    $sets[] = 'validated_at = COALESCE(validated_at, NOW())';
                    if ($this->createdBy !== null) {
                        $sets[] = 'validated_by = COALESCE(validated_by, :validated_by)';
                        $params['validated_by'] = $this->createdBy;
                    }
                }

                $stmt = $this->db->prepare(
                    'UPDATE mq_tracks
                     SET ' . implode(', ', $sets) . '
                     WHERE id = :id'
                );
                $stmt->execute($params);
            }

            $existing['family_id'] = $familyId;
            $existing['title'] = $trackTitle;
            $existing['artist'] = $artist;
            $existing['start_offset_seconds'] = $startOffset;
            $existing['is_active'] = 1;
            if ($promoteValidation) {
                $existing['is_validated'] = 1;
            }
            $this->trackByVideoId[$videoId] = $existing;
            $this->stats['tracks_updated']++;
            return;
        }

        if ($this->dryRun) {
            $id = $this->allocateVirtualId();
        } else {
            $fields = [
                'family_id',
                'title',
                'artist',
                'youtube_video_id',
                'duration_seconds',
                'start_offset_seconds',
                'end_offset_seconds',
                'is_active',
                'is_validated',
                'validated_by',
                'validated_at',
                'created_by',
            ];
            $params = [
                'family_id' => $familyId,
                'title' => $trackTitle,
                'artist' => $artist,
                'youtube_video_id' => $videoId,
                'duration_seconds' => null,
                'start_offset_seconds' => $startOffset,
                'end_offset_seconds' => null,
                'is_active' => 1,
                'is_validated' => $sourceValidated ? 1 : 0,
                'validated_by' => $sourceValidated ? $this->createdBy : null,
                'validated_at' => $sourceValidated ? date('Y-m-d H:i:s') : null,
                'created_by' => $this->createdBy,
            ];

            if ($this->hasYoutubeUrlColumn()) {
                array_splice($fields, 3, 0, ['youtube_url']);
                $params['youtube_url'] = mq_build_youtube_watch_url($videoId);
            }

            $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);
            $stmt = $this->db->prepare(
                'INSERT INTO mq_tracks (' . implode(', ', $fields) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);
            $id = (int)$this->db->lastInsertId();
        }

        $this->trackByVideoId[$videoId] = [
            'id' => $id,
            'family_id' => $familyId,
            'title' => $trackTitle,
            'artist' => $artist,
            'start_offset_seconds' => $startOffset,
            'is_active' => 1,
            'is_validated' => $sourceValidated ? 1 : 0,
            'validated_at' => $sourceValidated ? date('Y-m-d H:i:s') : null,
        ];
        $this->stats['tracks_created']++;
    }

    private function buildTrackTitle(array $item): string
    {
        $parts = [$this->normalizeSpaces((string)$item['playlist_name'])];

        if (is_string($item['season']) && $item['season'] !== '') {
            $parts[] = 'Saison ' . (preg_replace('/[^0-9A-Za-z_-]+/', '', $item['season']) ?? $item['season']);
        }

        if (is_string($item['year']) && $item['year'] !== '') {
            $parts[] = preg_replace('/[^0-9A-Za-z_-]+/', '', $item['year']) ?? $item['year'];
        }

        if (is_string($item['info']) && $item['info'] !== '') {
            $parts[] = $this->normalizeSpaces($item['info']);
        }

        $parts = array_values(array_filter(array_unique($parts), static fn(string $value): bool => $value !== ''));
        $label = implode(' - ', $parts);
        if ($label === '') {
            $label = 'Importe';
        }

        return $this->truncate($label, 220);
    }

    private function assertUserExists(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException(sprintf('User %d does not exist.', $userId));
        }
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

    private function hasYoutubeUrlColumn(): bool
    {
        if ($this->youtubeUrlColumnExists !== null) {
            return $this->youtubeUrlColumnExists;
        }

        $stmt = $this->db->query(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'mq_tracks'
               AND column_name = 'youtube_url'
             LIMIT 1"
        );

        $this->youtubeUrlColumnExists = (bool)$stmt->fetchColumn();
        return $this->youtubeUrlColumnExists;
    }

    private function loadFamilyAliases(int $familyId): void
    {
        if (array_key_exists($familyId, $this->aliasesByFamilyId)) {
            return;
        }

        $this->aliasesByFamilyId[$familyId] = [];
        if ($familyId <= 0 || !$this->hasFamilyAliasesTable() || ($this->dryRun && $familyId < 0)) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT slug, alias
             FROM mq_family_aliases
             WHERE family_id = :family_id'
        );
        $stmt->execute(['family_id' => $familyId]);

        foreach ($stmt->fetchAll() as $row) {
            $slug = (string)($row['slug'] ?? '');
            $alias = (string)($row['alias'] ?? '');
            if ($slug !== '' && $alias !== '') {
                $this->aliasesByFamilyId[$familyId][$slug] = $alias;
            }
        }
    }

    private function buildFamilyKey(int $categoryId, string $slug): string
    {
        return $categoryId . ':' . $slug;
    }

    private function cleanRequiredString($value, string $label, string $trackId): string
    {
        $cleaned = $this->cleanNullableString($value);
        if ($cleaned === null || $cleaned === '') {
            throw new RuntimeException(sprintf('Missing %s for source track %s.', $label, $trackId));
        }

        return $cleaned;
    }

    private function cleanNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim((string)$value);
        if ($cleaned === '' || strtoupper($cleaned) === 'NULL') {
            return null;
        }

        return $this->normalizeSpaces($cleaned);
    }

    private function parseNullableInteger($value): ?int
    {
        $cleaned = $this->cleanNullableString($value);
        if ($cleaned === null || !is_numeric($cleaned)) {
            return null;
        }

        return (int)$cleaned;
    }

    private function parseBooleanFlag($value): bool
    {
        $cleaned = $this->cleanNullableString($value);
        if ($cleaned === null) {
            return false;
        }

        return in_array(strtolower($cleaned), ['1', 'true', 'yes', 'y'], true);
    }

    private function normalizeSpaces(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        return trim((string)$normalized);
    }

    private function normalizeForCompare(string $value): string
    {
        $normalized = strtolower(trim($this->toAscii($value)));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/[^a-z0-9 ]/', '', $normalized) ?? '';
        return trim($normalized);
    }

    private function slugify(string $value): string
    {
        $normalized = strtolower($this->toAscii($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }

    private function toAscii(string $value): string
    {
        if (class_exists('Transliterator')) {
            $converted = transliterator_transliterate(
                'Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC',
                $value
            );
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        return $value;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $maxLength) {
                return $value;
            }
            return mb_substr($value, 0, $maxLength);
        }

        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }

    private function nullableStringsDiffer(?string $left, ?string $right): bool
    {
        return ($left ?? null) !== ($right ?? null);
    }

    private function allocateVirtualId(): int
    {
        return $this->nextVirtualId--;
    }
}
