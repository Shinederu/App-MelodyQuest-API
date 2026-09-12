<?php

// Opt-in fixture: only a disposable MySQL on loopback, never the shared database.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--local-fixture') exit(2);
$_ENV['MQ_DB_HOST'] = '127.0.0.1';
$_ENV['MQ_DB_PORT'] = '33307';
$_ENV['MQ_DB_NAME'] = 'mq_ui_test';
$_ENV['MQ_DB_USER'] = 'root';
$_ENV['MQ_DB_PASS'] = '';
$_ENV['MQ_MODERATION_EMAIL_ENABLED'] = 'false';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../services/LobbyService.php';
require_once __DIR__ . '/../services/CatalogService.php';
require_once __DIR__ . '/../services/SuggestionService.php';
require_once __DIR__ . '/../services/TvService.php';

$db = DatabaseService::getInstance();
$catalog = new CatalogService();
$lobbies = new LobbyService();
$suggestions = new SuggestionService();
$suffix = bin2hex(random_bytes(3));
$db->exec("INSERT INTO mq_guest_sessions(token_hash,nickname,expires_at) VALUES (SHA2('qa-owner-$suffix',256),'QA_InviteCreateur',DATE_ADD(NOW(), INTERVAL 2 HOUR))");
$owner = -(int)$db->lastInsertId();
$db->exec("INSERT INTO mq_guest_sessions(token_hash,nickname,expires_at) VALUES (SHA2('qa-guest-$suffix',256),'Super_PseudonymeVraimentTresLong',DATE_ADD(NOW(), INTERVAL 2 HOUR))");
$guest = -(int)$db->lastInsertId();
$categories = [];
$tracks = [];
foreach (['Jeux video', 'Dessins animes'] as $name) {
    $cat = $catalog->createCategory(1, ['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . $suffix]);
    $categories[] = (int)$cat['id'];
    foreach ([null, 3, 7, 9] as $rating) {
        $track = $catalog->createTrack(1, ['category_id' => $cat['id'], 'family_name' => 'Oeuvre QA ' . $suffix,
            'title' => 'Theme QA ' . ($rating ?? 'inconnu'), 'youtube_video_id' => 'M7lc1UVf-VE',
            'start_offset_seconds' => 12, 'end_offset_seconds' => 90, 'familiarity' => $rating]);
        $catalog->validateTrack(1, (int)$track['id']);
        $tracks[] = (int)$track['id'];
    }
}
$snapshot = $lobbies->createLobby($owner, ['name' => 'QA ergonomie ' . $suffix, 'selected_category_ids' => $categories]);
$lobbyId = (int)$snapshot['lobby']['id'];
$code = $snapshot['lobby']['lobby_code'];
$lobbies->joinLobby($guest, $code);
$lobbies->joinLobby(1, $code);
$lobbies->joinLobby(2, $code);

mqTest('Les nouveaux salons utilisent 30 manches, 20 secondes et la visibilite publique', function () use ($snapshot, $lobbies, $owner): void {
    mqAssertSame(30, (int)$snapshot['lobby']['total_rounds']);
    mqAssertSame(20, (int)$snapshot['lobby']['round_duration_seconds']);
    mqAssertSame('public', $snapshot['lobby']['visibility']);
    $passive = $lobbies->createLobby($owner, ['game_mode' => 'autoplay']);
    mqAssertSame('public', $passive['lobby']['visibility']);
});

mqTest('Le createur invite peut mettre absent et exclure un invite sans effacer son score', function () use ($db, $lobbies, $owner, $guest, $lobbyId, $code): void {
    $result = $lobbies->touchLobbyPresence($owner, $lobbyId, 'away', $guest);
    mqAssertSame($guest, $result['target_actor_id']);
    mqAssertSame('away', $result['presence_status']);
    mqAssertThrows(RuntimeException::class, fn() => $lobbies->kickPlayer($guest, $lobbyId, $owner));
    $db->exec("UPDATE mq_lobby_players SET score = 472 WHERE lobby_id=$lobbyId AND actor_id=$guest");
    $lobbies->kickPlayer($owner, $lobbyId, $guest);
    mqAssertSame('removed', $db->query("SELECT presence_status FROM mq_lobby_players WHERE lobby_id=$lobbyId AND actor_id=$guest")->fetchColumn());
    $lobbies->joinLobby($guest, $code);
    mqAssertSame(472, (int)$db->query("SELECT score FROM mq_lobby_players WHERE lobby_id=$lobbyId AND actor_id=$guest")->fetchColumn());
});

mqTest('Le filtrage garde un tirage equilibre et exclut les musiques sous le seuil', function () use ($catalog, $lobbies, $owner, $lobbyId, $categories): void {
    $lobbies->updateLobbyConfig($owner, $lobbyId, ['min_familiarity' => 7, 'total_rounds' => 4]);
    $counts = mqInvokePrivate($lobbies, 'getPlayableTrackCountsByCategory', [$lobbyId, $categories]);
    mqAssertSame([2, 2], array_values($counts));
    $state = $lobbies->startRound($owner, $lobbyId);
    $round = $lobbies->getRoundState($owner, $lobbyId);
    mqAssertSame(90, (int)$round['round']['track']['end_offset_seconds']);
    mqAssertFalse(isset($round['round']['track']['family_name']));
    foreach ($catalog->listCategories() as $category) {
        if (in_array((int)$category['id'], $categories, true)) mqAssertSame(1, (int)((array)$category['track_counts_by_familiarity'])[7]);
    }
});

mqTest('Une correction renomme l oeuvre partagee et conserve la musique et ses alias', function () use ($db, $catalog, $suggestions, $guest, $tracks): void {
    $track = $tracks[0];
    $family = (int)$db->query("SELECT family_id FROM mq_tracks WHERE id=$track")->fetchColumn();
    $catalog->addFamilyAlias(1, ['family_id' => $family, 'alias' => 'Alias initial']);
    $suggestion = $suggestions->submit($guest, ['track_id' => $track, 'proposed_family_name' => 'Nouvelle oeuvre QA', 'proposed_start_offset_seconds' => 0, 'proposed_end_offset_seconds' => 55]);
    $suggestions->apply((int)$suggestion['id'], 1, []);
    mqAssertSame('Nouvelle oeuvre QA', $db->query("SELECT name FROM mq_families WHERE id=$family")->fetchColumn());
    mqAssertSame(0, (int)$db->query("SELECT start_offset_seconds FROM mq_tracks WHERE id=$track")->fetchColumn());
    mqAssertSame(55, (int)$db->query("SELECT end_offset_seconds FROM mq_tracks WHERE id=$track")->fetchColumn());
    mqAssertSame(1, (int)$db->query("SELECT COUNT(*) FROM mq_family_aliases WHERE family_id=$family AND alias='Alias initial'")->fetchColumn());
    $alias = $suggestions->submit($guest, ['track_id' => $track, 'proposed_alias' => 'Deuxieme alias']);
    $suggestions->apply((int)$alias['id'], 1, []);
    mqAssertSame('Nouvelle oeuvre QA', $db->query("SELECT name FROM mq_families WHERE id=$family")->fetchColumn());
});

mqTest('Une TV peut etre liee apres une partie terminee, mais pas a un salon ferme', function () use ($db, $owner, $lobbyId): void {
    $tv = new TvService();
    $pairing = $tv->createPairing();
    $db->exec("UPDATE mq_lobbies SET status='finished' WHERE id=$lobbyId");
    $linked = $tv->linkPairing($owner, $pairing['pairing_code'], $lobbyId);
    mqAssertSame('linked', $linked['status']);
    $db->exec("UPDATE mq_lobbies SET status='closed' WHERE id=$lobbyId");
    mqAssertThrows(RuntimeException::class, fn() => mqInvokePrivate($tv, 'requireLinkableLobby', [$lobbyId]));
    $db->exec("UPDATE mq_lobbies SET status='waiting' WHERE id=$lobbyId");
});

mqTest('Une suppression proposee exige un motif et une confirmation explicite sans effacer l historique', function () use ($db, $suggestions, $catalog, $guest, $categories, $suffix): void {
    $track = $catalog->createTrack(1, ['category_id' => $categories[0], 'family_name' => 'Suppression QA ' . $suffix, 'title' => 'Doublon QA', 'youtube_video_id' => 'M7lc1UVf-VE']);
    $payload = ['suggestion_type' => 'track_removal', 'track_id' => $track['id']];
    mqAssertThrows(RuntimeException::class, fn() => $suggestions->submit($guest, $payload));
    $request = $suggestions->submit($guest, $payload + ['note' => 'Doublon de la meme musique']);
    $id = (int)$request['id'];
    mqAssertThrows(RuntimeException::class, fn() => $suggestions->apply($id, 1, []));
    mqAssertThrows(RuntimeException::class, fn() => $suggestions->apply($id, 1, ['confirm_removal' => 'false']));
    mqAssertSame(1, (int)$db->query('SELECT COUNT(*) FROM mq_tracks WHERE id=' . (int)$track['id'])->fetchColumn());
    $before = (int)$db->query('SELECT COUNT(*) FROM mq_game_sessions')->fetchColumn();
    $suggestions->apply($id, 1, ['confirm_removal' => true]);
    mqAssertSame(0, (int)$db->query('SELECT COUNT(*) FROM mq_tracks WHERE id=' . (int)$track['id'])->fetchColumn());
    mqAssertSame($before, (int)$db->query('SELECT COUNT(*) FROM mq_game_sessions')->fetchColumn());
    mqAssertTrue($suggestions->apply($id, 1, [])['already_applied']);
});

mqTest('Les erreurs definitives sont authentifiees, dedupliquees, puis sautees une seule fois apres six secondes', function () use ($db, $lobbies, $owner, $guest, $lobbyId): void {
    $db->exec("UPDATE mq_lobbies SET status='playing' WHERE id=$lobbyId");
    $round = $lobbies->getRoundState($owner, $lobbyId)['round'];
    $payload = ['lobby_id' => $lobbyId, 'round_id' => (int)$round['id'], 'youtube_video_id' => $round['track']['youtube_video_id'], 'error_code' => 100];
    foreach ([2, 5, 153] as $code) mqAssertThrows(RuntimeException::class, fn() => $lobbies->reportPlaybackError($owner, array_replace($payload, ['error_code' => $code])));
    mqAssertThrows(RuntimeException::class, fn() => $lobbies->reportPlaybackError(null, $payload));
    mqAssertThrows(RuntimeException::class, fn() => $lobbies->reportPlaybackError($owner, array_replace($payload, ['youtube_video_id' => 'wrong-video'])));
    mqAssertFalse($lobbies->reportPlaybackError($guest, $payload)['skip_scheduled']);
    mqAssertTrue($lobbies->reportPlaybackError($owner, $payload)['skip_scheduled']);
    $state = $lobbies->getRoundState($owner, $lobbyId)['round'];
    $deadline = $state['unavailable_skip_at_unix'];
    mqAssertTrue($deadline > microtime(true) + 4 && $deadline <= microtime(true) + 6.1);
    mqAssertFalse($state['is_accepting_answers']);
    mqAssertFalse($state['is_reveal_visible']);
    $lobbies->reportPlaybackError($owner, $payload);
    mqAssertSame($deadline, $lobbies->getRoundState($owner, $lobbyId)['round']['unavailable_skip_at_unix']);
    mqAssertSame(1, (int)$db->query('SELECT COUNT(*) FROM mq_player_suggestions WHERE automatic_report_key IS NOT NULL AND current_youtube_video_id="M7lc1UVf-VE"')->fetchColumn());
    mqAssertFalse($lobbies->advanceUnavailableRound($guest, $payload)['advanced']);
    mqAssertThrows(RuntimeException::class, fn() => $lobbies->finishCurrentRound($owner, $lobbyId));
    $db->exec('UPDATE mq_rounds SET unavailable_skip_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE id=' . $payload['round_id']);
    $lobbies->holdSuggestion($guest, $lobbyId, $payload['round_id']);
    mqAssertFalse($lobbies->advanceUnavailableRound($owner, $payload)['advanced']);
    $lobbies->releaseSuggestionHold($guest, $lobbyId, $payload['round_id']);
    mqAssertTrue($lobbies->advanceUnavailableRound($guest, $payload)['advanced']);
    mqAssertTrue($lobbies->advanceUnavailableRound($owner, $payload)['stale']);
    mqAssertSame((int)$round['round_number'] + 1, (int)$lobbies->getRoundState($owner, $lobbyId)['round']['round_number']);
});

mqTest('Une TV liee peut signaler puis terminer la derniere manche en mode passif', function () use ($db, $lobbies, $owner, $categories): void {
    $lobby = $lobbies->createLobby($owner, ['game_mode' => 'autoplay', 'selected_category_ids' => $categories, 'total_rounds' => 1])['lobby'];
    $id = (int)$lobby['id'];
    $round = $lobbies->startRound($owner, $id)['round'];
    $tv = new TvService();
    $pairing = $tv->createPairing();
    $tv->linkPairing($owner, $pairing['pairing_code'], $id);
    $payload = ['lobby_id' => $id, 'round_id' => (int)$round['id'], 'youtube_video_id' => $round['track']['youtube_video_id'], 'error_code' => 150, 'device_token' => $pairing['device_token']];
    mqAssertThrows(RuntimeException::class, fn() => $lobbies->reportPlaybackError(null, array_replace($payload, ['device_token' => str_repeat('a', 64)])));
    mqAssertTrue($lobbies->reportPlaybackError(null, $payload)['skip_scheduled']);
    $db->exec('UPDATE mq_rounds SET unavailable_skip_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE id=' . (int)$round['id']);
    mqAssertTrue($lobbies->advanceUnavailableRound(null, $payload)['advanced']);
    mqAssertSame('finished', $db->query("SELECT status FROM mq_lobbies WHERE id=$id")->fetchColumn());
    mqAssertFalse($lobbies->advanceUnavailableRound(null, $payload)['advanced']);
});

echo "Local fixture lobby: $lobbyId / $code; owner actor: $owner; guest actor: $guest\n";
mqFinishTests();
