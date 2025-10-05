<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];


class GetAllTask
{
    private $conn;
    private $tofShopIdUrl;
    private $getAllActivePointsUrl;
    private $user;
    private $password;
    private $tofShopIds;
    private $response;
    private $taskData = [];
    private $auth;
    private $userAuthData;

    public function __construct($conn, &$response, $auth, $tofShopIdUrl, $tofShopIds = [], $getAllActivePointsUrl, $user, $password)
    {
        $this->conn = $conn;
        $this->tofShopIdUrl = $tofShopIdUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->tofShopIds = $tofShopIds;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
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
        $companyId = $this->userAuthData['data']->companyId;
        $permissions = $this->userAuthData['data']->permissions;

        if ($this->userAuthData['status'] !== 200) {
            return $this->response = array(
                'status' => $this->userAuthData['status'],
                'message' => $this->userAuthData['message'],
                'data' => NULL
            );;
        }

        //Data gathering
        $tofShopIds = getTofShopId($this->tofShopIdUrl);
        $this->tofShopIds = $tofShopIds['payload'];
        $restrictionOfCompanyId = !in_array(17, $permissions) ? true : false;
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
                    'tl.name',
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
                    'tl.company_feedback as feedback',
                    'tl.locker_approach as lockerApproach',
                    'c.id as "responsible"',
                    'td.planned_delivery_date',
                    'td.delivery_date',
                    'tlp.url',
                    'CONCAT(UPPER(LEFT(u.last_name, 1)), UPPER(LEFT(u.first_name, 1))) as createdBy',
                    't.created_at as createdAt'
                ],
                'others' => "
                        LEFT JOIN task_types tt on tt.task_id = t.id AND tt.deleted = 0
                        LEFT JOIN task_type_details ttd on ttd.id = tt.type_id
                        LEFT JOIN task_statuses ts1 on ts1.id = t.status_by_partner_id
                        LEFT JOIN task_statuses ts2 on ts2.id = t.status_by_exohu_id
                        LEFT JOIN task_status_permissions tsp on tsp.task_status_id = ts2.id
                        LEFT JOIN task_locations tl on tl.id = t.task_locations_id
                        LEFT JOIN location_types lt on lt.id = tl.location_type_id
                        LEFT JOIN task_location_photos tlp on tlp.task_locations_id = tl.id AND tlp.deleted in (0,null)
                        LEFT JOIN task_dates td on td.task_id = t.id
                        LEFT JOIN task_responsibles tr on tr.task_id = t.id AND tr.deleted = 0                        
                        LEFT JOIN companies c on c.id = tr.company_id
                        LEFT JOIN users u on u.id = t.created_by
                        ",

                //'conditions' => (in_array(17, $permissions) ? "tlp.deleted = 0 OR tlp.deleted is NULL" : "tr.company_id = $companyId AND tlp.deleted = 0 OR tlp.deleted is NULL") . " ORDER BY id"
                // 'conditions' => (!in_array(17, $permissions) ? "tr.company_id = $companyId ORDER BY id" : "tr.company_id in (1,2,3)"),
                'order' => "ORDER BY id DESC"
            ];
            if (!in_array(17, $permissions)) {
                $baseTaskData['conditions'] .= " tr.company_id = $companyId";
            }

            $fees = [
                'table' => "fees f",
                'method' => "get",
                'columns' => [
                    "f.id as id",
                    'CONCAT(f.name,"(",f.net_unit_price ,")") as name',
                    'f.fee_type as type',
                    "f.net_unit_price as value"
                ]
            ];
            if (!in_array(23, $permissions)) {
                $fees['conditions'] .= " f.company_id = $companyId AND f.is_active = 1 ORDER BY f.name DESC";
            } else {
                $fees['conditions'] .= " f.is_active = 1 ORDER BY f.name DESC";
            }

            $taskFees = [
                'table' => "task_fees tf",
                'method' => "get",
                'columns' => [
                    'tf.id as id',
                    'tf.task_id as taskId',
                    'tf.fee_id as feeId',
                    'tf.other_items as otherItems',
                    'f.fee_type as feeType',
                    'tf.quantity',
                    'tf.total',
                    'tf.serial as lockerSerial'
                ],
                'others' => "LEFT JOIN fees f on f.id = tf.fee_id",
                'conditions' => "tf.deleted = 0 ORDER BY tf.task_id"
            ];
            $lockers = [
                'table' => "task_lockers tl",
                'method' => "get",
                'columns' => [
                    'tl.id',
                    'tl.task_id',
                    'tl.task_locations_id',
                    'tl.brand',
                    'tl.serial',
                    'tl.type',
                    'tl.fault',
                    'tl.tof_shop_id',
                    'tl.controller_id as controllerId',
                    'tl.is_registered',
                    'tl.is_active',
                    'tl.private_key1_error as privateKey1Error',
                    'tl.battery_level as batteryLevel',
                    'tl.current_version as currentVersion',
                    'tl.last_connection_timestamp as lastConnectionTimestamp'
                ],
                'others' => "
                LEFT JOIN tasks t on t.id = tl.task_id
                ",
                'conditions' => "tl.deleted = 0"
            ];
            $resultOfBaseTaskData = dataToHandleInDb($this->conn, $baseTaskData);
            $resultOfLockers = dataToHandleInDb($this->conn, $lockers);

            $errorInfo = '';
            //Check if user has permission to taskFees
            $isAccessTotaskFees = $this->auth->authenticate(6);
            if ($isAccessTotaskFees['status'] !== 403) {
                $resultOfTaskFees = dataToHandleInDb($this->conn, $taskFees);
                $errorInfo .= isset($resultOfTaskFees['errorInfo']) ? $resultOfTaskFees['errorInfo'] : '';
            } else {
                $resultOfTaskFees = $isAccessTotaskFees;
            }

            //Check if user has permission to fees
            $isAccessTofees = $this->auth->authenticate(6);
            if ($isAccessTofees['status'] !== 403) {
                $resultOffees = dataToHandleInDb($this->conn, $fees);
                $errorInfo .= isset($resultOffees['errorInfo']) ? $resultOffees['errorInfo'] : '';
            } else {
                $resultOffees = $isAccessTofees;
            }

            //Catch errors from DB functions

            if ($resultOfBaseTaskData['status'] !== 200) {
                $errorInfo .= isset($resultOfBaseTaskData['errorInfo']) ? $resultOfBaseTaskData['errorInfo'] : '';
                if (isset($resultOfTaskFees) && $resultOfTaskFees['status'] !== 200) {
                    $errorInfo .= isset($resultOfTaskFees['errorInfo']) ? $resultOfTaskFees['errorInfo'] : '';
                }

                if ($errorInfo) {
                    $this->response = array(
                        'status' => 500,
                        'message' => $errorInfo,
                        'data' => NULL
                    );
                }
            } else {
                $this->taskData['baseTaskData'] = $resultOfBaseTaskData;
                $this->taskData['taskFees'] = $resultOfTaskFees;
                $this->taskData['lockers'] = $resultOfLockers;
                $this->taskData['fees'] = $resultOffees;
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
            $result = dataManipulation($this->conn, $rowData, $this->userAuthData, $this->tofShopIds, $this->getAllActivePointsUrl, $this->user, $this->password);
            $response = $result;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new GetAllTask($conn, $response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password);
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
