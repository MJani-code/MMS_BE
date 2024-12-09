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
    $data = json_decode($jsonData, true);

    $id = $data['id'];
    $taskId = $data['taskId'];
    $dbTable = 'Task_fees';

    class DeleteFee
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

        //TODO: user validation here

        public function deleteFeeFunction($conn, $dbTable, $id, $taskId)
        {
            $userId = null;
            $isAccess = $this->auth->authenticate(12);
            if ($isAccess['status'] !== 200) {
                return $this->response = $isAccess;
            } else {
                $userId = $isAccess['data']->userId;
            }
            $result = deleteFee($conn, $dbTable, $id, $taskId, $userId);
            $this->response = $result;
        }
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new DeleteFee($conn, $response, $auth);
$getAllTask->deleteFeeFunction($conn, $dbTable, $id, $taskId);
echo json_encode($response);
