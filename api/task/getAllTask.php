<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
//require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/db/dbFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/api/user/auth/auth.php');


$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class GetAllTask
{
    private $conn;
    private $response;
    private $taskData = [];
    private $auth;
    private $userAuthData;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }


    public function Auth()
    {
        return $this->auth->authenticate();
    }

    public function getTaskData()
    {
        //If there is already data, stop run code again
        if ($this->taskData !== []) {
            return $this->taskData;
        }
        //User validation here
        $this->userAuthData = $this->auth->authenticate();
        $roleId = $this->userAuthData['data']->roleId;
        //$roleId = 3;

        if ($this->userAuthData['status'] !== 200) {
            return $this->response = array(
                'status' => $this->userAuthData['status'],
                'message' => $this->userAuthData['message'],
                'data' => NULL
            );;
        }

        //Data gathering
        try {
            $baseTaskData = [
                'table' => "Tasks t",
                'method' => "get",
                'columns' => [
                    't.id as id',
                    'ttd.id as types',
                    'ts1.id as "status_partner_id"',
                    'ts2.id as "status_exohu_id"',
                    'ts2.name as "status_exohu"',
                    'ts2.color as "status_color"',
                    'tl.zip as "zip"',
                    'tl.city as "city"',
                    'tl.address as "address"',
                    'tl.location_type_id as location_type',
                    'tl.id as location_id',
                    'tl.fixing_method',
                    'tl.required_site_preparation',
                    'u.id as "responsible"',
                    'td.planned_delivery_date',
                    'td.delivery_date',
                    'tlp.url'

                ],
                'others' => "
                        LEFT JOIN Task_types tt on tt.task_id = t.id AND tt.deleted = 0
                        LEFT JOIN Task_type_details ttd on ttd.id = tt.type_id
                        LEFT JOIN Task_statuses ts1 on ts1.id = t.status_by_partner_id
                        LEFT JOIN Task_statuses ts2 on ts2.id = t.status_by_exohu_id
                        LEFT JOIN Task_status_permissions tsp on tsp.task_status_id = ts2.id
                        LEFT JOIN Task_locations tl on tl.id = t.id
                        LEFT JOIN Location_types lt on lt.id = tl.location_type_id
                        LEFT JOIN Task_location_photos tlp on tlp.location_id = tl.id
                        LEFT JOIN Task_dates td on td.task_id = t.id
                        LEFT JOIN Task_responsibles tr on tr.task_id = t.id AND tr.deleted = 0
                        LEFT JOIN Users u on u.id = tr.user_id
                        ",
                'conditions' => "tsp.role_id >=
                        (CASE
                        WHEN $roleId = $roleId THEN $roleId
                        ELSE tsp.role_id
                        END)
                        AND tlp.deleted = 0 OR tlp.deleted is NULL
                        ORDER BY id"
            ];

            $taskFees = [
                'table' => "Task_fees tf",
                'method' => "get",
                'columns' => [
                    'tf.id as id',
                    'tf.task_id as taskId',
                    'tf.fee_id as feeId',
                    'tf.other_items as otherItems',
                    'tf.quantity',
                    'tf.total'
                ],
                'conditions' => "tf.deleted = 0 ORDER BY tf.task_id"
            ];
            $result = dataToHandleInDb($this->conn, $baseTaskData);

            //Only roleId under 3 can access to fees
            if ($roleId < 3) {
                $result2 = dataToHandleInDb($this->conn, $taskFees);
            } else {
                $result2 = array(
                    'status' => 200,
                    'message' => 'nincs hozzáférés a fees részhez',
                    'data' => null
                );
            }

            $errorInfo = '';

            if ($result['status'] !== 200) {
                $errorInfo = isset($result['errorInfo']) ? $result['errorInfo'] : '';
                if (isset($result2) && $result2['status'] !== 200) {
                    $errorInfo .= isset($result2['errorInfo']) ? $result2['errorInfo'] : '';
                }

                if ($errorInfo) {
                    $this->response = array(
                        'status' => 500,
                        'errorInfo' => $errorInfo,
                        'data' => NULL
                    );
                }
            } else {
                $this->taskData['baseTaskData'] = $result;
                $this->taskData['taskFees'] = $result2;
                return $this->taskData;
            }
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'errorInfo' => $errorInfo,
                'data' => NULL
            );
            return;
        }
    }
    //Data customizing
    public function dataManipulation(&$response)
    {
        $rowData = $this->taskData;
        if ($rowData) {
            $result = dataManipulation($this->conn, $rowData, $this->userAuthData);
            $response = $result;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$permissionName = 'View_task';
$auth = new Auth($conn, $token, $secretkey, $permissionName);

$getAllTask = new GetAllTask($conn, $response, $auth, 'View_task');
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
