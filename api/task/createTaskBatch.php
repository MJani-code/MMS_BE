<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/api/user/auth/auth.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/vendor/autoload.php');


use PhpOffice\PhpSpreadsheet\IOFactory;

$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // $jsonData = file_get_contents("php://input");
    // $data = json_decode($jsonData, true);

    // $isFileUpload = $_POST['fileUpload'] ?? NULL;
    // $locationId = $_POST['locationId'] ?? NULL;
    // $file = $_FILES['file'] ?? NULL;


    class CreateTaskBatch
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

        public function createTaskBatch()
        {
            $userId = null;
            $isAccess = $this->auth->authenticate(14);
            if ($isAccess['status'] !== 200) {
                return $this->response = $isAccess;
            } else {
                $userId = $isAccess['data']->userId;
            }

            //Csatolt file feldolgozása
            $filePath = $_FILES['file']['tmp_name'];
            $result = xlsFileRead($filePath);
            $this->response = $result;
        }
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$createTaskBatch = new CreateTaskBatch($conn, $response, $auth);
$createTaskBatch->createTaskBatch();

echo json_encode($response);
