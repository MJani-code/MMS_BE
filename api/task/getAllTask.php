<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');
require(__DIR__ . '/../../vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('getAllTask');
$logger->pushHandler(new RotatingFileHandler('logs/getAllTask.log', 5));


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
    private $logger;

    public function __construct($conn, &$response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password, $logger)
    {
        $this->conn = $conn;
        $this->tofShopIdUrl = $tofShopIdUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->logger = $logger;
        $this->tofShopIds = $tofShopIds;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
    }


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
            // TESZTELÉSHEZ: Állítsd át: $limitForTesting = "LIMIT 50";
            // Így csak 50 taskot kérdez le, gyorsabb tesztelés
            // PRODUCTION-ban: $limitForTesting = "";
            $limitForTesting = ""; // <-- Itt állítsd át teszteléshez!

            $baseTaskData = [
                'table' => "tasks t",
                'method' => "get",
                'columns' => [
                    't.id as id',
                    't.task_locations_id',
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
                    'td.planned_delivery_date',
                    'td.delivery_date',
                    'CONCAT(UPPER(LEFT(u.last_name, 1)), UPPER(LEFT(u.first_name, 1))) as createdBy',
                    't.created_at as createdAt'
                ],
                'others' => "
                        LEFT JOIN task_statuses ts1 on ts1.id = t.status_by_partner_id
                        LEFT JOIN task_statuses ts2 on ts2.id = t.status_by_exohu_id
                        LEFT JOIN task_locations tl on tl.id = t.task_locations_id
                        LEFT JOIN task_dates td on td.task_id = t.id
                        LEFT JOIN users u on u.id = t.created_by
                        " . (!in_array(17, $permissions) ? "LEFT JOIN task_responsibles tr_filter on tr_filter.task_id = t.id AND tr_filter.deleted = 0 AND tr_filter.company_id = $companyId" : "") . "
                        ",

                //'conditions' => (in_array(17, $permissions) ? "tlp.deleted = 0 OR tlp.deleted is NULL" : "tr.company_id = $companyId AND tlp.deleted = 0 OR tlp.deleted is NULL") . " ORDER BY id"
                // 'conditions' => (!in_array(17, $permissions) ? "tr.company_id = $companyId ORDER BY id" : "tr.company_id in (1,2,3)"),
                'conditions' => (!in_array(17, $permissions) ? "tr_filter.id IS NOT NULL" : ""),
                'order' => "ORDER BY t.id DESC $limitForTesting"
            ];
            // Task types külön lekérdezése
            $taskTypes = [
                'table' => "task_types tt",
                'method' => "get",
                'columns' => [
                    'tt.task_id',
                    'ttd.id as type_id'
                ],
                'others' => "LEFT JOIN task_type_details ttd on ttd.id = tt.type_id",
                'conditions' => "tt.deleted = 0"
            ];

            // Task priorities külön lekérdezése
            $taskPriorities = [
                'table' => "task_priorities tp",
                'method' => "get",
                'columns' => [
                    'tp.task_id',
                    'tp.priority_id'
                ],
                'conditions' => ""
            ];

            // Task responsibles külön lekérdezése
            $taskResponsibles = [
                'table' => "task_responsibles tr",
                'method' => "get",
                'columns' => [
                    'tr.task_id',
                    'c.id as company_id'
                ],
                'others' => "LEFT JOIN companies c on c.id = tr.company_id",
                'conditions' => "tr.deleted = 0"
            ];
            if (!in_array(17, $permissions)) {
                $taskResponsibles['conditions'] .= " AND tr.company_id = $companyId";
            }

            $fees = [
                'table' => "fees f",
                'method' => "get",
                'columns' => [
                    "f.id as id",
                    'CONCAT(f.name,"(",f.net_unit_price ,")") as name',
                    'f.fee_type as type',
                    "f.net_unit_price as value"
                ],
                'conditions' => ""
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

            $taskPhotos = [
                'table' => "task_location_photos tlp",
                'method' => "get",
                'columns' => [
                    'tlp.task_locations_id',
                    'tlp.url as url'
                ],
                'conditions' => "tlp.deleted = 0 OR tlp.deleted IS NULL",
                'order' => "ORDER BY tlp.task_locations_id"
            ];

            $start = microtime(true);
            $resultOfBaseTaskData = dataToHandleInDb($this->conn, $baseTaskData);
            $end = microtime(true);
            $this->logger->info('getTaskData function - Time taken to execute baseTaskData query: ' . ($end - $start) . ' seconds');

            $start = microtime(true);
            $resultOfTaskTypes = dataToHandleInDb($this->conn, $taskTypes);
            $end = microtime(true);
            $this->logger->info('getTaskData function - Time taken to execute taskTypes query: ' . ($end - $start) . ' seconds');

            $start = microtime(true);
            $resultOfTaskPriorities = dataToHandleInDb($this->conn, $taskPriorities);
            $end = microtime(true);
            $this->logger->info('getTaskData function - Time taken to execute taskPriorities query: ' . ($end - $start) . ' seconds');

            $start = microtime(true);
            $resultOfTaskResponsibles = dataToHandleInDb($this->conn, $taskResponsibles);
            $end = microtime(true);
            $this->logger->info('getTaskData function - Time taken to execute taskResponsibles query: ' . ($end - $start) . ' seconds');

            $start = microtime(true);
            $resultOfLockers = dataToHandleInDb($this->conn, $lockers);
            $end = microtime(true);
            $this->logger->info('getTaskData function - Time taken to execute lockers query: ' . ($end - $start) . ' seconds');

            $start = microtime(true);
            $resultOfTaskPhotos = dataToHandleInDb($this->conn, $taskPhotos);
            $end = microtime(true);
            $this->logger->info('getTaskData function - Time taken to execute taskPhotos query: ' . ($end - $start) . ' seconds');

            $errorInfo = '';
            //Check if user has permission to taskFees
            $isAccessTotaskFees = $this->auth->authenticate(6);
            if ($isAccessTotaskFees['status'] !== 403) {
                $start = microtime(true);
                $resultOfTaskFees = dataToHandleInDb($this->conn, $taskFees);
                $end = microtime(true);
                $this->logger->info('getTaskData function - Time taken to execute taskFees query: ' . ($end - $start) . ' seconds');
                $errorInfo .= isset($resultOfTaskFees['errorInfo']) ? $resultOfTaskFees['errorInfo'] : '';
            } else {
                $resultOfTaskFees = $isAccessTotaskFees;
            }

            //Check if user has permission to fees
            $isAccessTofees = $this->auth->authenticate(6);
            if ($isAccessTofees['status'] !== 403) {
                $start = microtime(true);
                $resultOffees = dataToHandleInDb($this->conn, $fees);
                $end = microtime(true);
                $this->logger->info('getTaskData function - Time taken to execute fees query: ' . ($end - $start) . ' seconds');
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
                $this->taskData['taskTypes'] = $resultOfTaskTypes;
                $this->taskData['taskPriorities'] = $resultOfTaskPriorities;
                $this->taskData['taskResponsibles'] = $resultOfTaskResponsibles;
                $this->taskData['taskFees'] = $resultOfTaskFees;
                $this->taskData['lockers'] = $resultOfLockers;
                $this->taskData['fees'] = $resultOffees;
                $this->taskData['taskPhotos'] = $resultOfTaskPhotos;
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

$tofShopIds = [];

$auth = new Auth($conn, $token, $secretkey);

$getAllTask = new GetAllTask($conn, $response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password, $logger);
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
