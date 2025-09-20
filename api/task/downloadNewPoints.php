<?php
require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];
// input data feldolgozása
$input = file_get_contents('php://input');
$payload = json_decode($input, true);
$data = $payload;

class downloadNewPoints
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


    public function downloadNewPointsFunction($data)
    {

        $userId = null;
        $isAccess = $this->auth->authenticate(25);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;            
        }
        $result = downloadNewPoints($data);
        $this->response = $result;
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$downloadNewPoints = new downloadNewPoints($conn, $response, $auth);
$downloadNewPoints->downloadNewPointsFunction($data);
echo json_encode($response);
