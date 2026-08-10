<?php

interface RealtimeOutboxRepository
{
    public function enqueue(
        string $streamKey,
        string $eventKind,
        ?int $lobbyId,
        ?string $lobbyCode,
        ?array $payload
    ): void;

    public function claimNext(int $lockTimeoutSeconds): ?array;

    public function acknowledge(int $id, int $generation): void;

    public function retry(int $id, int $generation, int $delayMilliseconds, string $error): void;

    public function countPending(): int;
}
