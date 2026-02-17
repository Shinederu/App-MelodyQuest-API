<?php
include_once('../Service/UserService.php');

class UserController
{

    private $userService;

    public function __construct()
    {
        $this->userService = new UserService();

    }

    public function register(array $data)
    {
        $infos = $this->userService->registerUser($data);
        return ['message' => $infos['message']];
    }
    public function login(array $data)
    {
        $infos = $this->userService->loginUser($data);
        return ['message' => $infos['message']];
    }

    public function logout()
    {
        $infos = $this->userService->logoutUser();
        return ['message' => $infos['message']];
    }

}

?>