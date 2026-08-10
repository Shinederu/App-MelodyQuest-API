<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$serviceSource = file_get_contents(__DIR__ . '/../services/LobbyService.php');
$migrationSource = file_get_contents(__DIR__ . '/../sql/018_melodyquest_game_history.sql');
$backfillSource = file_get_contents(__DIR__ . '/../scripts/backfill_game_history.php');

mqTest('Chaque chemin destructif archive la partie auparavant', function () use ($serviceSource): void {
    mqAssertTrue(substr_count($serviceSource, 'archiveLobbyGame(') >= 4);
    mqAssertTrue(
        strpos($serviceSource, "archiveLobbyGame(\$lobbyId, 'finished')") <
        strpos($serviceSource, "'DELETE a")
    );
    mqAssertTrue(
        strpos($serviceSource, 'foreach ($staleLobbies as $staleLobby)') <
        strpos($serviceSource, "'DELETE FROM mq_lobbies WHERE id IN (")
    );
});

mqTest('La migration ajoute uniquement des tables append-only', function () use ($migrationSource): void {
    $upper = strtoupper($migrationSource);
    mqAssertFalse(str_contains($upper, 'ALTER TABLE'));
    mqAssertFalse(str_contains($upper, 'DROP TABLE'));
    mqAssertFalse(str_contains($upper, 'DELETE FROM'));

    foreach ([
        'mq_game_sessions',
        'mq_game_session_players',
        'mq_game_session_rounds',
        'mq_game_session_answers',
        'mq_game_session_answer_attempts',
        'mq_game_session_reveal_votes',
        'mq_game_session_away_bonuses',
    ] as $table) {
        mqAssertTrue(str_contains($migrationSource, $table), "Table d'historique manquante: {$table}");
    }
});

mqTest('Le backfill est transactionnel et limité aux salons terminés', function () use ($backfillSource): void {
    mqAssertTrue(str_contains($backfillSource, 'beginTransaction()'));
    mqAssertTrue(str_contains($backfillSource, 'rollBack()'));
    mqAssertTrue(str_contains($backfillSource, 'l.status IN ("finished", "closed")'));
    mqAssertFalse(str_contains(strtoupper($backfillSource), 'DELETE FROM'));
});
