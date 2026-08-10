<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/MercureService.php';
require_once __DIR__ . '/RealtimeOutboxProcessor.php';
require_once __DIR__ . '/../repositories/PdoRealtimeOutboxRepository.php';
require_once __DIR__ . '/../utils/after_response.php';

class RealtimeOutboxService
{
    public function __construct(
        private ?RealtimeOutboxRepository $repository = null,
        private ?MercureService $mercure = null,
        private ?Closure $drainScheduler = null
    ) {
        $this->repository ??= new PdoRealtimeOutboxRepository(DatabaseService::getInstance());
        $this->mercure ??= new MercureService();
        $this->drainScheduler ??= static function (): void {
            mq_defer_after_response('melodyquest-realtime-outbox', static function (): void {
                (new RealtimeOutboxProcessor())->drain();
            });
        };
    }

    public function queueLobbySnapshot(int $lobbyId, bool $includePublicLobbies): void
    {
        if ($lobbyId <= 0 || !$this->mercure->canPublish()) {
            return;
        }

        $this->enqueueSafely(static function (RealtimeOutboxRepository $repository) use ($lobbyId): void {
            $repository->enqueue(
                'lobby:' . $lobbyId,
                'lobby_snapshot',
                $lobbyId,
                null,
                null
            );
        });

        if ($includePublicLobbies) {
            $this->queuePublicLobbies();
        }
    }

    public function queueDeletedLobby(string $lobbyCode, int $lobbyId, bool $includePublicLobbies): void
    {
        if ($lobbyId <= 0 || !$this->mercure->canPublish()) {
            return;
        }

        $lobbyCode = strtoupper(trim($lobbyCode));
        if ($lobbyCode !== '') {
            $payload = [
                'revision' => 'deleted-' . $lobbyId . '-' . str_replace('.', '', sprintf('%.6F', microtime(true))),
                'lobby' => null,
                'players' => [],
                'pool' => ['items' => []],
                'round' => ['round' => null, 'answers' => []],
                'playback' => null,
                'scoreboard' => ['items' => []],
                'deleted' => true,
                'deleted_lobby_id' => $lobbyId,
                'server_time' => gmdate('c'),
            ];

            $this->enqueueSafely(
                static function (RealtimeOutboxRepository $repository) use (
                    $lobbyId,
                    $lobbyCode,
                    $payload
                ): void {
                    $repository->enqueue(
                        'lobby:' . $lobbyId,
                        'lobby_deleted',
                        $lobbyId,
                        $lobbyCode,
                        $payload
                    );
                }
            );
        }

        if ($includePublicLobbies) {
            $this->queuePublicLobbies();
        }
    }

    public function queuePublicLobbies(): void
    {
        if (!$this->mercure->canPublish()) {
            return;
        }

        $this->enqueueSafely(static function (RealtimeOutboxRepository $repository): void {
            $repository->enqueue('public-lobbies', 'public_lobbies', null, null, null);
        });
    }

    private function enqueueSafely(Closure $enqueue): void
    {
        try {
            $enqueue($this->repository);
            ($this->drainScheduler)();
        } catch (Throwable $e) {
            error_log('MelodyQuest realtime outbox enqueue failed: ' . $e->getMessage());
        }
    }
}
