<?php
include_once('Controller/UserController.php');

header("Access-Control-Allow-Origin: https://melodyquest.shinederu.lol");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {

    switch ($_SERVER['REQUEST_METHOD']) {

        case 'GET':
            switch ($_GET['action']) {
                case 'logout':
                    sendResponse((new UserController())->logout());
                    break;
            }

            break;

        case 'POST':
            switch ($_POST['action']) {
                case 'register':
                    sendResponse((new UserController())->register($_POST));
                    break;

                case 'login':
                    sendResponse((new UserController())->login($_POST));
                    break;
            }
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    sendResponse(['message' => 'Database Error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    sendResponse(['message' => 'Application Error: ' . $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    sendResponse(['message' => 'Unknown Error: ' . $e->getMessage()]);
} finally {
    exit;
}

function sendResponse($infos)
{
    echo json_encode([
        'message' => $infos['message'] ?? '',
        'data' => $infos['data'] ?? ''
    ]);
}
?>