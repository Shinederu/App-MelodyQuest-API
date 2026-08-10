<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['env-dir::', 'db-host::']);
$envDirectory = trim((string)($options['env-dir'] ?? ''));
if ($envDirectory !== '') {
    loadEnvironment($envDirectory);
}

$dbHost = trim((string)($options['db-host'] ?? ''));
if ($dbHost !== '') {
    $_ENV['MQ_DB_HOST'] = $dbHost;
}

require_once __DIR__ . '/../services/DatabaseService.php';
require_once __DIR__ . '/../services/GameSessionService.php';
require_once __DIR__ . '/../repositories/PdoGameSessionRepository.php';

$db = DatabaseService::getInstance();
$service = new GameSessionService(new PdoGameSessionRepository($db));

$lobbies = $db->query(
    'SELECT l.id, l.status
     FROM mq_lobbies l
     WHERE l.status IN ("finished", "closed")
       AND EXISTS (
           SELECT 1
           FROM mq_rounds r
           WHERE r.lobby_id = l.id
       )
     ORDER BY l.id ASC'
)->fetchAll();

$before = (int)$db->query('SELECT COUNT(*) FROM mq_game_sessions')->fetchColumn();
$processed = 0;
$failures = [];

foreach ($lobbies as $lobby) {
    $lobbyId = (int)$lobby['id'];
    $completionStatus = strtolower((string)$lobby['status']) === 'finished'
        ? 'finished'
        : 'cancelled';

    try {
        $db->beginTransaction();
        $service->archiveLobbyGame($lobbyId, $completionStatus);
        $db->commit();
        $processed++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $failures[] = sprintf('Lobby %d: %s', $lobbyId, $e->getMessage());
    }
}

$after = (int)$db->query('SELECT COUNT(*) FROM mq_game_sessions')->fetchColumn();
$created = max(0, $after - $before);
$alreadyArchived = max(0, $processed - $created);

printf(
    "Backfill terminé: %d salon(s) traité(s), %d session(s) créée(s), %d déjà archivée(s).\n",
    $processed,
    $created,
    $alreadyArchived
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

function loadEnvironment(string $directory): void
{
    if (!is_dir($directory)) {
        throw new RuntimeException("Dossier d'environnement introuvable: {$directory}");
    }

    $autoload = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('Dotenv\\Dotenv')) {
        throw new RuntimeException("phpdotenv est introuvable dans le dossier d'environnement");
    }

    Dotenv\Dotenv::createImmutable($directory)->safeLoad();
}
