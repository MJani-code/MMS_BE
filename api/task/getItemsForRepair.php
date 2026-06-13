<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$repairData = json_decode($jsonData, true);

class getItems
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
    public function getItemsForRepair($repairData)
    {
        $userId = null;
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }
        try {
            $stmt = $this->conn->prepare("SELECT * FROM interventions where is_active = 1");            
            $stmt->execute();
            $interventions = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error: " . $e->getMessage());
        }
    }

    
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$itemsForRepair = new getItems($conn, $response, $auth, $token);
$itemsForRepair->getItemsForRepair($repairData);

echo json_encode($response);
