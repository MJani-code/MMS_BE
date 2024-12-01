<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/api/user/auth/auth.php');


$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class GetAllTask
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

    public function getTaskData()
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
            $newPassword = $data['newPassword'];
            $newConfirmedPassword = $data['newPasswordConfirmed'];

            //Check if the new and the confirmedNew is mathced
            if ($newPassword !== $newConfirmedPassword) {
                $errorInfo = "The confirmed password does not match with the password";
                return $this->response = $this->createResponse(400, $errorInfo);
            }

            //Check if the original password is matched with the sent one
            if ($resultOfGetUserPassword['status'] === 200 && !password_verify($password, $storedPassword)) {
                $errorInfo = "The password validation was not success";
                return $this->response = $this->createResponse(400, $errorInfo);
            }

            $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT, array('cost' => 7)) ?? '';
            $resultOfUpdateUser = updateUser($this->conn, $hashedNewPassword, $userId);
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

$getAllTask = new GetAllTask($conn, $response, $auth);
$getAllTask->getTaskData();

echo json_encode($response);
