<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$insightsSource = file_get_contents(__DIR__ . '/../services/AdminInsightsService.php');
$catalogSource = file_get_contents(__DIR__ . '/../services/CatalogService.php');
$routerSource = file_get_contents(__DIR__ . '/../index.php');

mqTest('L analyse admin fusionne les essais actifs et archivés sans doublon', function () use ($insightsSource): void {
    mqAssertTrue(str_contains($insightsSource, 'mq_round_answer_attempts answer_attempt'));
    mqAssertTrue(str_contains($insightsSource, 'mq_game_session_answer_attempts history_attempt'));
    mqAssertTrue(str_contains($insightsSource, 'history_copy.source_attempt_id = answer_attempt.id'));
    mqAssertTrue(str_contains($insightsSource, 'WHERE history_copy.id IS NULL'));
});

mqTest('L ajout direct d alias reste protégé par la route admin', function () use ($catalogSource, $routerSource): void {
    mqAssertTrue(str_contains($catalogSource, 'public function addFamilyAlias'));
    mqAssertTrue(str_contains($catalogSource, 'INSERT IGNORE INTO mq_family_aliases'));
    mqAssertTrue(str_contains($routerSource, "case 'addFamilyAlias':"));
    mqAssertTrue(str_contains($routerSource, 'AdminMiddleware::check($userId)'));
});
