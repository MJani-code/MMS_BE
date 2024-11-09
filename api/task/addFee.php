<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
//require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/db/dbFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');


$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class GetAllTask
{
    private $conn;
    private $response;
    private $taskData = [];

    public function __construct($conn, &$response)
    {
        $this->conn = $conn;
        $this->response = &$response;
    }

    //TODO: user validation here

    public function getTaskData()
    {
        if ($this->taskData !== []) {
            return $this->taskData;
        }

        $userRoleId = 2;
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
                    'tl.location_type as location_type',
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
                        LEFT JOIN Task_locations tl on tl.id = t.id
                        LEFT JOIN Task_location_photos tlp on tlp.location_id = tl.id
                        LEFT JOIN Task_dates td on td.task_id = t.id
                        LEFT JOIN Task_additional_info tai on tai.task_id = t.id
                        LEFT JOIN Task_additional_info_permissions taip on taip.task_additional_info_id = tai.id
                        LEFT JOIN Task_responsibles tr on tr.task_id = t.id AND tr.deleted = 0
                        LEFT JOIN Users u on u.id = tr.user_id
                        ",
                'conditions' => "
                        taip.role_id >=
                        (CASE
                        WHEN $userRoleId = 1 THEN 1
                        WHEN $userRoleId = 2 THEN 2
                        WHEN $userRoleId = 3 THEN 3
                        WHEN $userRoleId = 4 THEN 4
                        WHEN $userRoleId = 5 THEN 5
                        ELSE taip.role_id
                        END)
                        OR tai.name is NULL AND tlp.deleted = 0 OR tlp.deleted is NULL
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
                'other' => "ORDER BY tf.task_id"
            ];
            $result = dataToHandleInDb($this->conn, $baseTaskData);
            $result2 = dataToHandleInDb($this->conn, $taskFees);
            //echo json_encode($result2);
            if ($result['status'] && $result2['status'] !== 200) {
                $errorInfo = isset($result['errorInfo']) ? $result['errorInfo'] : '';
                $errorInfo = isset($result2['errorInfo']) ? $result2['errorInfo'] : '';
                $this->response = array(
                    'status' => 500,
                    'errorInfo' => $errorInfo,
                    'data' => NULL
                );
            } else {
                //echo json_encode($result);
                $this->taskData['baseTaskData'] = $result; // Tároljuk az eredményt
                $this->taskData['taskFees'] = $result2; // Tároljuk az eredményt
                return $result;
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
        $rowData = $this->getTaskData();
        if ($rowData) {
            $result = dataManipulation($this->conn, $rowData);
            $response = $result;
            //echo json_encode($rowData);
        }
    }
}
$getAllTask = new GetAllTask($conn, $response);
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
