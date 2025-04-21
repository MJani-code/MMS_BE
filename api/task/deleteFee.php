<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$data = json_decode($jsonData, true);

$id = $data['id'];
$taskId = $data['taskId'];
$dbTable = 'task_fees';

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
            $isTheTaskVisibleForUser = $this->auth->isTheTaskVisibleForUser($taskId, null, $isAccess['data']->companyId, $isAccess['data']->permissions);
            if ($isTheTaskVisibleForUser['status'] !== 200) {
                return $this->response = $isTheTaskVisibleForUser;
            }
        }
        $result = deleteFee($conn, $dbTable, $id, $taskId, $userId);
        $this->response = $result;
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new DeleteFee($conn, $response, $auth);
$getAllTask->deleteFeeFunction($conn, $dbTable, $id, $taskId);
echo json_encode($response);
