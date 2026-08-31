<?php

// Align env loading with API/auth:
// use the same vendor + .env source to keep DB/runtime config identical.
$authVendorAutoload = __DIR__ . '/../auth/vendor/autoload.php';
if (file_exists($authVendorAutoload)) {
    require_once $authVendorAutoload;

    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../auth');
        $dotenv->safeLoad();
    }
}

require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/request.php';
require_once __DIR__ . '/middlewares/AuthMiddleware.php';
require_once __DIR__ . '/middlewares/PlayerMiddleware.php';
require_once __DIR__ . '/middlewares/AdminMiddleware.php';
require_once __DIR__ . '/controllers/LobbyController.php';
require_once __DIR__ . '/controllers/CatalogController.php';
require_once __DIR__ . '/controllers/SuggestionController.php';
require_once __DIR__ . '/controllers/AdminInsightsController.php';
require_once __DIR__ . '/controllers/TvController.php';

$body = get_body();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = get_action($method, $body);
header('Content-Type: application/json; charset=utf-8');

try {
    $lobbyController = new LobbyController();
    $catalogController = new CatalogController();
    $suggestionController = new SuggestionController();
    $adminInsightsController = new AdminInsightsController();
    $tvController = new TvController();

    switch ($method) {
        case 'GET':
            switch ($action) {
                case 'getPlayerIdentity':
                    json_success(null, ['identity' => PlayerMiddleware::optional()]);
                    break;
                case 'getLobbyByCode':
                    $identity = PlayerMiddleware::check($_GET['guest_nickname'] ?? null);
                    $lobbyController->getByCode((int)$identity['actor_id'], $_GET);
                    break;
                case 'getPlaybackState':
                    $identity = PlayerMiddleware::check($_GET['guest_nickname'] ?? null);
                    $lobbyController->getPlayback((int)$identity['actor_id'], $_GET);
                    break;
                case 'listTrackPool':
                    $identity = PlayerMiddleware::check($_GET['guest_nickname'] ?? null);
                    $lobbyController->listTrackPool((int)$identity['actor_id'], $_GET);
                    break;
                case 'getRoundState':
                    $identity = PlayerMiddleware::check($_GET['guest_nickname'] ?? null);
                    $lobbyController->getRoundState((int)$identity['actor_id'], $_GET);
                    break;
                case 'getScoreboard':
                    $identity = PlayerMiddleware::check($_GET['guest_nickname'] ?? null);
                    $lobbyController->getScoreboard((int)$identity['actor_id'], $_GET);
                    break;
                case 'listPublicLobbies':
                    $lobbyController->listPublicLobbies();
                    break;
                case 'listCategories':
                    $catalogController->listCategories();
                    break;
                case 'listFamilies':
                    $catalogController->listFamilies($_GET);
                    break;
                case 'listTracks':
                    $catalogController->listTracks($_GET);
                    break;
                case 'listPendingTracks':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->listPendingTracks();
                    break;
                case 'listSuggestions':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $suggestionController->list($_GET);
                    break;
                case 'listAnswerAttempts':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $adminInsightsController->listAnswerAttempts($_GET);
                    break;
                case 'getTvPairing':
                    $tvController->getPairing($_GET);
                    break;
                case 'getTvState':
                    $tvController->getState($_GET);
                    break;
                default:
                    json_error('Unknown action for GET method', 404);
            }
            break;

        case 'POST':
            switch ($action) {
                case 'updateGuestNickname':
                    $nickname = (string)($body['nickname'] ?? '');
                    if ($nickname === '') {
                        json_error('nickname requis', 400);
                    }
                    json_success('Pseudo invité mis à jour', ['identity' => PlayerMiddleware::renameGuest($nickname)]);
                    break;
                case 'endGuestSession':
                    PlayerMiddleware::endGuest();
                    json_success('Session invitée terminée', ['ended' => true]);
                    break;
                case 'createLobby':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->create($identity, $body);
                    break;
                case 'joinLobby':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->join($identity, $body);
                    break;
                case 'leaveLobby':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->leave((int)$identity['actor_id'], $body);
                    break;
                case 'touchLobby':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->touch((int)$identity['actor_id'], $body);
                    break;
                case 'kickPlayer':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->kickPlayer((int)$identity['actor_id'], $body);
                    break;
                case 'deleteLobby':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->delete((int)$identity['actor_id'], $body);
                    break;
                case 'resetLobbyForReplay':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->resetForReplay((int)$identity['actor_id'], $body);
                    break;
                case 'syncPlayback':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->syncPlayback((int)$identity['actor_id'], $body);
                    break;
                case 'addTrackToPool':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->addTrackToPool((int)$identity['actor_id'], $body);
                    break;
                case 'removeTrackFromPool':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->removeTrackFromPool((int)$identity['actor_id'], $body);
                    break;
                case 'startRound':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->startRound((int)$identity['actor_id'], $body);
                    break;
                case 'revealRound':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->revealRound((int)$identity['actor_id'], $body);
                    break;
                case 'finishRound':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->finishRound((int)$identity['actor_id'], $body);
                    break;
                case 'voteNextRound':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->voteNextRound((int)$identity['actor_id'], $body);
                    break;
                case 'voteRevealRound':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->voteRevealRound((int)$identity['actor_id'], $body);
                    break;
                case 'submitAnswer':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->submitAnswer((int)$identity['actor_id'], $body);
                    break;
                case 'holdSuggestion':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->holdSuggestion((int)$identity['actor_id'], $body);
                    break;
                case 'releaseSuggestionHold':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->releaseSuggestionHold((int)$identity['actor_id'], $body);
                    break;
                case 'submitSuggestion':
                    $identity = PlayerMiddleware::optional(false);
                    $suggestionController->submit($identity !== null ? (int)$identity['actor_id'] : null, $body);
                    break;
                case 'createTvPairing':
                    $tvController->createPairing();
                    break;
                case 'linkTvPairing':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $tvController->linkPairing((int)$identity['actor_id'], $body);
                    break;
                case 'createCategory':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->createCategory($userId, $body);
                    break;
                case 'createFamily':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->createFamily($userId, $body);
                    break;
                case 'createTrack':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->createTrack($userId, $body);
                    break;
                case 'addFamilyAlias':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->addFamilyAlias($userId, $body);
                    break;
                case 'validateTrack':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->validateTrack($userId, $body);
                    break;
                case 'unvalidateTrack':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->unvalidateTrack($body);
                    break;
                case 'updateSuggestionStatus':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $suggestionController->updateStatus($userId, $body);
                    break;
                case 'updateSuggestion':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $suggestionController->update($body);
                    break;
                case 'applySuggestion':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $suggestionController->apply($userId, $body);
                    break;
                default:
                    json_error('Unknown action for POST method', 404);
            }
            break;

        case 'PUT':
            switch ($action) {
                case 'updateLobbyConfig':
                    $identity = PlayerMiddleware::check($body['guest_nickname'] ?? null);
                    $lobbyController->updateConfig((int)$identity['actor_id'], $body);
                    break;
                case 'updateCategory':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->updateCategory($body);
                    break;
                case 'updateFamily':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->updateFamily($userId, $body);
                    break;
                case 'updateTrack':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->updateTrack($userId, $body);
                    break;
                default:
                    json_error('Unknown action for PUT method', 404);
            }
            break;

        case 'DELETE':
            switch ($action) {
                case 'deleteCategory':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->deleteCategory(array_merge($_GET, $body));
                    break;
                case 'deleteFamily':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->deleteFamily(array_merge($_GET, $body));
                    break;
                case 'deleteTrack':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->deleteTrack(array_merge($_GET, $body));
                    break;
                default:
                    json_error('Unknown action for DELETE method', 404);
            }
            break;

        default:
            json_error('Method not allowed', 405);
    }
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (PDOException $e) {
    error_log('MelodyQuest database error: ' . $e->getMessage());
    json_error('Erreur serveur', 500);
} catch (Throwable $e) {
    error_log('MelodyQuest unexpected error: ' . $e->getMessage());
    json_error('Erreur serveur', 500);
}
