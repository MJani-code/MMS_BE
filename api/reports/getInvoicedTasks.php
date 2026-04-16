<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');
require('../../vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('getInvoicedTasks');
$logger->pushHandler(new RotatingFileHandler('logs/getInvoicedTasks.log', 5));
$response = [];

class GetAllInvoicedTask
{
    private $conn;
    private $response;
    private $auth;
    private $logger;

    public function __construct($conn, &$response, $auth, $logger)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->logger = $logger;
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
            $taskTypes = $body['taskTypes'] ?? [];
            $notToGroupByShop = $body['notToGroupByShop'] ?? false;

            // Create placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($taskTypes), '?'));

            // Base query
            $query = "SELECT t.id as taskId, ttd.name as taskTypeName, tl.tof_shop_id as tofShopId, tl.box_id as boxId, tl.name, concat(tl.city,' ',tl.address) as address, ";

            if (!$notToGroupByShop) {
                $query .= " sum(tf.total) as total, GROUP_CONCAT(DISTINCT f.name) as feeNames, sum(tf.quantity) as totalQuantity, ";
            } else {
                $query .= " tf.total as total, f.name as feeNames, tf.quantity as totalQuantity, ";
            }

            $query .= "td.delivery_date as deliveryDate
            FROM task_fees tf
            LEFT JOIN tasks t on t.id = tf.task_id
            LEFT JOIN fees f on f.id = tf.fee_id
            LEFT JOIN task_locations tl on tl.id = t.task_locations_id
            LEFT JOIN task_dates td on td.task_id = t.id
--            LEFT JOIN task_lockers tlo ON tlo.id = (
--                SELECT MAX(tlo2.id)
--                FROM task_lockers tlo2
--                WHERE tlo2.task_id = t.id
--            )
            INNER JOIN (
                SELECT task_id, type_id
                FROM task_types
                WHERE deleted = 0" . (count($taskTypes) > 0 ? " AND type_id IN ($placeholders)" : "") . "                
            ) tt ON tt.task_id = t.id
            LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
            WHERE t.status_by_exohu_id = 10 AND tf.deleted = 0";

            // Append the IN clause if there are task statuses
            // if (count($taskTypes) > 0) {
            //     $query .= " AND tt.type_id IN ($placeholders)";
            // }

            // Complete the query
            //$query .= " GROUP BY tl.tof_shop_id;";
            if (!$notToGroupByShop) {
                $query .= " GROUP BY tl.tof_shop_id, tf.id;";
            } else {
                $query .= " GROUP BY tf.id;";
            }

            $stmt = $this->conn->prepare($query);
            //Bind parameters for the IN clause
            if (count($taskTypes) > 0) {
                foreach ($taskTypes as $index => $typeId) {
                    $stmt->bindValue($index + 1, $typeId, PDO::PARAM_INT);
                }
            }

            $stmt->execute();

            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // $result['postedData'] = $taskStatuses;

            //Log
            $this->logger->info('getIvoicedTasksData function executed', [
                'query' => $query,
                'taskTypes' => $taskTypes,
                'notToGroupByShop' => $notToGroupByShop,
                'resultCount' => count($result)
            ]);
            return $this->response = $result;

            //DEBUG
            // $this->response = array(
            //     'query' => $query,
            //     'params' => $taskTypes,
            //     'result' => $result
            // );

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


$getAllTask = new GetAllInvoicedTask($conn, $response, $auth, $logger);
$getAllTask->getIvoicedTasksData($token);

echo json_encode($response);
