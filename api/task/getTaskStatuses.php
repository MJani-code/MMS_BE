<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

class TaskStatus
{
    private $conn;
    public $id;
    private $auth;
    private $userRoleId = null;
    private $response;


    public function __construct($id, $conn, $auth, $userRoleId, &$response)
    {
        $this->conn = $conn;
        $this->id = $id;
        $this->auth = $auth;
        $this->userRoleId = $userRoleId;
        $this->response = &$response;
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'payload' => []
        ];
    }

    public function isUserAllowed()
    {
        $authentication = $this->auth->authenticate(4);
        $this->userRoleId = $this->auth->roleId;
        return $authentication;
    }

    public function getStatuses()
    {
        // Authenticate user
        $isAccess = $this->isUserAllowed();
        if ($isAccess['status'] !== 200) {
            return $this->createResponse($isAccess['status'], $isAccess['message']);
        }

        try {
            // Get headers
            $stmt = $this->conn->prepare(
                "SELECT text, dbTable, dbColumn, align, filterable, value 
            FROM task_columns tc 
            LEFT JOIN task_column_permissions tcp ON tcp.task_columns_id = tc.id 
            WHERE tcp.role_id >= 
            (CASE WHEN '$this->userRoleId' = '$this->userRoleId' THEN '$this->userRoleId' ELSE tcp.role_id END) 
            AND tc.task_column_types_id = 1 
            AND tc.is_active = 1 
            ORDER BY tc.orderId ASC"
            );
            $stmt->execute();
            $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get statuses
            $stmt = $this->conn->prepare(
                "SELECT ts.id, name, color 
                FROM task_statuses ts
                WHERE ts.is_active = 1"
            );
            $stmt->execute();
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // getAllowedStatuses
            $stmt = $this->conn->prepare(
                "SELECT ts.id, ts.name, ts.color 
                FROM task_statuses ts 
                LEFT JOIN task_status_permissions tsp ON tsp.task_status_id = ts.id 
                WHERE tsp.role_id >= (CASE WHEN '$this->userRoleId' = '$this->userRoleId' THEN '$this->userRoleId' ELSE tsp.role_id END) 
                AND ts.is_active = 1"
            );
            $stmt->execute();
            $allowedStatuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // getLocationTypes
            $stmt = $this->conn->prepare(
                "SELECT id, name, color 
                FROM location_types lt 
                WHERE lt.is_active = 1"
            );
            $stmt->execute();
            $locationTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // getTaskTypes
            $stmt = $this->conn->prepare(
                "SELECT id, name, color 
                FROM task_type_details ttd 
                WHERE ttd.is_active = 1"
            );
            $stmt->execute();
            $taskTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // GetResponsibles
            $stmt = $this->conn->prepare(
                "SELECT r.company_id AS id, c.name AS name 
                FROM responsibles r 
                LEFT JOIN companies c ON c.id = r.company_id 
                WHERE r.is_active = 1"
            );
            $stmt->execute();
            $responsibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // getPriorities
            $stmt = $this->conn->prepare(
                "SELECT id, name, color 
                FROM priorities p 
                WHERE p.is_active = 1"
            );
            $stmt->execute();
            $priorities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // statusGroups
            $stmt = $this->conn->prepare(
                "SELECT t.status_by_exohu_id as id, ts.name as name, ts.color as color, COUNT(t.id) as count
                FROM `tasks` t
                LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
                GROUP BY t.status_by_exohu_id"
            );
            $stmt->execute();
            $statusesGroupsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $statusGroups = [];
            foreach ($statusesGroupsResult as $status) {
                $statusGroups[$status['id']] = [
                    'title' => $status['name'],
                    'color' => $status['color'],
                    'count' => (int)$status['count']
                ];
            }


            $this->response = [
                'status' => 200,
                'payload' => [
                    'headers' => $headers,
                    'statuses' => $statuses,
                    'allowedStatuses' => $allowedStatuses,
                    'locationTypes' => $locationTypes,
                    'taskTypes' => $taskTypes,
                    'responsibles' => $responsibles,
                    'priorities' => $priorities,
                    'statusGroups' => $statusGroups
                ]
            ];

            // return $this->createResponse(200, 'Task statuses and related data fetched successfully.', $payload);
        } catch (\Throwable $th) {
            return $this->createResponse(500, 'Database query error: ' . $th->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$taskStatus = new TaskStatus(null, $conn, $auth, null, $response);
$taskStatus->getStatuses();

echo json_encode($response);
