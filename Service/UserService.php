<?php
require_once('../Repository/UserRepository.php');
require_once('../Infrastructure/Http/SessionManager.php');
class UserService
{
    private $userRepository;
    private $sessionManager;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->sessionManager = SessionManager::getInstance();
    }

    public function registerUser(array $data)
    {
        $data['username'] = trim(htmlspecialchars($data['username'] ?? '', ENT_QUOTES, 'UTF-8'));

        if (!isset($data['username']) || !isset($data['password'])) {
            http_response_code(400);
            return ['message' => "Username and password are required"];
        }

        if (strlen($data['password']) < 8) {
            http_response_code(400);
            return ['message' => "Password must be at least 8 characters long."];
        }

        if ($this->userRepository->checkUsernameExists($data['username'])) {
            http_response_code(400);
            return ['message' => "Username already exists. Please choose another one or login."];
        }

        if (!$this->userRepository->createUser($data['username'], password_hash($data['password'], PASSWORD_BCRYPT))) {
            http_response_code(500);
            return ['message' => "An error occurred while creating the account. Please try again later."];
        }

        http_response_code(201);
        return ['message' => "Account successfully created."];
    }

    public function loginUser(array $data)
    {

        $returnMessage = "User successfully logged in.";

        if ($this->sessionManager->get('userid')) {
            $returnMessage = "User already logged in.";
            http_response_code(400);
            return ['message' => $returnMessage];
        }

        $data['username'] = trim(htmlspecialchars($data['username'] ?? '', ENT_QUOTES, 'UTF-8'));

        if (!isset($data['username']) || !isset($data['password'])) {
            $returnMessage = "Username and password are required";
            http_response_code(400);
            return ['message' => $returnMessage];
        }

        $localUser = $this->userRepository->getUserByUsername($data['username']);
        if (!$localUser || !password_verify($data['password'], $localUser['password'])) {
            $returnMessage = "Invalid username or password.";
            http_response_code(403);
            return ['message' => $returnMessage];
        }

        $this->sessionManager->set('userid', $localUser['id']);
        $this->sessionManager->set('isAdmin', $localUser['isAdmin']);

        http_response_code(200);
        return ['message' => $returnMessage];

    }

    public function logoutUser()
    {
        $this->sessionManager->destroy();

        http_response_code(200);
        return ['message' => "User successfully logged out."];
    }
}
?>