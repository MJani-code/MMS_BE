<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
//require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/db/dbFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');


$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = file_get_contents("php://input");
    $data = json_decode($jsonData, true);

    $id = $data['id'];
    $taskId = $data['taskId'];
    $userId = 1;
    $dbTable = 'Task_fees';

    class DeleteFee
    {
        private $conn;
        private $response;

        public function __construct($conn, &$response)
        {
            $this->conn = $conn;
            $this->response = &$response;
        }

        //TODO: user validation here

        public function deleteFeeFunction($conn, $dbTable, $id, $taskId, $userId)
        {
            $result = deleteFee($conn, $dbTable, $id, $taskId, $userId);
            $this->response = $result;
        }
    }
}

$getAllTask = new DeleteFee($conn, $response);
$getAllTask->deleteFeeFunction($conn, $dbTable, $id, $taskId, $userId);
echo json_encode($response);
