<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class GetData
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
    private function createResponse($status, $message, $payload = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
        ];
    }
    public function getData()
    {
        //User validation here
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        //Data gathering
        try {
            $locationsStmt = [
                'table' => "task_locations tl",
                'method' => "get",
                'columns' => ['tl.id', 'tl.tof_shop_id', 'tl.name', 'CONCAT(tl.city, " ", tl.address) as address', 'CONCAT(tl.name, " - ",tl.city," ",tl.address) as nameAndAddress'],
                'others' => "",
                'conditions' => "tl.deleted = 0"
            ];
            $result = dataToHandleInDb($this->conn, $locationsStmt);
            $locations = $result['payload'];

            if ($result['status'] !== 200) {
                return $this->response = $this->createResponse(400, $result['errorInfo']);
            }

            $taskTypesStmt = [
                'table' => "task_type_details ttd",
                'method' => "get",
                'columns' => ['ttd.id', 'ttd.name'],
                'others' => "",
                'conditions' => "ttd.deleted = 0"
            ];
            $result = dataToHandleInDb($this->conn, $taskTypesStmt);
            $taskTypes = $result['payload'];

            if ($result['status'] !== 200) {
                return $this->response = $this->createResponse(400, $result['errorInfo']);
            }

            //megbízottak lekérdezése
            $responsiblesStmt = [
                'table' => "responsibles r",
                'method' => "get",
                'columns' => ['c.id', 'c.name'],
                'others' => "LEFT JOIN companies c ON c.id = r.company_id",
                'conditions' => "r.deleted = 0"
            ];
            $result = dataToHandleInDb($this->conn, $responsiblesStmt);
            $responsibles = $result['payload'];

            if ($result['status'] !== 200) {
                return $this->response = $this->createResponse(400, $result['errorInfo']);
            }

            //lockerd adatok lekérése
            $lockerStmt = [
                'table' => "task_lockers tl",
                'method' => "get",
                'columns' => ['tl.id', 'tl.tof_shop_id as tofShopId' ,'tl.task_locations_id as locationId', 'tl.serial'],
                'others' => "",
                'conditions' => "tl.deleted = 0"
            ];
            $result = dataToHandleInDb($this->conn, $lockerStmt);
            $lockers = $result['payload'];

            if ($result['status'] !== 200) {
                return $this->response = $this->createResponse(400, $result['errorInfo']);
            }

            $lockerIssueTypesStmt = [
                'table' => "locker_issue_types lit",
                'method' => "get",
                'columns' => ['lit.id', 'lit.los_id', 'lit.name'],
                'others' => "",
                'conditions' => "lit.deleted = 0"
            ];
            $result = dataToHandleInDb($this->conn, $lockerIssueTypesStmt);
            $lockerIssueTypes = $result['payload'];

            if ($result['status'] !== 200) {
                return $this->response = $this->createResponse(400, $result['errorInfo']);
            }

            $this->response = $this->createResponse(200, "Data loaded successfully", [
                'locations' => $locations,
                'taskTypes' => $taskTypes,
                'responsibles' => $responsibles,
                'lockers' => $lockers,
                'lockerIssueTypes' => $lockerIssueTypes
            ]);

            //return $this->response;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'payload' => NULL
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getData = new GetData($conn, $response, $auth);
$getData->getData();

echo json_encode($response);
