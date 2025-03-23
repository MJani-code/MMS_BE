<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class GetAllInvoicedTask
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
    public function getIvoicedTasksData($token)
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
            $taskStatuses = $body['taskStatuses'] ?? [];

            // Create placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($taskStatuses), '?'));

            // Base query
            $query = "SELECT t.id as taskId, tl.tof_shop_id as tofShopId, tl.box_id as boxId, tl.name, concat(tl.city,' ',tl.address) as address, sum(DISTINCT tf.total) as total, td.delivery_date as deliveryDate
            FROM task_fees tf
            LEFT JOIN tasks t on t.id = tf.task_id
            LEFT JOIN task_locations tl on tl.id = t.task_locations_id
            LEFT JOIN task_dates td on td.task_id = t.id
            LEFT JOIN (
                SELECT DISTINCT task_id, type_id
                FROM task_types
                WHERE deleted = 0
            ) tt ON tt.task_id = t.id            
            WHERE t.status_by_exohu_id >= 9 AND tf.deleted = 0";

            // Append the IN clause if there are task statuses
            if (count($taskStatuses) > 0) {
                $query .= " AND tt.type_id IN ($placeholders)";
            }

            // Complete the query
            $query .= " GROUP BY tl.tof_shop_id;";

            $stmt = $this->conn->prepare($query);
            
            // Bind the values to the placeholders
            if (count($taskStatuses) > 0) {
                $stmt->execute($taskStatuses);
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
                'data' => NULL
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$getAllTask = new GetAllInvoicedTask($conn, $response, $auth);
$getAllTask->getIvoicedTasksData($token);

echo json_encode($response);
