<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class GetLocationsAvailability
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
    public function getLocationsAvailabilityData($token)
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
            $body = json_decode($jsonData, true) ?? [];
            $from = isset($body['from']) && $body['from'] !== '' ? $body['from'] : null;
            $to = isset($body['to']) && $body['to'] !== '' ? $body['to'] : null;
            $tofShopIds = isset($body['tofShopIds']) && is_array($body['tofShopIds']) && count($body['tofShopIds']) > 0
                ? array_values($body['tofShopIds'])
                : null;

            $conditions = [];
            $params = [];
            $bindIndex = 1;

            if ($from !== null) {
                $conditions[] = "day >= ?";
                $params[$bindIndex++] = $from;
            }

            if ($to !== null) {
                $conditions[] = "day <= ?";
                $params[$bindIndex++] = $to;
            }

            if ($tofShopIds !== null) {
                $placeholders = implode(',', array_fill(0, count($tofShopIds), '?'));
                $conditions[] = "tof_shop_id IN ($placeholders)";
                foreach ($tofShopIds as $id) {
                    $params[$bindIndex++] = $id;
                }
            }

            $whereSql = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

            // Base query
            $query = "SELECT
                        tof_shop_id,
                        day,
                        is_enabled,
                        collected_at
                    FROM
                        locker_daily_permission
                    $whereSql";

            $stmt = $this->conn->prepare($query);

            foreach ($params as $index => $value) {
                $stmt->bindValue($index, $value);
            }
            $stmt->execute();

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

$getLocationsAvailability = new GetLocationsAvailability($conn, $response, $auth);
$getLocationsAvailability->getLocationsAvailabilityData($token);

echo json_encode($response);
