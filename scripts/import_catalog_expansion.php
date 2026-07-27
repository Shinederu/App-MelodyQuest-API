<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'file:',
    'env-dir::',
    'db-host::',
    'created-by::',
    'dry-run',
    'aliases-only',
    'help',
]);
if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$filePath = trim((string)($options['file'] ?? ''));
if ($filePath === '' || !is_file($filePath)) {
    printUsage();
    fwrite(STDERR, "\nA readable --file is required.\n");
    exit(1);
}

$envDirectory = trim((string)($options['env-dir'] ?? ''));
if ($envDirectory !== '') {
    loadEnvironment($envDirectory);
}

$dbHost = trim((string)($options['db-host'] ?? ''));
if ($dbHost !== '') {
    $_ENV['MQ_DB_HOST'] = $dbHost;
}

$createdBy = null;
if (isset($options['created-by']) && $options['created-by'] !== false) {
    $createdBy = filter_var(
        $options['created-by'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($createdBy === false) {
        throw new InvalidArgumentException('--created-by must be a positive integer.');
    }
}

require_once __DIR__ . '/../services/DatabaseService.php';

$payload = json_decode(
    (string)file_get_contents($filePath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$items = flattenManifest($payload);
$dryRun = isset($options['dry-run']);
$aliasesOnly = isset($options['aliases-only']);
$db = DatabaseService::getInstance();

if ($createdBy !== null) {
    $stmt = $db->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $createdBy]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException("Unknown users.id: {$createdBy}");
    }
}

$stats = [
    'source_tracks' => count($items),
    'families_created' => 0,
    'families_reused' => 0,
    'aliases_created' => 0,
    'aliases_skipped' => 0,
    'tracks_created' => 0,
];

$db->beginTransaction();
try {
    $categoryIds = loadCategoryIds($db);
    $existingVideoIds = loadExistingVideoIds($db);
    $manifestVideoIds = [];
    $familyCache = [];
    $aliasCache = [];
    $hasYoutubeUrlColumn = columnExists($db, 'mq_tracks', 'youtube_url');
    $trackFields = [
        'family_id',
        'title',
        'artist',
        'youtube_video_id',
    ];
    $trackValues = [
        ':family_id',
        ':title',
        ':artist',
        ':youtube_video_id',
    ];
    if ($hasYoutubeUrlColumn) {
        $trackFields[] = 'youtube_url';
        $trackValues[] = ':youtube_url';
    }
    $trackFields = array_merge(
        $trackFields,
        [
            'duration_seconds',
            'start_offset_seconds',
            'end_offset_seconds',
            'is_active',
            'is_validated',
            'validated_by',
            'validated_at',
            'created_by',
        ]
    );
    $trackValues = array_merge(
        $trackValues,
        [
            ':duration_seconds',
            ':start_offset_seconds',
            ':end_offset_seconds',
            '1',
            '1',
            ':validated_by',
            'NOW()',
            ':created_by',
        ]
    );
    $trackInsertStmt = $db->prepare(
        'INSERT INTO mq_tracks (' . implode(', ', $trackFields) . ')
         VALUES (' . implode(', ', $trackValues) . ')'
    );

    foreach ($items as $index => $item) {
        validateItem($item, $index);
        $videoId = $item['youtube_video_id'];
        if (!$aliasesOnly && isset($manifestVideoIds[$videoId])) {
            throw new RuntimeException(
                "Duplicate video ID in manifest: {$videoId}"
            );
        }
        if (!$aliasesOnly && isset($existingVideoIds[$videoId])) {
            throw new RuntimeException(
                "Video ID already exists in database: {$videoId}"
            );
        }
        if (!$aliasesOnly) {
            $manifestVideoIds[$videoId] = true;
        }

        $categorySlug = $item['category_slug'];
        if (!isset($categoryIds[$categorySlug])) {
            throw new RuntimeException(
                "Unknown category slug: {$categorySlug}"
            );
        }
        $categoryId = $categoryIds[$categorySlug];
        $familySlug = slugify($item['family']);
        $familyKey = "{$categoryId}:{$familySlug}";

        if (!isset($familyCache[$familyKey])) {
            $familyId = findFamilyId($db, $categoryId, $familySlug);
            if ($familyId === null) {
                if ($aliasesOnly) {
                    throw new RuntimeException(
                        "Family missing during alias-only import: "
                        . "{$categorySlug}/{$familySlug}"
                    );
                }
                $stmt = $db->prepare(
                    'INSERT INTO mq_families
                        (category_id, name, slug, is_active, created_by)
                     VALUES
                        (:category_id, :name, :slug, 1, :created_by)'
                );
                $stmt->execute([
                    'category_id' => $categoryId,
                    'name' => $item['family'],
                    'slug' => $familySlug,
                    'created_by' => $createdBy,
                ]);
                $familyId = (int)$db->lastInsertId();
                $stats['families_created']++;
            } else {
                $stats['families_reused']++;
            }
            $familyCache[$familyKey] = $familyId;
            $aliasCache[$familyId] = loadAliasSlugs($db, $familyId);
        }
        $familyId = $familyCache[$familyKey];

        foreach ($item['aliases'] as $alias) {
            $alias = trim((string)$alias);
            $aliasSlug = slugify($alias);
            if (
                $alias === ''
                || $aliasSlug === ''
                || $aliasSlug === $familySlug
                || isset($aliasCache[$familyId][$aliasSlug])
            ) {
                $stats['aliases_skipped']++;
                continue;
            }
            $stmt = $db->prepare(
                'INSERT INTO mq_family_aliases
                    (family_id, alias, slug, created_by)
                 VALUES
                    (:family_id, :alias, :slug, :created_by)'
            );
            $stmt->execute([
                'family_id' => $familyId,
                'alias' => $alias,
                'slug' => $aliasSlug,
                'created_by' => $createdBy,
            ]);
            $aliasCache[$familyId][$aliasSlug] = true;
            $stats['aliases_created']++;
        }

        if ($aliasesOnly) {
            continue;
        }

        $trackParameters = [
            'family_id' => $familyId,
            'title' => $item['track_label'],
            'artist' => $item['artist'],
            'youtube_video_id' => $videoId,
            'duration_seconds' => $item['duration_seconds'],
            'start_offset_seconds' => $item['start_offset_seconds'],
            'end_offset_seconds' => $item['end_offset_seconds'],
            'validated_by' => $createdBy,
            'created_by' => $createdBy,
        ];
        if ($hasYoutubeUrlColumn) {
            $trackParameters['youtube_url'] = (
                $item['youtube_url']
                ?? 'https://www.youtube.com/watch?v=' . $videoId
            );
        }
        $trackInsertStmt->execute($trackParameters);
        $stats['tracks_created']++;
    }

    if ($dryRun) {
        $db->rollBack();
    } else {
        $db->commit();
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}

fwrite(
    STDOUT,
    json_encode(
        [
            'mode' => $dryRun ? 'dry-run' : 'committed',
            'operation' => $aliasesOnly ? 'aliases-only' : 'full-import',
            'file' => realpath($filePath) ?: $filePath,
            'stats' => $stats,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL
);

function flattenManifest(array $payload): array
{
    $selected = $payload['selected'] ?? null;
    if (!is_array($selected)) {
        throw new RuntimeException('Manifest must contain a selected object.');
    }

    $items = [];
    foreach ($selected as $categorySlug => $rows) {
        if (!is_array($rows)) {
            throw new RuntimeException(
                "Manifest category {$categorySlug} must be an array."
            );
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException(
                    "Invalid row in category {$categorySlug}."
                );
            }
            $row['category_slug'] = (string)$categorySlug;
            $row['aliases'] = array_values(
                array_filter(
                    is_array($row['aliases'] ?? null) ? $row['aliases'] : [],
                    'is_string'
                )
            );
            $row['duration_seconds'] = normalizeNullableInt(
                $row['duration_seconds'] ?? $row['youtube_duration'] ?? null
            );
            $row['start_offset_seconds'] = max(
                0,
                (int)($row['start_offset_seconds'] ?? 0)
            );
            $row['end_offset_seconds'] = normalizeNullableInt(
                $row['end_offset_seconds'] ?? null
            );
            $items[] = $row;
        }
    }
    return $items;
}

function validateItem(array $item, int $index): void
{
    foreach ([
        'category_slug',
        'family',
        'track_label',
        'artist',
        'youtube_video_id',
    ] as $field) {
        if (trim((string)($item[$field] ?? '')) === '') {
            throw new RuntimeException(
                "Manifest row {$index} has an empty {$field}."
            );
        }
    }
    if (!preg_match(
        '/^[A-Za-z0-9_-]{11}$/',
        (string)$item['youtube_video_id']
    )) {
        throw new RuntimeException(
            "Manifest row {$index} has an invalid YouTube video ID."
        );
    }
    if (
        $item['end_offset_seconds'] !== null
        && $item['end_offset_seconds'] <= $item['start_offset_seconds']
    ) {
        throw new RuntimeException(
            "Manifest row {$index} has invalid offsets."
        );
    }
}

function loadCategoryIds(PDO $db): array
{
    $output = [];
    foreach ($db->query('SELECT id, slug FROM mq_categories') as $row) {
        $output[(string)$row['slug']] = (int)$row['id'];
    }
    return $output;
}

function loadExistingVideoIds(PDO $db): array
{
    $output = [];
    foreach ($db->query('SELECT youtube_video_id FROM mq_tracks') as $row) {
        $output[(string)$row['youtube_video_id']] = true;
    }
    return $output;
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return $stmt->fetchColumn() !== false;
}

function findFamilyId(PDO $db, int $categoryId, string $slug): ?int
{
    $stmt = $db->prepare(
        'SELECT id
         FROM mq_families
         WHERE category_id = :category_id AND slug = :slug
         LIMIT 1'
    );
    $stmt->execute([
        'category_id' => $categoryId,
        'slug' => $slug,
    ]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int)$value;
}

function loadAliasSlugs(PDO $db, int $familyId): array
{
    $stmt = $db->prepare(
        'SELECT slug FROM mq_family_aliases WHERE family_id = :family_id'
    );
    $stmt->execute(['family_id' => $familyId]);
    $output = [];
    foreach ($stmt as $row) {
        $output[(string)$row['slug']] = true;
    }
    return $output;
}

function normalizeNullableInt(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return max(0, (int)$value);
}

function slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $originalValue = $value;
    if (class_exists('Transliterator')) {
        $transliterator = Transliterator::create(
            'Any-Latin; Latin-ASCII; Lower();'
        );
        if ($transliterator !== null) {
            $value = $transliterator->transliterate($value);
        }
    } else {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $converted === false ? strtolower($value) : strtolower($converted);
    }
    $slug = trim(
        (string)preg_replace('/[^a-z0-9]+/', '-', $value),
        '-'
    );
    if ($slug === '') {
        return 'u-' . substr(sha1($originalValue), 0, 20);
    }
    return $slug;
}

function loadEnvironment(string $directory): void
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    $autoloadPath = $directory . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException(
            "Composer autoload not found in env directory: {$directory}"
        );
    }
    require_once $autoloadPath;
    if (!class_exists('Dotenv\\Dotenv')) {
        throw new RuntimeException('vlucas/phpdotenv is not available.');
    }
    Dotenv\Dotenv::createImmutable($directory)->safeLoad();
}

function printUsage(): void
{
    fwrite(
        STDOUT,
        "Usage: php import_catalog_expansion.php --file=<manifest.json> "
        . "[--env-dir=<directory>] [--db-host=<host>] "
        . "[--created-by=<users.id>] [--dry-run] [--aliases-only]\n"
    );
}
