<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../services/RealtimeOutboxService.php';

class InMemoryRealtimeOutboxRepository implements RealtimeOutboxRepository
{
    public array $rows = [];
    public array $retries = [];
    private int $nextId = 1;

    public function enqueue(
        string $streamKey,
        string $eventKind,
        ?int $lobbyId,
        ?string $lobbyCode,
        ?array $payload
    ): void {
        $existing = $this->rows[$streamKey] ?? null;
        $this->rows[$streamKey] = [
            'id' => $existing['id'] ?? $this->nextId++,
            'stream_key' => $streamKey,
            'event_kind' => $eventKind,
            'lobby_id' => $lobbyId,
            'lobby_code' => $lobbyCode,
            'payload' => $payload,
            'generation' => (int)($existing['generation'] ?? 0) + 1,
            'attempts' => 0,
            'locked' => (bool)($existing['locked'] ?? false),
        ];
    }

    public function claimNext(int $lockTimeoutSeconds): ?array
    {
        foreach ($this->rows as $streamKey => $row) {
            if (!empty($row['locked'])) {
                continue;
            }
            $this->rows[$streamKey]['locked'] = true;
            $this->rows[$streamKey]['attempts']++;
            $row['attempts']++;
            return $row;
        }

        return null;
    }

    public function acknowledge(int $id, int $generation): void
    {
        foreach ($this->rows as $streamKey => $row) {
            if ((int)$row['id'] !== $id) {
                continue;
            }
            if ((int)$row['generation'] === $generation) {
                unset($this->rows[$streamKey]);
            } else {
                $this->rows[$streamKey]['locked'] = false;
            }
            return;
        }
    }

    public function retry(int $id, int $generation, int $delayMilliseconds, string $error): void
    {
        $this->retries[] = compact('id', 'generation', 'delayMilliseconds', 'error');
        foreach ($this->rows as $streamKey => $row) {
            if ((int)$row['id'] === $id) {
                $this->rows[$streamKey]['locked'] = false;
            }
        }
    }

    public function countPending(): int
    {
        return count($this->rows);
    }
}

class FakeRealtimeMercureService extends MercureService
{
    public array $publications = [];
    public int $failuresRemaining = 0;

    public function canPublish(): bool
    {
        return true;
    }

    public function publish(
        string $topic,
        array $payload,
        bool $private,
        string $eventType,
        ?string $eventId = null
    ): bool {
        $this->publications[] = compact('topic', 'payload', 'private', 'eventType', 'eventId');
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            return false;
        }
        return true;
    }
}

class FakeRealtimeLobbyService extends LobbyService
{
    public array $codes = [42 => 'ABCD1234'];

    public function __construct()
    {
    }

    public function getLobbyCodeById(int $lobbyId): string
    {
        return $this->codes[$lobbyId] ?? '';
    }

    public function buildLobbyRealtimeSnapshot(int $lobbyId, array $options = []): array
    {
        return [
            'revision' => 'lobby-revision-' . $lobbyId,
            'lobby' => ['id' => $lobbyId, 'lobby_code' => $this->getLobbyCodeById($lobbyId)],
        ];
    }

    public function getPublicLobbiesRealtimeSnapshot(): array
    {
        return ['revision' => 'public-revision', 'items' => []];
    }
}

mqTest('La file regroupe un salon et laisse la suppression remplacer son snapshot', function (): void {
    $repository = new InMemoryRealtimeOutboxRepository();
    $mercure = new FakeRealtimeMercureService();
    $scheduled = 0;
    $service = new RealtimeOutboxService(
        $repository,
        $mercure,
        static function () use (&$scheduled): void {
            $scheduled++;
        }
    );

    $service->queueLobbySnapshot(42, true);
    $service->queueLobbySnapshot(42, true);
    mqAssertSame(2, count($repository->rows));
    mqAssertSame(2, $repository->rows['lobby:42']['generation']);

    $service->queueDeletedLobby('ABCD1234', 42, true);
    mqAssertSame('lobby_deleted', $repository->rows['lobby:42']['event_kind']);
    mqAssertSame(3, $repository->rows['lobby:42']['generation']);
    mqAssertTrue($scheduled >= 1);
});

mqTest('Un acquittement ancien ne supprime jamais une generation plus recente', function (): void {
    $repository = new InMemoryRealtimeOutboxRepository();
    $repository->enqueue('lobby:42', 'lobby_snapshot', 42, null, null);
    $claimed = $repository->claimNext(30);
    mqAssertTrue(is_array($claimed));

    $repository->enqueue('lobby:42', 'lobby_snapshot', 42, null, null);
    $repository->acknowledge((int)$claimed['id'], (int)$claimed['generation']);

    mqAssertSame(1, $repository->countPending());
    mqAssertSame(2, $repository->rows['lobby:42']['generation']);
    mqAssertFalse($repository->rows['lobby:42']['locked']);
});

