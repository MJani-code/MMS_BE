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
                    'tl.tof_shop_id',
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
                'conditions' => "tlp.deleted = 0 OR tlp.deleted is NULL
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
            $lockers = [
                'table' => "Lockers l",
                'method' => "get",
                'columns' => [
                    'l.id',
                    'l.brand',
                    'l.serial',
                    'l.tof_shop_id',
                    'l.is_active'
                ],
                'conditions' => "l.deleted = 0"
            ];
            $resultOfBaseTaskData = dataToHandleInDb($this->conn, $baseTaskData);
            $resultOfLockers = dataToHandleInDb($this->conn, $lockers);

            //Only roleId under 3 can access to fees
            if ($roleId < 3) {
                $resultOfTaskFees = dataToHandleInDb($this->conn, $taskFees);
            } else {
                $resultOfTaskFees = array(
                    'status' => 200,
                    'message' => 'nincs hozzáférés a fees részhez',
                    'data' => null
                );
            }

            //Catch errors from DB functions
            $errorInfo = '';
            if ($resultOfBaseTaskData['status'] !== 200) {
                $errorInfo = isset($resultOfBaseTaskData['errorInfo']) ? $resultOfBaseTaskData['errorInfo'] : '';
                if (isset($resultOfTaskFees) && $resultOfTaskFees['status'] !== 200) {
                    $errorInfo .= isset($resultOfTaskFees['errorInfo']) ? $resultOfTaskFees['errorInfo'] : '';
                }

                if ($errorInfo) {
                    $this->response = array(
                        'status' => 500,
                        'errorInfo' => $errorInfo,
                        'data' => NULL
                    );
                }
            } else {
                $this->taskData['baseTaskData'] = $resultOfBaseTaskData;
                $this->taskData['taskFees'] = $resultOfTaskFees;
                $this->taskData['lockers'] = $resultOfLockers;
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
            $response['status'] = 200;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$permissionId = 4;
$auth = new Auth($conn, $token, $secretkey, $permissionId);

$getAllTask = new GetAllTask($conn, $response, $auth, $permissionId);
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
