<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');
require('../../lib/NotificationGenerator.php');


$response = [];

$jsonData = file_get_contents("php://input");
$updateItem = json_decode($jsonData, true);

class updateTaskInBatch
{
    private $conn;
    private $response;
    private $auth;
    private $userAuthData;
    private $userId;
    private $changedTaskIds = [];

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
            $this->userId = $userId;
            $this->userAuthData = $isAccess['data'];
            return null;
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
                    'message' => localizeErrorMessage('errors.data_update_missing_field', null, ['field' => $field])
                ];
                return $this->response;
            }
        }
        if (!is_array($updateItem['taskIds'])) {
            $this->response = [
                'status' => 400,
                'message' => localizeErrorMessage('errors.data_update_missing_field', $updateItem['locale'] ?? 'hu', ['field' => 'taskIds'])
            ];
            return $this->response;
        }

        //Statusszín lekérdezése
        $statusId = intval($updateItem['value']);
        $statusQuery =
            "SELECT ts.color, t.text as name
        FROM task_statuses ts
        LEFT JOIN translations t ON t.task_status_id = ts.id AND t.locale = :locale
        WHERE ts.id = :statusId
        ";
        $stmt = $this->conn->prepare($statusQuery);
        $stmt->bindValue(":statusId", $statusId);
        $stmt->bindValue(":locale", $updateItem['locale'] ?? 'hu');
        $stmt->execute();
        $statusResult = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$statusResult) {
            $this->response = [
                'status' => 400,
                'message' => localizeErrorMessage('errors.invalid_status_id', $updateItem['locale'] ?? 'hu')
            ];
            return $this->response;
        }
        return $this->response = ['status' => 200, 'message' => localizeSuccessMessage('success.query_successful', $updateItem['locale'] ?? 'hu'), 'data' => $statusResult];
    }

    private function executeUpdate($updateItem, $userId)
    {
        try {
            $taskIds = array_values(array_unique(array_map('intval', $updateItem['taskIds'])));
            if (empty($taskIds)) {
                throw new Exception(localizeErrorMessage('errors.no_updatable_task', $updateItem['locale'] ?? 'hu'));
            }
            $statusId = intval($updateItem['value']);
            $taskIdPlaceholders = implode(',', array_fill(0, count($taskIds), '?'));
            $changedTasksStmt = $this->conn->prepare("
                SELECT id
                FROM tasks
                WHERE id IN ($taskIdPlaceholders)
                  AND (status_by_exohu_id IS NULL OR status_by_exohu_id <> ?)
            ");
            $changedTasksStmt->execute(array_merge($taskIds, [$statusId]));
            $this->changedTaskIds = array_map('intval', $changedTasksStmt->fetchAll(PDO::FETCH_COLUMN));

            $sql = "UPDATE tasks SET status_by_exohu_id = ?,
            updated_by = ?, updated_at = NOW()
            WHERE id IN ($taskIdPlaceholders)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute(array_merge([$statusId, $userId], $taskIds));
        } catch (\Throwable $th) {
            throw new Exception(localizeErrorMessage('errors.database_error', $updateItem['locale'] ?? 'hu', ['message' => $th->getMessage()]));
        }

        if ($stmt->rowCount() === 0) {
            throw new Exception(localizeErrorMessage('errors.no_updatable_task', $updateItem['locale'] ?? 'hu'));
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
            foreach (array_unique(array_map('intval', $updateItem['taskIds'])) as $taskId) {
                $isTheTaskVisibleForUser = $this->auth->isTheTaskVisibleForUser(
                    $taskId,
                    null,
                    $this->userAuthData->companyId,
                    $this->userAuthData->permissions
                );
                if ($isTheTaskVisibleForUser['status'] !== 200) {
                    $this->response = $isTheTaskVisibleForUser;
                    return;
                }
            }

            $this->executeUpdate($updateItem, $this->userId);
            if (!empty($this->changedTaskIds)) {
                global $smtpHost, $smtpPort, $smtpEncryption, $smtpUsername, $smtpPassword, $smtpFromEmail, $smtpFromName;
                $notificationGenerator = new NotificationGenerator(
                    $this->conn,
                    $smtpHost,
                    $smtpPort,
                    $smtpEncryption,
                    $smtpUsername,
                    $smtpPassword,
                    $smtpFromEmail,
                    $smtpFromName
                );

                if (!$notificationGenerator->sendBatchStatusChangeEmail(
                    $this->userAuthData->companyId,
                    'Feladatok státuszváltozása (Teszt)',
                    'Az alábbi feladatok státusza megváltozott.',
                    $name,
                    $this->changedTaskIds
                )) {
                    error_log('Failed to send batch status change email for task IDs: ' . implode(',', $this->changedTaskIds));
                }
            }

            $this->response = [
                'status' => 200,
                'message' => localizeSuccessMessage('success.data_update_successful', $updateItem['locale'] ?? 'hu'),
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
                'message' => localizeErrorMessage('errors.database_error', $updateItem['locale'] ?? 'hu', ['message' => $e->getMessage()])
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
