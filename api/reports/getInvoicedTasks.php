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

            if (!is_array($taskTypes)) {
                $taskTypes = [];
            }

            $taskTypes = array_values(array_filter(array_map('intval', $taskTypes), static function ($typeId) {
                return $typeId > 0;
            }));

            // Create placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($taskTypes), '?'));
            $taskTypeFilter = count($taskTypes) > 0 ? " AND type_id IN ($placeholders)" : "";

            // Base query
            $query = "SELECT t.id as taskId, c.name as companyName, ttd.name as taskTypeName, tl.tof_shop_id as tofShopId, tl.box_id as boxId, tl.name, concat(tl.city,' ',tl.address) as address, COALESCE(lc.lockerCount, 0) as lockerCount, ";

            if (!$notToGroupByShop) {
                $query .= " sum(tf.total) as total, GROUP_CONCAT(DISTINCT f.name) as feeNames, tf.other_items as otherItems ,sum(tf.quantity) as totalQuantity, ";
            } else {
                $query .= " tf.total as total, f.name as feeNames, tf.other_items as otherItems ,tf.quantity as totalQuantity, ";
            }

            $query .= "td.delivery_date as deliveryDate,
            ms.monthlyDistinctShopCount as monthlyDistinctShopCount
            FROM task_fees tf
            LEFT JOIN tasks t on t.id = tf.task_id
            LEFT JOIN fees f on f.id = tf.fee_id
            LEFT JOIN task_locations tl on tl.id = t.task_locations_id
            LEFT JOIN (
                SELECT task_locations_id, COUNT(DISTINCT serial) as lockerCount
                FROM task_lockers
                WHERE serial IS NOT NULL AND serial <> ''
                AND deleted = 0
                GROUP BY task_locations_id
            ) lc ON lc.task_locations_id = tl.id
            LEFT JOIN companies c on c.id = f.company_id
            LEFT JOIN task_dates td on td.task_id = t.id
--            LEFT JOIN task_lockers tlo ON tlo.id = (
--                SELECT MAX(tlo2.id)
--                FROM task_lockers tlo2
--                WHERE tlo2.task_id = t.id
--            )
            INNER JOIN (
                SELECT task_id, type_id
                FROM task_types
                WHERE deleted = 0" . $taskTypeFilter . "                
            ) tt ON tt.task_id = t.id
            LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
            LEFT JOIN (
                SELECT DATE_FORMAT(td2.delivery_date, '%Y-%m') as deliveryMonth,
                       COUNT(DISTINCT tl2.tof_shop_id) as monthlyDistinctShopCount
                FROM task_fees tf2
                LEFT JOIN tasks t2 on t2.id = tf2.task_id
                LEFT JOIN task_locations tl2 on tl2.id = t2.task_locations_id
                LEFT JOIN task_dates td2 on td2.task_id = t2.id
                INNER JOIN (
                    SELECT task_id, type_id
                    FROM task_types
                    WHERE deleted = 0" . $taskTypeFilter . "
                ) tt2 ON tt2.task_id = t2.id
                WHERE t2.status_by_exohu_id = 10 AND tf2.deleted = 0
                GROUP BY DATE_FORMAT(td2.delivery_date, '%Y-%m')
            ) ms ON ms.deliveryMonth = DATE_FORMAT(td.delivery_date, '%Y-%m')
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
            // Bind parameters for both task_types filters (main query + monthly subquery)
            if (count($taskTypes) > 0) {
                $stmt->execute(array_merge($taskTypes, $taskTypes));
            } else {
                $stmt->execute();
            }

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
