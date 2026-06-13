<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

class FilterTask
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
    private $statusId;
    private $itemsPerPage;
    private $page;
    private $searchText;
    private $paginationMeta = [];

    public function __construct($conn, &$response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password, $statusId = null, $itemsPerPage = null, $page = null, $searchText = null)
    {
        $this->conn = $conn;
        $this->tofShopIdUrl = $tofShopIdUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->tofShopIds = $tofShopIds;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
        $this->statusId = $statusId;
        $this->itemsPerPage = $itemsPerPage;
        $this->page = $page;
        $this->searchText = $searchText;
    }

    private function buildSearchWhereClause($companyId, $permissions)
    {
        $where = [];
        $params = [];

        if (!in_array(17, $permissions)) {
            $where[] = "EXISTS (
                SELECT 1
                FROM task_responsibles tr_filter
                WHERE tr_filter.task_id = t.id
                  AND tr_filter.deleted = 0
                  AND tr_filter.company_id = :companyId
            )";
            $params[':companyId'] = intval($companyId);
        }

        if ($this->statusId) {
            $where[] = "t.status_by_exohu_id = :statusId";
            $params[':statusId'] = intval($this->statusId);
        }

        $searchText = trim((string)$this->searchText);
        if ($searchText !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchText) . '%';
            $params[':searchText'] = $like;

            $where[] = "(
                CAST(t.id AS CHAR) LIKE :searchText ESCAPE '\\\\'
                OR tl.name LIKE :searchText ESCAPE '\\\\'
                OR tl.city LIKE :searchText ESCAPE '\\\\'
                OR tl.address LIKE :searchText ESCAPE '\\\\'
                OR tl.zip LIKE :searchText ESCAPE '\\\\'
                OR CAST(tl.tof_shop_id AS CHAR) LIKE :searchText ESCAPE '\\\\'
                OR tl.box_id LIKE :searchText ESCAPE '\\\\'
                OR ts2.name LIKE :searchText ESCAPE '\\\\'
                OR CONCAT(UPPER(LEFT(u.last_name, 1)), UPPER(LEFT(u.first_name, 1))) LIKE :searchText ESCAPE '\\\\'
                OR EXISTS (
                    SELECT 1
                    FROM task_lockers tlk
                    WHERE tlk.task_id = t.id
                      AND tlk.deleted = 0
                      AND (
                          tlk.serial LIKE :searchText ESCAPE '\\\\'
                          OR tlk.brand LIKE :searchText ESCAPE '\\\\'
                          OR tlk.type LIKE :searchText ESCAPE '\\\\'
                          OR CAST(tlk.tof_shop_id AS CHAR) LIKE :searchText ESCAPE '\\\\'
                      )
                )
                OR EXISTS (
                    SELECT 1
                    FROM task_types tt2
                    LEFT JOIN task_type_details ttd2 ON ttd2.id = tt2.type_id
                    WHERE tt2.task_id = t.id
                      AND tt2.deleted = 0
                      AND ttd2.name LIKE :searchText ESCAPE '\\\\'
                )
                OR EXISTS (
                    SELECT 1
                    FROM task_responsibles tr2
                    LEFT JOIN companies c2 ON c2.id = tr2.company_id
                    WHERE tr2.task_id = t.id
                      AND tr2.deleted = 0
                      AND c2.name LIKE :searchText ESCAPE '\\\\'
                )
            )";
        }

        return [
            'where' => $where,
            'params' => $params
        ];
    }

    private function getPaginatedTaskIds($companyId, $permissions)
    {
        $searchData = $this->buildSearchWhereClause($companyId, $permissions);
        $where = $searchData['where'];
        $params = $searchData['params'];

        $page = intval($this->page ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $itemsPerPage = intval($this->itemsPerPage ?? 20);
        if ($itemsPerPage < 1) {
            $itemsPerPage = 20;
        }

        $offset = ($page - 1) * $itemsPerPage;

        $baseFrom = "
            FROM tasks t
            LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
            LEFT JOIN task_statuses ts2 ON ts2.id = t.status_by_exohu_id
            LEFT JOIN users u ON u.id = t.created_by
        ";

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $countSql = "SELECT COUNT(DISTINCT t.id) as totalCount " . $baseFrom . $whereSql;
        $countStmt = $this->conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalCount = intval($countStmt->fetchColumn() ?: 0);

        $idsSql = "
            SELECT DISTINCT t.id
            " . $baseFrom . "
            " . $whereSql . "
            ORDER BY t.id DESC
            LIMIT :offset, :itemsPerPage
        ";

        $idsStmt = $this->conn->prepare($idsSql);
        foreach ($params as $key => $value) {
            $idsStmt->bindValue($key, $value);
        }
        $idsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $idsStmt->bindValue(':itemsPerPage', $itemsPerPage, PDO::PARAM_INT);
        $idsStmt->execute();
        $idsPayload = $idsStmt->fetchAll(PDO::FETCH_ASSOC);

        $taskIds = array_map('intval', array_column($idsPayload, 'id'));
        $totalPages = $itemsPerPage > 0 ? intval(ceil($totalCount / $itemsPerPage)) : 0;

        $this->paginationMeta = [
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages
        ];

        return $taskIds;
    }

    public function getTaskData()
    {
        if ($this->taskData !== []) {
            return $this->taskData;
        }

        $this->userAuthData = $this->auth->authenticate(4);
        $companyId = $this->userAuthData['data']->companyId;
        $permissions = $this->userAuthData['data']->permissions;

        if ($this->userAuthData['status'] !== 200) {
            return $this->response = array(
                'status' => $this->userAuthData['status'],
                'message' => $this->userAuthData['message'],
                'data' => NULL
            );
        }

        $tofShopIds = getTofShopId($this->tofShopIdUrl);
        $this->tofShopIds = $tofShopIds['payload'];

        try {
            $baseTaskConditions = [];

            if (!in_array(17, $permissions)) {
                $baseTaskConditions[] = "tr.company_id = $companyId";
            }

            if ($this->statusId) {
                $baseTaskConditions[] = "t.status_by_exohu_id = " . intval($this->statusId);
            }

            $taskIds = $this->getPaginatedTaskIds($companyId, $permissions);
            $baseTaskConditions[] = !empty($taskIds) ? 't.id IN (' . implode(',', $taskIds) . ')' : '1 = 0';

            $baseTaskData = [
                'table' => "tasks t",
                'method' => "get",
                'columns' => [
                    't.id as id',
                    'ttd.id as types',
                    'tp.priority_id as priorityId',
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
                        LEFT JOIN task_priorities tp on tp.task_id = t.id
                        LEFT JOIN priorities p on p.id = tp.priority_id
                        LEFT JOIN location_types lt on lt.id = tl.location_type_id
                        LEFT JOIN task_location_photos tlp on tlp.task_locations_id = tl.id AND tlp.deleted in (0,null)
                        LEFT JOIN task_dates td on td.task_id = t.id
                        LEFT JOIN task_responsibles tr on tr.task_id = t.id AND tr.deleted = 0
                        LEFT JOIN companies c on c.id = tr.company_id
                        LEFT JOIN users u on u.id = t.created_by
                        ",
                'conditions' => implode(' AND ', $baseTaskConditions),
                'order' => "ORDER BY t.id DESC"
            ];

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
            $isAccessTotaskFees = $this->auth->authenticate(6);
            if ($isAccessTotaskFees['status'] !== 403) {
                $resultOfTaskFees = dataToHandleInDb($this->conn, $taskFees);
                $errorInfo .= isset($resultOfTaskFees['errorInfo']) ? $resultOfTaskFees['errorInfo'] : '';
            } else {
                $resultOfTaskFees = $isAccessTotaskFees;
            }

            $isAccessTofees = $this->auth->authenticate(6);
            if ($isAccessTofees['status'] !== 403) {
                $resultOffees = dataToHandleInDb($this->conn, $fees);
                $errorInfo .= isset($resultOffees['errorInfo']) ? $resultOffees['errorInfo'] : '';
            } else {
                $resultOffees = $isAccessTofees;
            }

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

    public function dataManipulation(&$response)
    {
        $rowData = $this->taskData;
        echo json_encode($rowData);
        if ($rowData) {
            $result = dataManipulation($this->conn, $rowData, $this->userAuthData, $this->tofShopIds, $this->getAllActivePointsUrl, $this->user, $this->password);
            $result['pagination'] = $this->paginationMeta;
            $response = $result;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$statusId = $_GET['statusId'] ?? null;
$itemsPerPage = $_GET['itemsPerPage'] ?? null;
$page = $_GET['page'] ?? null;
$searchText = $_GET['searchText'] ?? null;

$auth = new Auth($conn, $token, $secretkey);

$filterTask = new FilterTask($conn, $response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password, $statusId, $itemsPerPage, $page, $searchText);
$filterTask->getTaskData();
$filterTask->dataManipulation($response);
echo json_encode($response);
