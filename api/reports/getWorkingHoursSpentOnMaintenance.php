<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class GetWorkingHoursSpentOnMaintenance
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
    public function getWorkingHoursSpentOnMaintenanceData($token)
    {
        //token értékét kikérni közvetlenül db-ből
        try {
            $stmt = $this->conn->prepare("SELECT token FROM api_tokens where api='grafana/getInvoicedTasks' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tokenFromDb = $result ? $result['token'] : null;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'data' => NULL
            );
            return;
        }

        if ($tokenFromDb !== $token) {
            return $this->response = $this->createResponse(401, "Unauthorized", $token);
        }

        //Data gathering
        try {
            $jsonData = file_get_contents("php://input");
            $body = json_decode($jsonData, true);
            $feeTypes = $body['feeTypes'] ?? [];
            $taskTypes = $body['taskTypes'] ?? [];

            // Prepare params and placeholders for IN clauses
            $params = [];
            $placeholders = '';
            if (count($feeTypes) > 0) {
                $placeholders = implode(',', array_fill(0, count($feeTypes), '?'));
            }

            // Base query
            $query = "SELECT
                        tf.task_id AS taskId,
                        tl.tof_shop_id AS shopId,
                        sum(tf.quantity) AS workingHours,
                        tlo.brand,
                        t.created_at AS createdAt
                        FROM task_fees tf
                        JOIN tasks t ON t.id = tf.task_id
                        LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
                        LEFT JOIN fees f ON f.id = tf.fee_id
                        LEFT JOIN task_lockers tlo ON tlo.task_id = t.id
                        WHERE EXISTS (
                        SELECT 1 FROM task_types tt2
                        WHERE tt2.task_id = tf.task_id";

            if (count($taskTypes) > 0) {
                // Create placeholders for the IN clause and add their values to params
                $taskTypePlaceholders = implode(',', array_fill(0, count($taskTypes), '?'));
                $query .= " AND tt2.type_id IN ($taskTypePlaceholders))";
                $params = array_merge($params, array_values($taskTypes));
            }

            $query .= " AND t.status_by_exohu_id = 10";

            // Append the IN clause if there are task statuses
            if (count($feeTypes) > 0) {
                $query .= " AND tf.fee_id IN ($placeholders)";
                $params = array_merge($params, array_values($feeTypes));
            }

            // Complete the query
            $query .= " GROUP BY tf.task_id;";

            $stmt = $this->conn->prepare($query);

            // Bind the values to the placeholders in the correct order
            if (count($params) > 0) {
                $stmt->execute($params);
            } else {
                $stmt->execute();
            }

            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // $result['postedData'] = $taskStatuses;

            return $this->response = $result;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'data' => [
                    'query' => strval($query),
                    'stmt' => $stmt
                ]
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$getWorkingHoursSpentOnMaintenance = new GetWorkingHoursSpentOnMaintenance($conn, $response, $auth);
$getWorkingHoursSpentOnMaintenance->getWorkingHoursSpentOnMaintenanceData($token);

echo json_encode($response);
