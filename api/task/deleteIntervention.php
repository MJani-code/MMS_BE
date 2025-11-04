<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class deleteIntervention
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
    public function deleteIntervention($payload)
    {
        // Authenticate user (use same permission id as other actions)
        $userId = null;
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        $interventionId = isset($payload['interventionId']) ? (int)$payload['interventionId'] : 0;
        $relatedIssues = isset($payload['issues']) ? $payload['issues'] : [];
        if ($interventionId <= 0) {
            return $this->response = $this->createResponse(400, "Invalid interventionId");
        }

        // Optional: verify taskId + uuid ownership if provided
        $taskId = isset($payload['taskId']) ? (int)$payload['taskId'] : null;
        $uuid = isset($payload['uuid']) ? $payload['uuid'] : null;

        try {
            // Check existence and optional ownership
            $checkSql = "SELECT id, task_id, uuid FROM task_lockers_interventions WHERE id = :id LIMIT 1";
            $stmt = $this->conn->prepare($checkSql);
            $stmt->bindValue(':id', $interventionId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $this->response = $this->createResponse(404, "Intervention not found");
            }
            if ($taskId !== null && $row['task_id'] != $taskId) {
                return $this->response = $this->createResponse(403, "Intervention does not belong to the given task");
            }
            if ($uuid !== null && $row['uuid'] != $uuid) {
                return $this->response = $this->createResponse(403, "Intervention does not belong to the given uuid");
            }

            // Start transaction
            $this->conn->beginTransaction();

            // Delete linked issue mappings (intervention_issues)
            $delIssuesSql = "DELETE FROM intervention_issues WHERE intervention_id = :id";
            $stmt = $this->conn->prepare($delIssuesSql);
            $stmt->bindValue(':id', $interventionId, PDO::PARAM_INT);
            $stmt->execute();

            //Revert is_solved field to 0 in task_lockers_issues table for related issues
            foreach ($relatedIssues as $issue) {
                $taskLockerIssuesUpdateSql = "UPDATE task_lockers_issues SET is_solved = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
                $taskLockerIssuesUpdateStmt = $this->conn->prepare($taskLockerIssuesUpdateSql);
                $taskLockerIssuesUpdateStmt->execute([0, $userId, $issue['issueId']]);
            }

            //SELECT rows before delete parts
            $selectPartsSql = "SELECT tlip.id
                                FROM task_locker_intervention_parts tlip
                                LEFT JOIN stock_movements sm ON sm.task_locker_intervention_parts_id = tlip.id
                                WHERE intervention_id = :id";
            $stmt = $this->conn->prepare($selectPartsSql);
            $stmt->bindValue(':id', $interventionId, PDO::PARAM_INT);
            $stmt->execute();
            $interventionParts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            //SELECT orignal data from stock_movements before delete
            $partsData = [];
            foreach ($interventionParts as $interventionPart) {
                $selectStockSql = "SELECT part_id, warehouse_id, -change_amount as quantity, {$interventionPart['id']} as task_locker_intervention_parts_id
                                    FROM stock_movements
                                    WHERE task_locker_intervention_parts_id = :tlip_id";
                $stmt = $this->conn->prepare($selectStockSql);
                $stmt->bindValue(':tlip_id', $interventionPart['id'], PDO::PARAM_INT);
                $stmt->execute();
                $partData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($partData) {
                    $partsData[] = $partData;
                }
            }

            //echo json_encode($partsData);

            // Delete linked parts (task_locker_intervention_parts)
            $delPartsSql = "DELETE FROM task_locker_intervention_parts WHERE intervention_id = :id";
            $stmt = $this->conn->prepare($delPartsSql);
            $stmt->bindValue(':id', $interventionId, PDO::PARAM_INT);
            $stmt->execute();

            // Restore stock for each part
            foreach ($partsData as $part) {
                $updateStockSql = "INSERT INTO stock_movements (part_id, warehouse_id, task_locker_intervention_parts_id, change_amount, reason, created_by) VALUES (:part_id, :warehouse_id, :task_locker_intervention_parts_id, :change_amount, :reason, :created_by)";
                $stmt = $this->conn->prepare($updateStockSql);
                $stmt->bindValue(':change_amount', $part['quantity'], PDO::PARAM_INT);
                $stmt->bindValue(':part_id', $part['part_id'], PDO::PARAM_INT);
                $stmt->bindValue(':warehouse_id', $part['warehouse_id'], PDO::PARAM_INT);
                $stmt->bindValue(':task_locker_intervention_parts_id', $part['task_locker_intervention_parts_id'], PDO::PARAM_INT);
                $stmt->bindValue(':reason', 'IN', PDO::PARAM_STR);
                $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
                $stmt->execute();
            }

            // Delete the intervention itself
            $delIntervSql = "DELETE FROM task_lockers_interventions WHERE id = :id";
            $stmt = $this->conn->prepare($delIntervSql);
            $stmt->bindValue(':id', $interventionId, PDO::PARAM_INT);
            $stmt->execute();

            $this->conn->commit();

            return $this->response = $this->createResponse(200, "Intervention deleted successfully", ['interventionId' => $interventionId]);
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->response = $this->createResponse(500, "Database error (deleteIntervention): " . $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$deleteIntervention = new deleteIntervention($conn, $response, $auth, $token);
$deleteIntervention->deleteIntervention($payload);

echo json_encode($response);
