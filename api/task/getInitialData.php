<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

//error debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class GetInitialData
{
    private PDO $conn;
    private Auth $auth;
    private ?int $userRoleId = null;

    public function __construct(PDO $conn, Auth $auth)
    {
        $this->conn = $conn;
        $this->auth = $auth;
    }

    private function createResponse(int $statusCode, string $message, array $payload = []): array
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'payload' => $payload
        ];
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isUserAllowed(): array
    {
        $authentication = $this->auth->authenticate(4);
        if (($authentication['status'] ?? 500) === 200) {
            $this->userRoleId = (int)($this->auth->roleId ?? 0);
        }
        return $authentication;
    }

    public function getInitialData(): array
    {
        $isAccess = $this->isUserAllowed();
        if (($isAccess['status'] ?? 500) !== 200) {
            return $this->createResponse(
                (int)($isAccess['status'] ?? 403),
                (string)($isAccess['message'] ?? 'Unauthorized')
            );
        }

        try {
            $params = ['role_id' => $this->userRoleId];

            $headers = $this->fetchAll(
                "SELECT DISTINCT tc.text, tc.dbTable, tc.dbColumn, tc.align, tc.filterable, tc.value
                 FROM task_columns tc
                 LEFT JOIN task_column_permissions tcp ON tcp.task_columns_id = tc.id
                 WHERE tc.task_column_types_id = 1
                   AND tc.is_active = 1
                   AND (tcp.role_id IS NULL OR tcp.role_id >= :role_id)
                 ORDER BY tc.orderId ASC",
                $params
            );

            $statuses = $this->fetchAll(
                "SELECT ts.id, ts.name, ts.color
                 FROM task_statuses ts
                 WHERE ts.is_active = 1"
            );

            $allowedStatuses = $this->fetchAll(
                "SELECT DISTINCT ts.id, ts.name, ts.color
                 FROM task_statuses ts
                 LEFT JOIN task_status_permissions tsp ON tsp.task_status_id = ts.id
                 WHERE ts.is_active = 1
                   AND (tsp.role_id IS NULL OR tsp.role_id >= :role_id)",
                $params
            );

            $locationTypes = $this->fetchAll(
                "SELECT lt.id, lt.name, lt.color
                 FROM location_types lt
                 WHERE lt.is_active = 1"
            );

            $taskTypes = $this->fetchAll(
                "SELECT ttd.id, ttd.name, ttd.color
                 FROM task_type_details ttd
                 WHERE ttd.is_active = 1"
            );

            $responsibles = $this->fetchAll(
                "SELECT r.company_id AS id, c.name
                 FROM responsibles r
                 LEFT JOIN companies c ON c.id = r.company_id
                 WHERE r.is_active = 1"
            );

            $priorities = $this->fetchAll(
                "SELECT p.id, p.name, p.color
                 FROM priorities p
                 WHERE p.is_active = 1"
            );

            $statusesGroupsResult = $this->fetchAll(
                "SELECT t.status_by_exohu_id AS id, ts.name, ts.color, COUNT(t.id) AS count
                 FROM tasks t
                 LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
                 GROUP BY t.status_by_exohu_id, ts.name, ts.color"
            );

            $statusGroups = [];
            foreach ($statusesGroupsResult as $status) {
                if ($status['id'] === null) {
                    continue;
                }

                $statusGroups[$status['id']] = [
                    'title' => $status['name'],
                    'color' => $status['color'],
                    'count' => (int)$status['count']
                ];
            }

            return $this->createResponse(200, 'Initial data fetched successfully.', [
                'headers' => $headers,
                'statuses' => $statuses,
                'allowedStatuses' => $allowedStatuses,
                'locationTypes' => $locationTypes,
                'taskTypes' => $taskTypes,
                'responsibles' => $responsibles,
                'priorities' => $priorities,
                'statusGroups' => $statusGroups
            ]);
        } catch (\Throwable $th) {
            return $this->createResponse(500, 'Database query error: ' . $th->getMessage());
        }
    }
}

// Authorization header kezelése biztonságosan
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$token = $matches[1];
$auth = new Auth($conn, $token, $secretkey);

$service = new GetInitialData($conn, $auth);
$response = $service->getInitialData();

// http_response_code((int)($response['status'] ?? 500));
echo json_encode($response, JSON_UNESCAPED_UNICODE);
