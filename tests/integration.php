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
        $track = $catalog->createTrack(1, ['category_id' => $cat['id'], 'family_name' => 'Oeuvre QA ' . $suffix . '-' . ($rating ?? 'inconnu'),
            'notoriety_seed' => $rating >= 9 ? 100 : ($rating >= 7 ? 75 : 60),
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
    $lobbies->updateLobbyConfig($owner, $lobbyId, ['min_notoriety' => 60, 'total_rounds' => 4]);
    $counts = mqInvokePrivate($lobbies, 'getPlayableTrackCountsByCategory', [$lobbyId, $categories]);
    mqAssertSame([4, 4], array_values($counts));
    $state = $lobbies->startRound($owner, $lobbyId);
    $round = $lobbies->getRoundState($owner, $lobbyId);
    mqAssertSame(90, (int)$round['round']['track']['end_offset_seconds']);
    mqAssertFalse(isset($round['round']['track']['family_name']));
    foreach ($catalog->listCategories() as $category) {
        if (in_array((int)$category['id'], $categories, true)) mqAssertSame(1, (int)((array)$category['track_counts_by_notoriety'])[75]);
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

mqTest('Le vote est reserve aux comptes et le premier choix reste definitif pour toute l oeuvre', function () use ($db, $lobbies, $catalog, $owner, $guest, $categories): void {
    $service = new FamilyKnowledgeService();
    $lobby = $lobbies->createLobby(1, ['selected_category_ids' => $categories, 'total_rounds' => 3])['lobby'];
    $id = (int)$lobby['id'];
    $lobbies->joinLobby($guest, $lobby['lobby_code']);
    $lobbies->joinLobby(2, $lobby['lobby_code']);
    $round = $lobbies->startRound(1, $id)['round'];
    $roundId = (int)$round['id'];
    $payload = ['lobby_id' => $id, 'round_id' => $roundId, 'known' => true];
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound($guest, $payload, true));
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound(2, $payload, true));
    $db->exec("UPDATE mq_rounds SET started_at=DATE_SUB(NOW(3), INTERVAL 5 SECOND) WHERE id=$roundId");
    $familyName = $db->query("SELECT f.name FROM mq_rounds r JOIN mq_tracks t ON t.id=r.track_id JOIN mq_families f ON f.id=t.family_id WHERE r.id=$roundId")->fetchColumn();
    $lobbies->submitAnswer(1, $id, ['guess_title' => $familyName]);
    mqAssertSame(true, $service->forRound(1, $payload, true)['choice']);
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound(2, $payload));
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound($guest, $payload));
    $db->exec("UPDATE mq_rounds SET status='reveal', reveal_started_at=NOW(3) WHERE id=$roundId");
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound($guest, $payload, true));
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound(999999, $payload, true));
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound(2, array_replace($payload, ['known' => 'false']), true));
    mqAssertTrue($service->forRound(2, $payload)['can_vote']);
    $result = $service->forRound(2, array_replace($payload, ['known' => false]), true);
    mqAssertSame(2, $result['vote_count']);
    mqAssertSame(50, $result['known_percent']);
    $result = $service->forRound(2, $payload, true);
    mqAssertSame(2, $result['vote_count']);
    mqAssertSame(50, $result['known_percent']);
    mqAssertSame(false, $result['choice']);
    mqAssertFalse($result['can_vote']);
    mqAssertSame(true, $service->forRound(1, array_replace($payload, ['known' => false]), true)['choice']);
    $family = $result['family_id'];
    $lobbies->finishCurrentRound(1, $id);
    $second = $lobbies->startRound(1, $id)['round'];
    $next = (int)$second['id'];
    $sameFamilyTrack = (int)$catalog->createTrack(1, ['family_id' => $family, 'title' => 'Other track, same work', 'youtube_video_id' => 'M7lc1UVf-VE'])['id'];
    $catalog->validateTrack(1, $sameFamilyTrack);
    $db->exec("UPDATE mq_rounds SET track_id=$sameFamilyTrack,status='reveal',reveal_started_at=NOW(3) WHERE id=$next");
    $result = (new FamilyKnowledgeService())->forRound(2, array_replace($payload, ['round_id' => $next]), true);
    mqAssertSame(2, $result['vote_count']);
    mqAssertSame(false, $result['choice']);
    mqAssertFalse($result['can_vote']);
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound(2, $payload, true));
    $db->exec("INSERT INTO mq_family_knowledge(family_id,guest_session_id,known) VALUES($family," . abs($guest) . ",1)");
    $db->exec('DELETE FROM mq_guest_sessions WHERE id=' . abs($guest));
    mqAssertSame(3, $service->summaries([$family])[$family]['vote_count']);
    mqAssertSame(1, (int)$db->query("SELECT COUNT(*) FROM mq_family_knowledge WHERE family_id=$family AND user_id IS NULL AND guest_session_id IS NULL")->fetchColumn());
});

