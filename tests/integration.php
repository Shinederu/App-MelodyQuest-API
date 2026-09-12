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

echo "Local fixture lobby: $lobbyId / $code; owner actor: $owner; guest actor: $guest\n";
mqFinishTests();
