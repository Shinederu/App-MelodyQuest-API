<?php
include_once('../Infrastructure/Database/DatabaseConnection.php');
class UserRepository
{
    private $db;

    public function __construct()
    {
        $db = DatabaseConnection::getInstance();
    }

    public function checkUsernameExists($username)
    {
        $countData = $this->db->selectSingleQuery("SELECT COUNT(*) AS count FROM users WHERE username = :username", ['username' => $username]);

        return $countData['count'] > 0;
    }


    public function createUser($username, $email, $hashedPassword)
    {
        return $this->db->executeQuery("INSERT INTO users (username, email, password) VALUES (?, ?, ?);", [$username, $email, $hashedPassword]);

    }

}



?>