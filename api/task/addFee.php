<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/api/user/auth/auth.php');

$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = file_get_contents("php://input");
    $newItems = json_decode($jsonData, true);

    $dbTable = 'Task_fees';

    class AddFee
    {
        private $conn;
        private $response;
        private $auth;
        private $userAuthData;

        public function __construct($conn, &$response, $auth)
        {
            $this->conn = $conn;
            $this->response = &$response;
            $this->auth = $auth;
        }

        public function addFeeFunction($conn, $dbTable, $newItems)
        {
            $userId = null;
            $isAccess = $this->auth->authenticate(11);
            if ($isAccess['status'] !== 200) {
                return $this->response = $isAccess;
            } else {
                $userId = $isAccess['data']->userId;
            }
            $result = addFee($conn, $dbTable, $newItems, $userId);
            $this->response = $result;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new AddFee($conn, $response, $auth);
$getAllTask->addFeeFunction($conn, $dbTable, $newItems);
echo json_encode($response);
