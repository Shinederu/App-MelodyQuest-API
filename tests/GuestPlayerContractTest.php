<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$migrationSource = file_get_contents(__DIR__ . '/../sql/020_melodyquest_guest_players.sql');
$sessionSource = file_get_contents(__DIR__ . '/../services/PlayerSessionService.php');
$lobbySource = file_get_contents(__DIR__ . '/../services/LobbyService.php');
$routerSource = file_get_contents(__DIR__ . '/../index.php');

mqTest('Les invités ont un espace acteur séparé des comptes', function () use ($migrationSource, $sessionSource): void {
    mqAssertTrue(str_contains($migrationSource, 'mq_guest_sessions'));
    mqAssertTrue(str_contains($migrationSource, 'owner_actor_id'));
    mqAssertTrue(str_contains($migrationSource, 'actor_id BIGINT'));
    mqAssertTrue(str_contains($sessionSource, "'actor_id' => -\$sessionId"));
    mqAssertFalse(str_contains($sessionSource, 'INSERT INTO users'));
});

mqTest('Le cookie invité ne contient que le jeton brut côté navigateur', function () use ($sessionSource): void {
    mqAssertTrue(str_contains($sessionSource, "hash('sha256', \$token)"));
    mqAssertTrue(str_contains($sessionSource, "'secure' => true"));
    mqAssertTrue(str_contains($sessionSource, "'httponly' => true"));
    mqAssertTrue(str_contains($sessionSource, "'samesite' => 'Lax'"));
});

mqTest('La migration invité conserve les données de jeu existantes', function () use ($migrationSource): void {
    $upper = strtoupper($migrationSource);
    mqAssertFalse(str_contains($upper, 'TRUNCATE '));
    mqAssertFalse(str_contains($upper, 'DROP TABLE'));
    mqAssertFalse(str_contains($upper, 'DELETE FROM'));
    mqAssertTrue(str_contains($migrationSource, 'UPDATE mq_lobby_players SET actor_id = user_id'));
    mqAssertTrue(str_contains($migrationSource, 'UPDATE mq_game_session_players SET actor_id = user_id'));
});

mqTest('Les actions de jeu résolvent toutes une identité joueur', function () use ($routerSource): void {
    foreach ([
        'createLobby',
        'joinLobby',
        'submitAnswer',
        'voteNextRound',
        'voteRevealRound',
        'linkTvPairing',
    ] as $action) {
        $position = strpos($routerSource, "case '{$action}'");
        mqAssertTrue($position !== false, "Action absente: {$action}");
        $block = substr($routerSource, $position, 420);
        mqAssertTrue(str_contains($block, 'PlayerMiddleware::check'), "Identité joueur absente: {$action}");
    }
});

mqTest('Les requêtes de salon utilisent actor_id pour les droits et scores', function () use ($lobbySource): void {
    foreach ([
        'WHERE lobby_id = :lobby_id AND actor_id = :actor_id',
        'owner_actor_id = :owner_actor_id',
        'SET score = score + :delta',
        'removed_by_actor_id = :removed_by_actor_id',
    ] as $contract) {
        mqAssertTrue(str_contains($lobbySource, $contract), "Contrat manquant: {$contract}");
    }
});
