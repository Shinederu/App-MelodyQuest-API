<?php

// Source-only maintenance command. Never expose or deploy this script publicly.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['env-dir:', 'db-host:', 'database:', 'backup:', 'expected-count:', 'apply']);
try {
    $envDir = (string)($options['env-dir'] ?? '');
    if ($envDir !== '') {
        require rtrim($envDir, '/\\') . '/vendor/autoload.php';
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
    }
    if (isset($options['db-host'])) $_ENV['MQ_DB_HOST'] = $options['db-host'];
    require_once __DIR__ . '/../services/DatabaseService.php';
    require_once __DIR__ . '/lib/CatalogRecheck.php';
    $db = DatabaseService::getInstance();
    $database = $db->query('SELECT DATABASE()')->fetchColumn();
    if ($database !== ($options['database'] ?? '')) throw new RuntimeException('Specify the actual target with --database');
    $operation = new CatalogRecheck($db);
    $result = isset($options['apply'])
        ? $operation->apply((string)($options['backup'] ?? ''), (int)($options['expected-count'] ?? 0))
        : $operation->inspect();
    echo json_encode(['applied' => isset($options['apply']), 'database' => $database] + $result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    // Do not print a connection exception that could include runtime credentials.
    fwrite(STDERR, $error instanceof PDOException ? "Database operation failed; no credentials printed.\n" : $error->getMessage() . "\n");
    exit(1);
}