mqTest('La file de verification est filtree et paginee sans perdre les bornes de lecture', function () use ($catalog, $tracks, $categories): void {
    foreach ($tracks as $track) $catalog->unvalidateTrack($track);
    $result = $catalog->listPendingTracks(['category_id' => $categories[1], 'page_size' => 2, 'page' => 99, 'search' => 'Theme QA']);
    mqAssertSame(4, $result['total']);
    mqAssertSame(2, $result['pages']);
    mqAssertSame(2, $result['page']);
    mqAssertSame(2, count($result['items']));
    mqAssertSame(12, (int)$result['items'][0]['start_offset_seconds']);
    mqAssertSame(90, (int)$result['items'][0]['end_offset_seconds']);
    mqAssertSame(0, $catalog->listPendingTracks(['search' => '%_not_a_wildcard'])['total']);
});

mqTest('Le mode passif accepte un avis facultatif uniquement apres revelation', function () use ($db, $lobbies, $owner): void {
    $lobby = $lobbies->createLobby(1, ['game_mode' => 'autoplay', 'total_rounds' => 1])['lobby'];
    $id = (int)$lobby['id'];
    $round = $lobbies->startRound(1, $id)['round'];
    $roundId = (int)$round['id'];
    $payload = ['lobby_id' => $id, 'round_id' => $roundId, 'known' => false];
    $service = new FamilyKnowledgeService();
    mqAssertThrows(RuntimeException::class, fn() => $service->forRound(1, $payload, true));
    $db->exec("UPDATE mq_rounds SET status='reveal',reveal_started_at=NOW(3) WHERE id=$roundId");
    $before = $service->forRound(1, $payload);
    mqAssertSame($before['choice'] ?? false, $service->forRound(1, $payload, true)['choice']);
});

