<?php

interface GameSessionRepository
{
    public function archiveLobbyGame(int $lobbyId, string $completionStatus): ?int;
}
