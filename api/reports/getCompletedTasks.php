<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class getCompletedTasks
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
    public function getCompletedTasksData($token)
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
            $taskTypes = $body['taskTypes'] ?? [];

            // Create placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($taskTypes), '?'));

            // Base query
            $query = "SELECT
                t.id as taskId, GROUP_CONCAT(ttd.name) as taskType, ts.name as status, td.delivery_date as deliveryDate
                FROM `tasks` t
                LEFT JOIN task_dates td on td.task_id = t.id
                LEFT JOIN task_locations tl on tl.id = t.task_locations_id
                LEFT JOIN task_statuses ts on ts.id = t.status_by_exohu_id
                LEFT JOIN(
                    SELECT DISTINCT
                        task_id,
                        type_id
                    FROM
                        task_types
                    WHERE
                        deleted = 0
                ) tt
                ON
                    tt.task_id = t.id
                LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
                WHERE
                    t.status_by_exohu_id = 10";

            // Append the IN clause if there are task types
            if (count($taskTypes) > 0) {
                $query .= " AND tt.type_id IN ($placeholders)";
            }

            // Complete the query
            $query .= " GROUP BY t.id;";

            $stmt = $this->conn->prepare($query);

            // Bind the values to the placeholders
            if (count($taskTypes) > 0) {
                $stmt->execute($taskTypes);
            } else {
                $stmt->execute();
            }

            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

$getCompletedTasks = new getCompletedTasks($conn, $response, $auth);
$getCompletedTasks->getCompletedTasksData($token);

echo json_encode($response);
