<?php

require_once __DIR__ . '/../repositories/GameSessionRepository.php';

final class GameSessionService
{
    public function __construct(private GameSessionRepository $repository)
    {
    }

    public function archiveLobbyGame(int $lobbyId, string $completionStatus): ?int
    {
        if ($lobbyId <= 0) {
            throw new InvalidArgumentException('Lobby invalide pour l\'historique de partie');
        }

        return $this->repository->archiveLobbyGame(
            $lobbyId,
            self::normalizeCompletionStatus($completionStatus)
        );
    }

    public static function normalizeCompletionStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['finished', 'cancelled'], true)) {
            throw new InvalidArgumentException('Statut d\'historique de partie invalide');
        }

        return $status;
    }
}
