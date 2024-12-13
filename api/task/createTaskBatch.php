<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');
require('../../vendor/autoload.php');


use PhpOffice\PhpSpreadsheet\IOFactory;

$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


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
        $result = xlsFileDataToWrite($this->conn, $filePath, $userId);
        $this->response = $result;
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$createTaskBatch = new CreateTaskBatch($conn, $response, $auth);
$createTaskBatch->createTaskBatch();

echo json_encode($response);
