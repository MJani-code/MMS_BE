<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../api/user/auth/auth.php');


$response = [];

$jsonData = file_get_contents("php://input");
$updateItem = json_decode($jsonData, true);

class updateTaskInBatch
{
    private $conn;
    private $response;
    private $auth;
    private $userAuthData;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    private function permissionCheck()
    {
        $userId = null;
        $isAccess = $this->auth->authenticate(2);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }
    }

    private function validationError($updateItem)
    {
        //Kötelező mezők ellenőrzése
        $mandatoryFields = ['taskIds', 'value'];
        foreach ($mandatoryFields as $field) {
            if (!isset($updateItem[$field]) || empty($updateItem[$field])) {
                $this->response = [
                    'status' => 400,
                    'message' => "Hiányzó vagy üres mező: '$field'."
                ];
                return $this->response;
            }
        }

        //Statusszín lekérdezése
        $statusId = intval($updateItem['value']);
        $statusQuery = "SELECT color, name FROM task_statuses WHERE id = :statusId";
        $stmt = $this->conn->prepare($statusQuery);
        $stmt->bindValue(":statusId", $statusId);
        $stmt->execute();
        $statusResult = $stmt->fetch(PDO::FETCH_ASSOC);
        if (count($statusResult) === 0) {
            $this->response = [
                'status' => 400,
                'message' => "Érvénytelen statusId érték."
            ];
            return $this->response;
        }
        return $this->response = ['status' => 200, 'message' => 'Validáció sikeres.', 'data' => $statusResult];
    }

    private function executeUpdate($updateItem)
    {
        try {
            $taskIds = implode(',', array_map('intval', $updateItem['taskIds']));
            $statusId = intval($updateItem['value']);
            $sql = "UPDATE tasks SET status_by_exohu_id = :status_by_exohu_id WHERE id IN ($taskIds)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(":status_by_exohu_id", $statusId);
            $stmt->execute();
        } catch (\Throwable $th) {
            return throw new Exception("Adatbázis hiba: " . $th->getMessage());
        }

        if ($this->conn->affected_rows === 0) {
            throw new Exception("Nincs frissíthető feladat.");
        }
    }

    public function updateTaskData($updateItem)
    {
        //Update Engedély ellenőrzése
        $permissionCheck = $this->permissionCheck();
        if ($permissionCheck) {
            return;
        }
        //Adat validáció
        $validationError = $this->validationError($updateItem);
        if ($validationError['status'] !== 200) {
            return;
        }

        $color = $validationError['data']['color'];
        $name = $validationError['data']['name'];

        //Adatok frissítése
        try {
            $this->executeUpdate($updateItem);
            $this->response = [
                'status' => 200,
                'message' => "A feladatok sikeresen frissítve lettek.",
                'payload' => [
                    'taskIds' => $updateItem['taskIds'],
                    'value' => $updateItem['value'],
                    'column' => $updateItem['column'],
                    'status_exohu' => $name,
                    'color' => $color
                ]
            ];
            return;
        } catch (Exception $e) {
            $this->response = [
                'status' => 500,
                'message' => "Hiba történt a frissítés során: " . $e->getMessage()
            ];
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$updateTask = new updateTaskInBatch($conn, $response, $auth);
$updateTask->updateTaskData($updateItem);

echo json_encode($response);
