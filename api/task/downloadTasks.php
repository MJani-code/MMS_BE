<?php
//header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];
// input data feldolgozása
$input = file_get_contents('php://input');
$inputData = json_decode($input, true);
$inputData[] = array(
    'statuses' => [6]
);

class DownloadTasks
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


    public function downloadTasksFunction($conn, $inputData)
    {

        $userId = null;
        $isAccess = $this->auth->authenticate(24);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;            
        }

        $result = downloadTasks($conn, $inputData);
        $this->response = $result;
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$downloadTasks = new DownloadTasks($conn, $response, $auth);
$downloadTasks->downloadTasksFunction($conn, $inputData);
echo json_encode($response);
