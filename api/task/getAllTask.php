<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];


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


    // public function Auth()
    // {
    //     return $this->auth->authenticate();
    // }

    public function getTaskData()
    {
        //If there is already data, stop run code again
        if ($this->taskData !== []) {
            return $this->taskData;
        }
        //User validation here
        $this->userAuthData = $this->auth->authenticate(4);
        $roleId = $this->userAuthData['data']->roleId;

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
                'table' => "tasks t",
                'method' => "get",
                'columns' => [
                    't.id as id',
                    'ttd.id as types',
                    'ts1.id as "status_partner_id"',
                    'ts2.id as "status_exohu_id"',
                    'ts2.name as "status_exohu"',
                    'ts2.color as "status_color"',
                    'tl.tof_shop_id',
                    'tl.box_id',
                    'tl.zip as "zip"',
                    'tl.city as "city"',
                    'tl.address as "address"',
                    'tl.location_type_id as location_type',
                    'tl.id as location_id',
                    'tl.fixing_method',
                    'tl.required_site_preparation',
                    'tl.comment',
                    'u.id as "responsible"',
                    'td.planned_delivery_date',
                    'td.delivery_date',
                    'tlp.url'

                ],
                'others' => "
                        LEFT JOIN task_types tt on tt.task_id = t.id AND tt.deleted = 0
                        LEFT JOIN task_type_details ttd on ttd.id = tt.type_id
                        LEFT JOIN task_statuses ts1 on ts1.id = t.status_by_partner_id
                        LEFT JOIN task_statuses ts2 on ts2.id = t.status_by_exohu_id
                        LEFT JOIN task_status_permissions tsp on tsp.task_status_id = ts2.id
                        LEFT JOIN task_locations tl on tl.task_id = t.id
                        LEFT JOIN location_types lt on lt.id = tl.location_type_id
                        LEFT JOIN task_location_photos tlp on tlp.location_id = tl.id
                        LEFT JOIN task_dates td on td.task_id = t.id
                        LEFT JOIN task_responsibles tr on tr.task_id = t.id AND tr.deleted = 0
                        LEFT JOIN users u on u.id = tr.user_id
                        ",
                'conditions' => "tlp.deleted = 0 OR tlp.deleted is NULL
                        ORDER BY id"
            ];

            $taskFees = [
                'table' => "task_fees tf",
                'method' => "get",
                'columns' => [
                    'tf.id as id',
                    'tf.task_id as taskId',
                    'tf.fee_id as feeId',
                    'tf.other_items as otherItems',
                    'tf.quantity',
                    'tf.total',
                    'tf.serial as lockerSerial'
                ],
                'conditions' => "tf.deleted = 0 ORDER BY tf.task_id"
            ];
            $lockers = [
                'table' => "lockers l",
                'method' => "get",
                'columns' => [
                    'l.id',
                    'l.brand',
                    'l.serial',
                    'l.type',
                    'l.comment',
                    'l.tof_shop_id',
                    'l.is_active'
                ],
                'others' => "
                LEFT JOIN task_locations tl on tl.tof_shop_id = l.tof_shop_id
                ",
                'conditions' => "l.deleted = 0"
            ];
            $resultOfBaseTaskData = dataToHandleInDb($this->conn, $baseTaskData);
            $resultOfLockers = dataToHandleInDb($this->conn, $lockers);

            //Check if user has permission to taskFees
            $isAccessTotaskFees = $this->auth->authenticate(6);
            if ($isAccessTotaskFees['status'] !== 403) {
                $resultOfTaskFees = dataToHandleInDb($this->conn, $taskFees);
            } else {
                $resultOfTaskFees = $isAccessTotaskFees;
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

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new GetAllTask($conn, $response, $auth);
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
