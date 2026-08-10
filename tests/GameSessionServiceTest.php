<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../services/GameSessionService.php';

final class FakeGameSessionRepository implements GameSessionRepository
{
    public array $calls = [];

    public function __construct(private ?int $result = 42)
    {
    }

    public function archiveLobbyGame(int $lobbyId, string $completionStatus): ?int
    {
        $this->calls[] = [$lobbyId, $completionStatus];
        return $this->result;
    }
}

mqTest('Le service normalise le statut avant archivage', function (): void {
    $repository = new FakeGameSessionRepository(73);
    $service = new GameSessionService($repository);

    mqAssertSame(73, $service->archiveLobbyGame(12, ' FINISHED '));
    mqAssertSame([[12, 'finished']], $repository->calls);
});

mqTest('Un archivage sans manche peut retourner null', function (): void {
    $service = new GameSessionService(new FakeGameSessionRepository(null));
    mqAssertSame(null, $service->archiveLobbyGame(12, 'cancelled'));
});

mqTest('Un identifiant de lobby invalide est refusé', function (): void {
    $service = new GameSessionService(new FakeGameSessionRepository());
    mqAssertThrows(InvalidArgumentException::class, fn () => $service->archiveLobbyGame(0, 'finished'));
});

mqTest('Un statut d historique invalide est refusé', function (): void {
    $service = new GameSessionService(new FakeGameSessionRepository());
    mqAssertThrows(InvalidArgumentException::class, fn () => $service->archiveLobbyGame(12, 'playing'));
});