mqTest('Le worker publie les derniers snapshots puis vide la file', function (): void {
    $repository = new InMemoryRealtimeOutboxRepository();
    $repository->enqueue('lobby:42', 'lobby_snapshot', 42, null, null);
    $repository->enqueue('public-lobbies', 'public_lobbies', null, null, null);
    $mercure = new FakeRealtimeMercureService();
    $processor = new RealtimeOutboxProcessor($repository, new FakeRealtimeLobbyService(), $mercure);

    $stats = $processor->drain(10, 2000);

    mqAssertSame(2, $stats['published']);
    mqAssertSame(0, $stats['failed']);
    mqAssertSame(0, $stats['pending']);
    mqAssertSame(2, count($mercure->publications));
});

mqTest('Un echec Mercure conserve le travail pour une nouvelle tentative', function (): void {
    $repository = new InMemoryRealtimeOutboxRepository();
    $repository->enqueue('lobby:42', 'lobby_snapshot', 42, null, null);
    $mercure = new FakeRealtimeMercureService();
    $mercure->failuresRemaining = 1;
    $processor = new RealtimeOutboxProcessor($repository, new FakeRealtimeLobbyService(), $mercure);

    $stats = $processor->drain(1, 2000);

    mqAssertSame(1, $stats['failed']);
    mqAssertSame(1, $stats['pending']);
    mqAssertSame(1, count($repository->retries));
    mqAssertTrue($repository->retries[0]['delayMilliseconds'] >= 500);
});

mqTest('Un snapshot de salon deja supprime est ignore sans boucle d erreur', function (): void {
    $repository = new InMemoryRealtimeOutboxRepository();
    $repository->enqueue('lobby:99', 'lobby_snapshot', 99, null, null);
    $mercure = new FakeRealtimeMercureService();
    $processor = new RealtimeOutboxProcessor($repository, new FakeRealtimeLobbyService(), $mercure);

    $stats = $processor->drain(10, 2000);

    mqAssertSame(1, $stats['discarded']);
    mqAssertSame(0, $stats['pending']);
    mqAssertSame(0, count($mercure->publications));
});

$controllerSource = file_get_contents(__DIR__ . '/../controllers/LobbyController.php');
$responseSource = file_get_contents(__DIR__ . '/../utils/response.php');
$afterResponseSource = file_get_contents(__DIR__ . '/../utils/after_response.php');
$migrationSource = file_get_contents(__DIR__ . '/../sql/019_melodyquest_realtime_outbox.sql');
$pdoRepositorySource = file_get_contents(__DIR__ . '/../repositories/PdoRealtimeOutboxRepository.php');

mqTest('Le controleur HTTP ne publie plus directement vers Mercure', function () use ($controllerSource): void {
    mqAssertFalse(str_contains($controllerSource, '->publish('));
    mqAssertTrue(str_contains($controllerSource, 'queueLobbySnapshot('));
    mqAssertTrue(str_contains($controllerSource, 'queueDeletedLobby('));
});

mqTest('Les travaux Mercure commencent seulement apres la fermeture FastCGI', function () use (
    $responseSource,
    $afterResponseSource
): void {
    mqAssertTrue(str_contains($responseSource, 'mq_run_after_response_tasks();'));
    mqAssertTrue(str_contains($afterResponseSource, "function_exists('fastcgi_finish_request')"));
    mqAssertTrue(
        strpos($afterResponseSource, 'fastcgi_finish_request()') < strpos($afterResponseSource, '$task();')
    );
});

mqTest('La migration outbox est non destructive et regroupe les flux', function () use ($migrationSource): void {
    $upper = strtoupper($migrationSource);
    mqAssertFalse(str_contains($upper, 'DROP TABLE'));
    mqAssertFalse(str_contains($upper, 'ALTER TABLE'));
    mqAssertFalse(str_contains($upper, 'DELETE FROM'));
    mqAssertTrue(str_contains($migrationSource, 'mq_realtime_outbox'));
    mqAssertTrue(str_contains($migrationSource, 'UNIQUE KEY uq_mq_realtime_outbox_stream'));
});

mqTest('La file utilise la meme horloge MySQL que ses colonnes par defaut', function () use ($pdoRepositorySource): void {
    mqAssertFalse(str_contains($pdoRepositorySource, 'UTC_TIMESTAMP'));
    mqAssertTrue(str_contains($pdoRepositorySource, 'CURRENT_TIMESTAMP(6)'));
});
