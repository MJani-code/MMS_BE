<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class UpdateTask
{
    private $conn;
    private $response;
    private $auth;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
    }

    public function updateUser()
    {
        //User validation here
        $isAccess = $this->auth->authenticate(4);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        try {
            //Get user password
            $resultOfGetUserPassword = getUserPassword($this->conn, $userId);
            $storedPassword = $resultOfGetUserPassword['payload']['password'];

            $jsonData = file_get_contents("php://input");
            $data = json_decode($jsonData, true);

            $password = $data['password'];
            $newPassword = $data['newPassword'] ?? null;
            $newConfirmedPassword = $data['newPasswordConfirm'];

            $firstName = $data['firstName'];
            $lastName = $data['lastName'];
            $email = $data['email'];

            //Check if user provided password
            if ($password === "") {
                $errorInfo = "Hibás jelszó";
                return $this->response = $this->createResponse(400, $errorInfo);
            }

            //Check if the new and the confirmedNew is mathced
            if ($newPassword != null && $newPassword !== $newConfirmedPassword) {
                $errorInfo = "A két új jelszó értéke nem egyezik";
                return $this->response = $this->createResponse(400, $errorInfo);
            }

            //Check if the original password is matched with the sent one
            if ($resultOfGetUserPassword['status'] === 200 && !password_verify($password, $storedPassword)) {
                $errorInfo = "Hibás jelszó";
                return $this->response = $this->createResponse(400, $errorInfo);
            }

            //If there is no newPassword sent original password is aplied
            if ($newPassword === "") {
                $newPassword = $password;
            }

            $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT, array('cost' => 7)) ?? '';
            $resultOfUpdateUser = updateUser($this->conn, $hashedNewPassword, $firstName, $lastName, $email, $userId);
            return $this->response = $resultOfUpdateUser;

            //return $this->response = $result;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'errorInfo' => $errorInfo,
                'data' => NULL
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$updateuser = new UpdateTask($conn, $response, $auth);
$updateuser->updateUser();

echo json_encode($response);
