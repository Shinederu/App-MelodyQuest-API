<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['output:', 'env-dir::', 'db-host::', 'help']);
if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$outputPath = trim((string)($options['output'] ?? ''));
if ($outputPath === '') {
    printUsage();
    fwrite(STDERR, "\nMissing required option: --output\n");
    exit(1);
}

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory)) {
    fwrite(STDERR, "Output directory does not exist: {$outputDirectory}\n");
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

require_once __DIR__ . '/../services/DatabaseService.php';

$db = DatabaseService::getInstance();
$categories = [];
$familyIndex = [];

$rows = $db->query(
    'SELECT c.id AS category_id,
            c.name AS category_name,
            c.slug AS category_slug,
            c.is_active AS category_is_active,
            f.id AS family_id,
            f.name AS family_name,
            f.slug AS family_slug,
            f.is_active AS family_is_active,
            t.id AS track_id,
            t.title AS track_title,
            t.artist,
            t.youtube_video_id,
            t.duration_seconds,
            t.start_offset_seconds,
            t.end_offset_seconds,
            t.is_active AS track_is_active,
            t.is_validated
     FROM mq_categories c
     LEFT JOIN mq_families f ON f.category_id = c.id
     LEFT JOIN mq_tracks t ON t.family_id = f.id
     ORDER BY c.id, f.id, t.id'
)->fetchAll();

foreach ($rows as $row) {
    $categoryId = (int)$row['category_id'];
    if (!isset($categories[$categoryId])) {
        $categories[$categoryId] = [
            'id' => $categoryId,
            'name' => (string)$row['category_name'],
            'slug' => (string)$row['category_slug'],
            'is_active' => (int)$row['category_is_active'],
            'families' => [],
        ];
    }

    if ($row['family_id'] === null) {
        continue;
    }

    $familyId = (int)$row['family_id'];
    if (!isset($familyIndex[$familyId])) {
        $categories[$categoryId]['families'][$familyId] = [
            'id' => $familyId,
            'name' => (string)$row['family_name'],
            'slug' => (string)$row['family_slug'],
            'is_active' => (int)$row['family_is_active'],
            'aliases' => [],
            'tracks' => [],
        ];
        $familyIndex[$familyId] = &$categories[$categoryId]['families'][$familyId];
    }

    if ($row['track_id'] !== null) {
        $familyIndex[$familyId]['tracks'][] = [
            'id' => (int)$row['track_id'],
            'title' => (string)$row['track_title'],
            'artist' => $row['artist'] !== null ? (string)$row['artist'] : null,
            'youtube_video_id' => (string)$row['youtube_video_id'],
            'duration_seconds' => $row['duration_seconds'] !== null ? (int)$row['duration_seconds'] : null,
            'start_offset_seconds' => (int)$row['start_offset_seconds'],
            'end_offset_seconds' => $row['end_offset_seconds'] !== null ? (int)$row['end_offset_seconds'] : null,
            'is_active' => (int)$row['track_is_active'],
            'is_validated' => (int)$row['is_validated'],
        ];
    }
}
unset($familyIndex);

$aliasRows = $db->query(
    'SELECT a.family_id, a.alias, a.slug
     FROM mq_family_aliases a
     ORDER BY a.family_id, a.id'
)->fetchAll();

$familyById = [];
foreach ($categories as &$category) {
    foreach ($category['families'] as &$family) {
        $familyById[$family['id']] = &$family;
    }
    unset($family);
}
unset($category);

foreach ($aliasRows as $aliasRow) {
    $familyId = (int)$aliasRow['family_id'];
    if (!isset($familyById[$familyId])) {
        continue;
    }
    $familyById[$familyId]['aliases'][] = [
        'alias' => (string)$aliasRow['alias'],
        'slug' => (string)$aliasRow['slug'],
    ];
}
unset($familyById);

$stats = [
    'categories' => count($categories),
    'families' => 0,
    'tracks' => 0,
    'aliases' => count($aliasRows),
    'validated_tracks' => 0,
    'pending_tracks' => 0,
];

foreach ($categories as &$category) {
    $category['families'] = array_values($category['families']);
    $stats['families'] += count($category['families']);
    foreach ($category['families'] as $family) {
        $stats['tracks'] += count($family['tracks']);
        foreach ($family['tracks'] as $track) {
            if ($track['is_validated'] === 1) {
                $stats['validated_tracks']++;
            } else {
                $stats['pending_tracks']++;
            }
        }
    }
}
unset($category);

$payload = [
    'exported_at' => date(DATE_ATOM),
    'stats' => $stats,
    'categories' => array_values($categories),
];

$json = json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if (!is_string($json)) {
    throw new RuntimeException('Unable to encode catalog export.');
}
if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
    throw new RuntimeException("Unable to write export: {$outputPath}");
}

fwrite(STDOUT, "Catalog exported.\n");
fwrite(STDOUT, "Output: {$outputPath}\n");
foreach ($stats as $key => $value) {
    fwrite(STDOUT, "{$key}={$value}\n");
}

function loadEnvironment(string $directory): void
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    $autoloadPath = $directory . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException("Composer autoload not found in env directory: {$directory}");
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
        "Usage: php export_catalog.php --output=<catalog.json> [--env-dir=<directory>] [--db-host=<host>]\n"
    );
}
