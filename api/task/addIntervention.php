<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class addIntervention
{
    private $conn;
    private $response;
    private $auth;
    private $token;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'payload' => $data
        ];
    }
    public function addInterventionFunction($payload)
    {
        $userId = null;
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        $result = addIntervention($this->conn,$payload['taskId'], $payload['interventions'], $userId);
        if ($result['status'] !== 200) {
            return $this->response = $this->createResponse($result['status'], $result['message']);
        } else {
            return $this->response = $this->createResponse(200, "Intervention added successfully", $result['payload']);
        }
        
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$addIntervention = new addIntervention($conn, $response, $auth, $token);
$addIntervention->addInterventionFunction($payload);

echo json_encode($response);
