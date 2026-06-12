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
        $locale = $repairData['locale'] ?? 'hu';
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
            $this->response = $this->createResponse(200, localizeSuccessMessage('success.data_loaded_successfully', $locale), [
                'interventions' => $interventions
            ]);
            return $this->response;
        } catch (PDOException $e) {
            return $this->response = createLocalizedErrorResponse(500, 'errors.database_generic', $locale, ['message' => 'getItemsForRepair'], $e->getMessage());
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