mqTest('La notoriete partagee preserve les avis et filtre exactement les seuils 60 et 90', function () use ($db, $catalog, $lobbies, $owner, $suffix): void {
    $cat = (int)$catalog->createCategory(1, ['name' => 'Notoriete', 'slug' => 'notoriete-' . $suffix])['id'];
    $ids = [];
    foreach ([60, 75, 100] as $seed) {
        $ids[$seed] = (int)$catalog->createTrack(1, ['category_id' => $cat, 'family_name' => 'Seed ' . $seed,
            'notoriety_seed' => $seed, 'title' => 'Test', 'youtube_video_id' => 'M7lc1UVf-VE'])['id'];
        $catalog->validateTrack(1, $ids[$seed]);
    }
    $family = (int)$db->query('SELECT family_id FROM mq_tracks WHERE id=' . $ids[100])->fetchColumn();
    $extra = (int)$catalog->createTrack(1, ['family_id' => $family, 'title' => 'Same work', 'youtube_video_id' => 'M7lc1UVf-VE'])['id'];
    $catalog->validateTrack(1, $extra);
    mqAssertSame(100, (int)$db->query("SELECT notoriety_seed FROM mq_families WHERE id=$family")->fetchColumn());
    $db->exec("INSERT INTO mq_family_knowledge(family_id,user_id,known) VALUES($family,1,0)");
    $summary = (new FamilyKnowledgeService())->summaries([$family])[$family];
    mqAssertSame(90, $summary['notoriety_percent']);
    $lowFamily = (int)$db->query('SELECT family_id FROM mq_tracks WHERE id=' . $ids[60])->fetchColumn();
    $db->exec("INSERT INTO mq_family_knowledge(family_id,user_id,known) VALUES($lowFamily,1,0)");
    mqAssertSame(54, (new FamilyKnowledgeService())->summaries([$lowFamily])[$lowFamily]['notoriety_percent']);
    $room = $lobbies->createLobby($owner, ['selected_category_ids' => [$cat], 'min_notoriety' => 90, 'game_mode' => 'autoplay'])['lobby'];
    $id = (int)$room['id'];
    foreach ([0 => 4, 60 => 3, 90 => 2] as $min => $expected) {
        $lobbies->updateLobbyConfig($owner, $id, ['min_notoriety' => $min]);
        mqAssertSame([$expected], array_values(mqInvokePrivate($lobbies, 'getPlayableTrackCountsByCategory', [$id, [$cat]])));
    }
    mqAssertTrue(mqInvokePrivate($lobbies, 'isTrackPlayableForLobby', [$id, $ids[100]]));
    mqAssertFalse(mqInvokePrivate($lobbies, 'isTrackPlayableForLobby', [$id, $ids[75]]));
    $catalog->updateFamily(1, ['id' => $family, 'notoriety_seed' => 75]);
    $summary = (new FamilyKnowledgeService())->summaries([$family])[$family];
    mqAssertSame(1, $summary['vote_count']);
    mqAssertSame(68, $summary['notoriety_percent']);
    mqAssertSame([0], array_values(mqInvokePrivate($lobbies, 'getPlayableTrackCountsByCategory', [$id, [$cat]])));
    $before = (int)$db->query('SELECT COUNT(*) FROM mq_tracks')->fetchColumn();
    mqAssertThrows(RuntimeException::class, fn() => $catalog->createTrack(1, ['family_id' => $family, 'title' => 'Invalid', 'notoriety_seed' => 50, 'youtube_video_id' => 'M7lc1UVf-VE']));
    mqAssertSame($before, (int)$db->query('SELECT COUNT(*) FROM mq_tracks')->fetchColumn());
    $catalog->validateTrack(1, $extra, ['notoriety_seed' => 75]);
    mqAssertSame(75, (int)$db->query("SELECT notoriety_seed FROM mq_families WHERE id=$family")->fetchColumn());
    $beforeRows = $db->query('SELECT * FROM mq_tracks ORDER BY id')->fetchAll();
    $migrationDb = new PDO('mysql:host=127.0.0.1;port=33307;dbname=mq_ui_test;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $migrationDb->query(file_get_contents(__DIR__ . '/../sql/024_melodyquest_notoriety.sql'));
    do { if ($stmt->columnCount()) $stmt->fetchAll(); } while ($stmt->nextRowset());
    $stmt = $migrationDb->query(file_get_contents(__DIR__ . '/../sql/025_melodyquest_notoriety_baseline.sql'));
    do { if ($stmt->columnCount()) $stmt->fetchAll(); } while ($stmt->nextRowset());
    mqAssertSame($beforeRows, $db->query('SELECT * FROM mq_tracks ORDER BY id')->fetchAll());
    mqAssertSame(75, (int)$db->query("SELECT notoriety_seed FROM mq_families WHERE id=$family")->fetchColumn());
    mqAssertSame(1, (int)$db->query("SELECT COUNT(*) FROM mq_family_knowledge WHERE family_id=$family")->fetchColumn());
});

mqTest('La remise en verification exige un backup et preserve toutes les metadonnees', function () use ($db): void {
    require_once __DIR__ . '/../scripts/lib/CatalogRecheck.php';
    $operation = new CatalogRecheck($db);
    $count = $operation->inspect()['tracks'];
    $backup = sys_get_temp_dir() . '/mq-recheck-' . bin2hex(random_bytes(8)) . '.json';
    mqAssertThrows(RuntimeException::class, fn() => $operation->apply($backup, $count));
    mqAssertFalse(file_exists($backup));
    $statuses = $db->query('SELECT id,status FROM mq_lobbies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->exec('UPDATE mq_lobbies SET status="finished" WHERE status="playing"');
    mqAssertThrows(RuntimeException::class, fn() => $operation->apply($backup, $count + 1));
    mqAssertFalse(file_exists($backup));
    $result = $operation->apply($backup, $count);
    mqAssertSame($count, $result['pending']);
    $saved = json_decode(file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    mqAssertSame($count, count($saved['tracks']));
    mqAssertThrows(RuntimeException::class, fn() => $operation->apply($backup, $count));
    // Restore only the disposable fixture state for subsequent interactive checks.
    $restore = $db->prepare('UPDATE mq_tracks SET is_validated=?,validated_by=?,validated_at=?,updated_at=? WHERE id=?');
    foreach ($saved['tracks'] as $track) $restore->execute([$track['is_validated'],$track['validated_by'],$track['validated_at'],$track['updated_at'],$track['id']]);
    $restore = $db->prepare('UPDATE mq_lobbies SET status=? WHERE id=?');
    foreach ($statuses as $id => $status) $restore->execute([$status,$id]);
    unlink($backup);
});

echo "Local fixture lobby: $lobbyId / $code; owner actor: $owner; guest actor: $guest\n";
mqFinishTests();
