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
require_once __DIR__ . '/middlewares/AdminMiddleware.php';
require_once __DIR__ . '/controllers/LobbyController.php';
require_once __DIR__ . '/controllers/CatalogController.php';

$body = get_body();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = get_action($method, $body);
$isStreamAction = $method === 'GET' && in_array($action, ['streamLobby', 'streamPublicLobbies'], true);

if (!$isStreamAction) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $lobbyController = new LobbyController();
    $catalogController = new CatalogController();

    switch ($method) {
        case 'GET':
            switch ($action) {
                case 'getLobbyByCode':
                    $userId = AuthMiddleware::check();
                    $lobbyController->getByCode($userId, $_GET);
                    break;
                case 'getPlaybackState':
                    $userId = AuthMiddleware::check();
                    $lobbyController->getPlayback($userId, $_GET);
                    break;
                case 'listTrackPool':
                    $userId = AuthMiddleware::check();
                    $lobbyController->listTrackPool($userId, $_GET);
                    break;
                case 'getRoundState':
                    $userId = AuthMiddleware::check();
                    $lobbyController->getRoundState($userId, $_GET);
                    break;
                case 'getScoreboard':
                    $userId = AuthMiddleware::check();
                    $lobbyController->getScoreboard($userId, $_GET);
                    break;
                case 'listPublicLobbies':
                    AuthMiddleware::check();
                    $lobbyController->listPublicLobbies();
                    break;
                case 'streamLobby':
                    $userId = AuthMiddleware::check();
                    $lobbyController->streamLobby($userId, $_GET);
                    break;
                case 'streamPublicLobbies':
                    AuthMiddleware::check();
                    $lobbyController->streamPublicLobbies();
                    break;
                case 'listCategories':
                    AuthMiddleware::check();
                    $catalogController->listCategories();
                    break;
                case 'listFamilies':
                    AuthMiddleware::check();
                    $catalogController->listFamilies($_GET);
                    break;
                case 'listTracks':
                    AuthMiddleware::check();
                    $catalogController->listTracks($_GET);
                    break;
                case 'listPendingTracks':
                    $userId = AuthMiddleware::check();
                    AdminMiddleware::check($userId);
                    $catalogController->listPendingTracks();
                    break;
                default:
                    json_error('Unknown action for GET method', 404);
            }
            break;

        case 'POST':
            switch ($action) {
                case 'createLobby':
                    $userId = AuthMiddleware::check();
                    $lobbyController->create($userId, $body);
                    break;
                case 'joinLobby':
                    $userId = AuthMiddleware::check();
                    $lobbyController->join($userId, $body);
                    break;
                case 'leaveLobby':
                    $userId = AuthMiddleware::check();
                    $lobbyController->leave($userId, $body);
                    break;
                case 'touchLobby':
                    $userId = AuthMiddleware::check();
                    $lobbyController->touch($userId, $body);
                    break;
                case 'kickPlayer':
                    $userId = AuthMiddleware::check();
                    $lobbyController->kickPlayer($userId, $body);
                    break;
                case 'deleteLobby':
                    $userId = AuthMiddleware::check();
                    $lobbyController->delete($userId, $body);
                    break;
                case 'syncPlayback':
                    $userId = AuthMiddleware::check();
                    $lobbyController->syncPlayback($userId, $body);
                    break;
                case 'addTrackToPool':
                    $userId = AuthMiddleware::check();
                    $lobbyController->addTrackToPool($userId, $body);
                    break;
                case 'removeTrackFromPool':
                    $userId = AuthMiddleware::check();
                    $lobbyController->removeTrackFromPool($userId, $body);
                    break;
                case 'startRound':
                    $userId = AuthMiddleware::check();
                    $lobbyController->startRound($userId, $body);
                    break;
                case 'revealRound':
                    $userId = AuthMiddleware::check();
                    $lobbyController->revealRound($userId, $body);
                    break;
                case 'finishRound':
                    $userId = AuthMiddleware::check();
                    $lobbyController->finishRound($userId, $body);
                    break;
                case 'voteNextRound':
                    $userId = AuthMiddleware::check();
                    $lobbyController->voteNextRound($userId, $body);
                    break;
                case 'submitAnswer':
                    $userId = AuthMiddleware::check();
                    $lobbyController->submitAnswer($userId, $body);
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
                default:
                    json_error('Unknown action for POST method', 404);
            }
            break;

        case 'PUT':
            switch ($action) {
                case 'updateLobbyConfig':
                    $userId = AuthMiddleware::check();
                    $lobbyController->updateConfig($userId, $body);
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
    json_error('Database Error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    json_error('Unknown Error: ' . $e->getMessage(), 500);
}
