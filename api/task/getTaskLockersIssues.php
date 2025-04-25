<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

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
    public function getIssues($payload)
    {
        $userId = null;
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }
        try {
            $stmt = $this->conn->prepare(
                "SELECT tli.id, lit.name
                FROM task_lockers_issues tli
                LEFT JOIN locker_issue_types lit ON lit.id = tli.issue_type
                WHERE task_id = :task_id
                AND uuid = :uuid
                "
            );
            $stmt->bindParam(':task_id', $payload['taskId'], PDO::PARAM_INT);
            $stmt->bindParam(':uuid', $payload['uuid'], PDO::PARAM_INT);            
            $stmt->execute();
            //tömbben tárolja az adatokat
            $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);            
            if ($issues) {
                return $this->response = $this->createResponse(200, "Issues fetched successfully", $issues);
            } else {
                return $this->response = $this->createResponse(404, "No issues found");
            }
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
$itemsForRepair->getIssues($payload);

echo json_encode($response);
