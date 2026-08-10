<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../services/LobbyService.php';

$reflection = new ReflectionClass(LobbyService::class);
$lobbyService = $reflection->newInstanceWithoutConstructor();

mqTest('La comparaison ignore les accents et la casse', function () use ($lobbyService): void {
    mqAssertTrue(mqInvokePrivate($lobbyService, 'isGuessCorrect', [
        'pokemon',
        'Pokémon',
        100,
    ]));
});

mqTest('Le seuil à 80 pourcent tolère une faute humaine raisonnable', function () use ($lobbyService): void {
    mqAssertTrue(mqInvokePrivate($lobbyService, 'isGuessCorrect', [
        'Grnshin Impart',
        'Genshin Impact',
        80,
    ]));
});

mqTest('Le seuil à 80 pourcent refuse une réponse sans rapport', function () use ($lobbyService): void {
    mqAssertFalse(mqInvokePrivate($lobbyService, 'isGuessCorrect', [
        'Super Mario Bros',
        'Genshin Impact',
        80,
    ]));
});

mqTest('Les catégories disponibles reçoivent un quota équilibré', function () use ($lobbyService): void {
    $quotas = mqInvokePrivate($lobbyService, 'calculateBalancedCategoryQuotas', [
        [10 => 8, 20 => 8, 30 => 8],
        8,
        91,
    ]);

    mqAssertSame(8, array_sum($quotas));
    mqAssertTrue(max($quotas) - min($quotas) <= 1);
});

mqTest('Une catégorie épuisée cède les manches restantes aux autres', function () use ($lobbyService): void {
    $quotas = mqInvokePrivate($lobbyService, 'calculateBalancedCategoryQuotas', [
        [10 => 1, 20 => 10, 30 => 10],
        7,
        91,
    ]);

    mqAssertSame(1, $quotas[10]);
    mqAssertSame(7, array_sum($quotas));
    mqAssertSame($quotas[20], $quotas[30]);
});
