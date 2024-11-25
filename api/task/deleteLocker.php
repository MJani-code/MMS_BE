<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
//require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/db/dbFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/api/user/auth/auth.php');

$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = file_get_contents("php://input");
    $newItems = json_decode($jsonData, true);

    class RemoveLocker
    {
        private $conn;
        private $response;
        private $auth;
        private $userAuthData;
        private $userId;

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
            $this->userAuthData = $this->auth->authenticate();
            $this->userId = $this->userAuthData['data']->userId;
            $userId = $this->userId;

            if ($this->userAuthData['status'] !== 200) {
                return $this->response = array(
                    'status' => $this->userAuthData['status'],
                    'message' => $this->userAuthData['message'],
                    'data' => NULL
                );;
            }

            $result = removeLocker($conn, $newItems, $userId);
            $this->response = $result;
        }
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$permissionId = 10;
$auth = new Auth($conn, $token, $secretkey, $permissionId);

$removeLocker = new RemoveLocker($conn, $response, $auth);
$removeLocker->removeLockerFunction($conn, $newItems);
echo json_encode($response);
