<?php
require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
    private $filters = [];
    private $paginationMeta = [];
    private $locale;

    public function __construct($conn, &$response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password, $statusId = null, $itemsPerPage = null, $page = null, $searchText = null, $filters = [], $locale = 'hu')
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
        $this->locale = $locale;
        $this->itemsPerPage = $itemsPerPage;
        $this->page = $page;
        $this->searchText = $searchText;
        $this->filters = is_array($filters) ? $filters : [];
    }

    private function escapeLike($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value);
    }

    private function applyClientFilters(array &$where, array &$params)
    {
        if (!is_array($this->filters) || empty($this->filters)) {
            return;
        }

        if (isset($this->filters['statusIds']) && is_array($this->filters['statusIds'])) {
            $statusIds = array_values(array_filter(array_map('intval', $this->filters['statusIds']), fn($id) => $id > 0));
            if (!empty($statusIds)) {
                $placeholders = [];
                foreach ($statusIds as $idx => $id) {
                    $key = ':f_status_' . $idx;
                    $placeholders[] = $key;
                    $params[$key] = $id;
                }
                $where[] = 't.status_by_exohu_id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (isset($this->filters['city']) && trim((string)$this->filters['city']) !== '') {
            $params[':f_city'] = '%' . $this->escapeLike(trim((string)$this->filters['city'])) . '%';
            $where[] = "tl.city LIKE :f_city ESCAPE '\\\\'";
        }

        if (isset($this->filters['name']) && trim((string)$this->filters['name']) !== '') {
            $params[':f_name'] = '%' . $this->escapeLike(trim((string)$this->filters['name'])) . '%';
            $where[] = "tl.name LIKE :f_name ESCAPE '\\\\'";
        }

        if (isset($this->filters['zip']) && trim((string)$this->filters['zip']) !== '') {
            $params[':f_zip'] = '%' . $this->escapeLike(trim((string)$this->filters['zip'])) . '%';
            $where[] = "tl.zip LIKE :f_zip ESCAPE '\\\\'";
        }

        if (isset($this->filters['address']) && trim((string)$this->filters['address']) !== '') {
            $params[':f_address'] = '%' . $this->escapeLike(trim((string)$this->filters['address'])) . '%';
            $where[] = "tl.address LIKE :f_address ESCAPE '\\\\'";
        }

        if (isset($this->filters['box_id']) && trim((string)$this->filters['box_id']) !== '') {
            $params[':f_box_id'] = trim((string)$this->filters['box_id']);
            $where[] = "tl.box_id = :f_box_id";
        }

        if (isset($this->filters['serial']) && trim((string)$this->filters['serial']) !== '') {
            $params[':f_serial'] = '%' . $this->escapeLike(trim((string)$this->filters['serial'])) . '%';
            $where[] = "EXISTS (
                SELECT 1
                FROM task_lockers tl_serial
                WHERE tl_serial.task_id = t.id
                  AND tl_serial.deleted = 0
                  AND tl_serial.serial LIKE :f_serial ESCAPE '\\\\'
            )";
        }

        if (isset($this->filters['location_type'])) {
            $locationTypesRaw = $this->filters['location_type'];
            if (!is_array($locationTypesRaw)) {
                $locationTypesRaw = explode(',', (string)$locationTypesRaw);
            }

            $locationTypeIds = array_values(array_filter(array_map('intval', $locationTypesRaw), fn($id) => $id > 0));
            if (!empty($locationTypeIds)) {
                $placeholders = [];
                foreach ($locationTypeIds as $idx => $id) {
                    $key = ':f_location_type_' . $idx;
                    $placeholders[] = $key;
                    $params[$key] = $id;
                }
                $where[] = 'tl.location_type_id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (isset($this->filters['taskType']) || isset($this->filters['taskTypes']) || isset($this->filters['task_type'])) {
            $taskTypesRaw = $this->filters['taskType'] ?? ($this->filters['taskTypes'] ?? $this->filters['task_type']);
            if (!is_array($taskTypesRaw)) {
                $taskTypesRaw = explode(',', (string)$taskTypesRaw);
            }

            $taskTypeIds = array_values(array_filter(array_map('intval', $taskTypesRaw), fn($id) => $id > 0));
            if (!empty($taskTypeIds)) {
                $placeholders = [];
                foreach ($taskTypeIds as $idx => $id) {
                    $key = ':f_task_type_' . $idx;
                    $placeholders[] = $key;
                    $params[$key] = $id;
                }

                $where[] = "EXISTS (
                    SELECT 1
                    FROM task_types tt_client
                    WHERE tt_client.task_id = t.id
                      AND tt_client.deleted = 0
                      AND tt_client.type_id IN (" . implode(', ', $placeholders) . ")
                )";
            }
        }

        if (isset($this->filters['tof_shop_id']) && $this->filters['tof_shop_id'] !== '') {
            $params[':f_tof_shop_id'] = (int)$this->filters['tof_shop_id'];
            $where[] = 'tl.tof_shop_id = :f_tof_shop_id';
        }

        if (isset($this->filters['createdAtFrom']) && trim((string)$this->filters['createdAtFrom']) !== '') {
            $params[':f_created_from'] = trim((string)$this->filters['createdAtFrom']) . ' 00:00:00';
            $where[] = 't.created_at >= :f_created_from';
        }

        if (isset($this->filters['createdAtTo']) && trim((string)$this->filters['createdAtTo']) !== '') {
            $params[':f_created_to'] = trim((string)$this->filters['createdAtTo']) . ' 23:59:59';
            $where[] = 't.created_at <= :f_created_to';
        }

        if (isset($this->filters['delivery_dateFrom']) && trim((string)$this->filters['delivery_dateFrom']) !== '') {
            $params[':f_delivery_from'] = trim((string)$this->filters['delivery_dateFrom']) . ' 00:00:00';
            $where[] = "EXISTS (
                SELECT 1
                FROM task_dates td_filter
                WHERE td_filter.task_id = t.id
                  AND td_filter.delivery_date >= :f_delivery_from
            )";
        }

        if (isset($this->filters['delivery_dateTo']) && trim((string)$this->filters['delivery_dateTo']) !== '') {
            $params[':f_delivery_to'] = trim((string)$this->filters['delivery_dateTo']) . ' 23:59:59';
            $where[] = "EXISTS (
                SELECT 1
                FROM task_dates td_filter2
                WHERE td_filter2.task_id = t.id
                  AND td_filter2.delivery_date <= :f_delivery_to
            )";
        }

        if (isset($this->filters['planned_delivery_dateFrom']) && trim((string)$this->filters['planned_delivery_dateFrom']) !== '') {
            $params[':f_planned_delivery_from'] = trim((string)$this->filters['planned_delivery_dateFrom']) . ' 00:00:00';
            $where[] = "EXISTS (
                SELECT 1
                FROM task_dates td_filter3
                WHERE td_filter3.task_id = t.id
                  AND td_filter3.planned_delivery_date >= :f_planned_delivery_from
            )";
        }

        if (isset($this->filters['planned_delivery_dateTo']) && trim((string)$this->filters['planned_delivery_dateTo']) !== '') {
            $params[':f_planned_delivery_to'] = trim((string)$this->filters['planned_delivery_dateTo']) . ' 23:59:59';
            $where[] = "EXISTS (
                SELECT 1
                FROM task_dates td_filter4
                WHERE td_filter4.task_id = t.id
                  AND td_filter4.planned_delivery_date <= :f_planned_delivery_to
            )";
        }

        if (
            isset($this->filters['responsibles'])
        ) {
            $responsiblesRaw = $this->filters['responsibles']
                ?? ($this->filters['responsibleCompanyIds']
                    ?? ($this->filters['responsibleCompanyId']
                        ?? $this->filters['responsible_company_id']));

            if (!is_array($responsiblesRaw)) {
                $responsiblesRaw = explode(',', (string)$responsiblesRaw);
            }

            $responsibleCompanyIds = array_values(array_filter(array_map('intval', $responsiblesRaw), fn($id) => $id > 0));
            if (!empty($responsibleCompanyIds)) {
                $placeholders = [];
                foreach ($responsibleCompanyIds as $idx => $id) {
                    $key = ':f_responsible_company_' . $idx;
                    $placeholders[] = $key;
                    $params[$key] = $id;
                }

                $where[] = "EXISTS (
                    SELECT 1
                    FROM task_responsibles tr_client
                    WHERE tr_client.task_id = t.id
                      AND tr_client.deleted = 0
                      AND tr_client.company_id IN (" . implode(', ', $placeholders) . ")
                )";
            }
        }
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
            $like = '%' . $this->escapeLike($searchText) . '%';
            $params[':searchText'] = $like;

            $where[] = "(
                CAST(t.id AS CHAR) LIKE :searchText ESCAPE '\\\\'
                OR tl.name LIKE :searchText ESCAPE '\\\\'
                OR tl.city LIKE :searchText ESCAPE '\\\\'
                OR tl.address LIKE :searchText ESCAPE '\\\\'
                OR tl.zip LIKE :searchText ESCAPE '\\\\'
                OR tl.comment LIKE :searchText ESCAPE '\\\\'
                OR CAST(tl.tof_shop_id AS CHAR) LIKE :searchText ESCAPE '\\\\'
                OR tl.box_id LIKE :searchText ESCAPE '\\\\'
                OR ts.name LIKE :searchText ESCAPE '\\\\'
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

        $this->applyClientFilters($where, $params);

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
            LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
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
            $taskIdsCondition = !empty($taskIds) ? implode(',', $taskIds) : '0';

            $baseTaskData = [
                'table' => "tasks t",
                'method' => "get",
                'columns' => [
                    't.id as id',
                    't.task_locations_id',
                    'ts.id as "status_exohu_id"',
                    'ts.name as "status_exohu"',
                    'ts.color as "status_color"',
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
                        LEFT JOIN task_statuses ts on ts.id = t.status_by_exohu_id
                        LEFT JOIN task_locations tl on tl.id = t.task_locations_id
                        LEFT JOIN task_dates td on td.task_id = t.id
                        LEFT JOIN users u on u.id = t.created_by
                        ",
                'conditions' => implode(' AND ', $baseTaskConditions),
                'order' => "ORDER BY t.id DESC"
            ];

            $taskTypes = [
                'table' => "task_types tt",
                'method' => "get",
                'columns' => [
                    'tt.task_id',
                    'ttd.id as type_id'
                ],
                'others' => "LEFT JOIN task_type_details ttd on ttd.id = tt.type_id",
                'conditions' => "tt.deleted = 0 AND tt.task_id IN ($taskIdsCondition)"
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

            // Task priorities külön lekérdezése
            $taskPriorities = [
                'table' => "task_priorities tp",
                'method' => "get",
                'columns' => [
                    'tp.task_id',
                    'tp.priority_id'
                ],
                'conditions' => "tp.task_id IN ($taskIdsCondition)"
            ];

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
                'conditions' => "tf.deleted = 0 AND tf.task_id IN ($taskIdsCondition) ORDER BY tf.task_id"
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
                'conditions' => "tr.deleted = 0 AND tr.task_id IN ($taskIdsCondition)"
            ];
            if (!in_array(17, $permissions)) {
                $taskResponsibles['conditions'] .= " AND tr.company_id = $companyId";
            }

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
                'conditions' => "tl.deleted = 0 AND tl.task_id IN ($taskIdsCondition)"
            ];

            $taskPhotos = [
                'table' => "task_location_photos tlp",
                'method' => "get",
                'columns' => [
                    'tlp.task_locations_id',
                    'tlp.url as url'
                ],
                'conditions' => "(tlp.deleted = 0 OR tlp.deleted IS NULL) AND tlp.task_locations_id IN (SELECT DISTINCT t_inner.task_locations_id FROM tasks t_inner WHERE t_inner.id IN ($taskIdsCondition))",
                'order' => "ORDER BY tlp.task_locations_id"
            ];

            $resultOfBaseTaskData = dataToHandleInDb($this->conn, $baseTaskData);
            $resultOfLockers = dataToHandleInDb($this->conn, $lockers);
            $resultOfTaskTypes = dataToHandleInDb($this->conn, $taskTypes);
            $resultOfTaskPriorities = dataToHandleInDb($this->conn, $taskPriorities);
            $resultOfTaskResponsibles = dataToHandleInDb($this->conn, $taskResponsibles);
            $resultOfTaskPhotos = dataToHandleInDb($this->conn, $taskPhotos);
            $errorInfo = '';
            $feesAccess = $this->auth->authenticate(6);
            if ($feesAccess['status'] !== 403) {
                $resultOfTaskFees = dataToHandleInDb($this->conn, $taskFees);
                $errorInfo .= isset($resultOfTaskFees['errorInfo']) ? $resultOfTaskFees['errorInfo'] : '';
            } else {
                $resultOfTaskFees = $feesAccess;
            }

            if ($feesAccess['status'] !== 403) {
                $resultOffees = dataToHandleInDb($this->conn, $fees);
                $errorInfo .= isset($resultOffees['errorInfo']) ? $resultOffees['errorInfo'] : '';
            } else {
                $resultOffees = $feesAccess;
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
                $this->taskData['taskTypes'] = $resultOfTaskTypes;
                $this->taskData['taskPriorities'] = $resultOfTaskPriorities;
                $this->taskData['taskResponsibles'] = $resultOfTaskResponsibles;
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

    public function dataManipulation(&$response)
    {
        $rowData = $this->taskData;
        if ($rowData) {
            $result = dataManipulation($this->conn, $rowData, $this->userAuthData, $this->tofShopIds, $this->getAllActivePointsUrl, $this->user, $this->password, $this->locale);
            $result['pagination'] = $this->paginationMeta;
            $response = $result;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? '';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$statusId     = $body['statusId']     ?? null;
$itemsPerPage = $body['itemsPerPage'] ?? null;
$page         = $body['page']         ?? null;
$searchText   = $body['filters']['searchText']   ?? null;
$filters      = is_array($body['filters'] ?? null) ? $body['filters'] : [];
$locale       = $body['locale'] ?? 'hu';

$auth = new Auth($conn, $token, $secretkey);

$filterTask = new FilterTask($conn, $response, $auth, $tofShopIdUrl, $tofShopIds, $getAllActivePointsUrl, $user, $password, $statusId, $itemsPerPage, $page, $searchText, $filters, $locale);
$filterTask->getTaskData();
$filterTask->dataManipulation($response);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
