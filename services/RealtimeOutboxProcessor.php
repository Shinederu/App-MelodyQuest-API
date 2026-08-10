<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/LobbyService.php';
require_once __DIR__ . '/MercureService.php';
require_once __DIR__ . '/../repositories/PdoRealtimeOutboxRepository.php';

class RealtimeOutboxProcessor
{
    public function __construct(
        private ?RealtimeOutboxRepository $repository = null,
        private ?LobbyService $lobbyService = null,
        private ?MercureService $mercure = null
    ) {
        if ($this->repository === null || $this->lobbyService === null) {
            $db = DatabaseService::getInstance();
            $this->repository ??= new PdoRealtimeOutboxRepository($db);
            $this->lobbyService ??= new LobbyService($db);
        }
        $this->mercure ??= new MercureService();
    }

    public function drain(?int $limit = null, ?int $maxRuntimeMilliseconds = null): array
    {
        $stats = [
            'claimed' => 0,
            'published' => 0,
            'discarded' => 0,
            'failed' => 0,
            'pending' => 0,
        ];

        if (!$this->mercure->canPublish()) {
            return $stats;
        }

        $limit = max(1, min(50, $limit ?? MQ_REALTIME_OUTBOX_BATCH_SIZE));
        $maxRuntimeMilliseconds = max(
            100,
            min(10000, $maxRuntimeMilliseconds ?? MQ_REALTIME_OUTBOX_MAX_RUNTIME_MS)
        );
        $deadline = microtime(true) + ($maxRuntimeMilliseconds / 1000);

        while ($stats['claimed'] < $limit && microtime(true) < $deadline) {
            $row = $this->repository->claimNext(MQ_REALTIME_OUTBOX_LOCK_TIMEOUT_SECONDS);
            if ($row === null) {
                break;
            }

            $stats['claimed']++;
            $id = (int)$row['id'];
            $generation = (int)$row['generation'];

            try {
                $outcome = $this->publishRow($row);
                if ($outcome === 'discarded') {
                    $stats['discarded']++;
                    $this->repository->acknowledge($id, $generation);
                    continue;
                }

                if ($outcome === 'published') {
                    $stats['published']++;
                    $this->repository->acknowledge($id, $generation);
                    continue;
                }

                throw new RuntimeException('Publication Mercure refusee');
            } catch (Throwable $e) {
                $stats['failed']++;
                $attempts = max(1, (int)($row['attempts'] ?? 1));
                $this->repository->retry(
                    $id,
                    $generation,
                    $this->retryDelayMilliseconds($attempts),
                    $e->getMessage()
                );
                error_log('MelodyQuest realtime outbox publish failed: ' . $e->getMessage());
            }
        }

        $stats['pending'] = $this->repository->countPending();
        return $stats;
    }

    private function publishRow(array $row): string
    {
        return match ((string)($row['event_kind'] ?? '')) {
            'lobby_snapshot' => $this->publishLobbySnapshot($row),
            'lobby_deleted' => $this->publishDeletedLobby($row),
            'public_lobbies' => $this->publishPublicLobbies(),
            default => 'discarded',
        };
    }

    private function publishLobbySnapshot(array $row): string
    {
        $lobbyId = (int)($row['lobby_id'] ?? 0);
        if ($lobbyId <= 0 || $this->lobbyService->getLobbyCodeById($lobbyId) === '') {
            return 'discarded';
        }

        $snapshot = $this->lobbyService->buildLobbyRealtimeSnapshot($lobbyId);
        $lobbyCode = strtoupper(trim((string)($snapshot['lobby']['lobby_code'] ?? '')));
        if ($lobbyCode === '') {
            return 'discarded';
        }

        $published = $this->mercure->publish(
            $this->mercure->getLobbyTopic($lobbyCode),
            $snapshot,
            true,
            'lobby',
            (string)($snapshot['revision'] ?? '')
        );

        return $published ? 'published' : 'failed';
    }

    private function publishDeletedLobby(array $row): string
    {
        $lobbyCode = strtoupper(trim((string)($row['lobby_code'] ?? '')));
        $payload = $this->decodePayload($row['payload'] ?? null);
        if ($lobbyCode === '' || $payload === null) {
            return 'discarded';
        }

        $published = $this->mercure->publish(
            $this->mercure->getLobbyTopic($lobbyCode),
            $payload,
            true,
            'lobby',
            (string)($payload['revision'] ?? '')
        );

        return $published ? 'published' : 'failed';
    }

    private function publishPublicLobbies(): string
    {
        $snapshot = $this->lobbyService->getPublicLobbiesRealtimeSnapshot();
        $published = $this->mercure->publish(
            $this->mercure->getPublicLobbiesTopic(),
            $snapshot,
            false,
            'lobbies',
            (string)($snapshot['revision'] ?? '')
        );

        return $published ? 'published' : 'failed';
    }

    private function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function retryDelayMilliseconds(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 500,
            $attempts === 2 => 1000,
            $attempts === 3 => 2000,
            $attempts === 4 => 5000,
            $attempts === 5 => 10000,
            default => 30000,
        };
    }
}
