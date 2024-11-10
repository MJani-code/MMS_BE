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
    $newItems = json_decode($jsonData, true);

    $userId = 1;
    $dbTable = 'Task_fees';

    class AddFee
    {
        private $conn;
        private $response;

        public function __construct($conn, &$response)
        {
            $this->conn = $conn;
            $this->response = &$response;
        }

        //TODO: user validation here

        public function addFeeFunction($conn, $dbTable, $newItems, $userId)
        {
            $result = addFee($conn, $dbTable, $newItems, $userId);
            $this->response = $result;
        }
    }
}

$getAllTask = new AddFee($conn, $response);
$getAllTask->addFeeFunction($conn, $dbTable, $newItems, $userId);
echo json_encode($response);
