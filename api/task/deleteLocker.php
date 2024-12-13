<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = file_get_contents("php://input");
    $newItems = json_decode($jsonData, true);

    class RemoveLocker
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

        //User validation here
        public function Auth()
        {
            return $this->auth->authenticate();
        }

        public function removeLockerFunction($conn, $newItems)
        {
            $userId = null;
            $isAccess = $this->auth->authenticate(10);
            if ($isAccess['status'] !== 200) {
                return $this->response = $isAccess;
            } else {
                $userId = $isAccess['data']->userId;
            }

            $result = removeLocker($conn, $newItems, $userId);
            $this->response = $result;
        }
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$removeLocker = new RemoveLocker($conn, $response, $auth);
$removeLocker->removeLockerFunction($conn, $newItems);
echo json_encode($response);
