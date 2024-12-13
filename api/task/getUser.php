<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class GetAllTask
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
    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
    }
    public function getTaskData()
    {
        //User validation here
        $isAccess = $this->auth->authenticate(4);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        //Data gathering
        try {
            $userData = [
                'table' => "users u",
                'method' => "get",
                'columns' => ['u.id', 'u.first_name as firstName', 'u.last_name as lastName', 'u.email as email', ' "" as password', ' "" as newPassword', ' "" as newPasswordConfirm'],
                'others' => "",
                'conditions' => "u.id = $userId"
            ];
            $result = dataToHandleInDb($this->conn, $userData);

            if ($result['status'] !== 200) {
                return $this->response = $this->createResponse(400, $result['errorInfo']);
            }

            return $this->response = $result;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'data' => NULL
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new GetAllTask($conn, $response, $auth);
$getAllTask->getTaskData();

echo json_encode($response);
