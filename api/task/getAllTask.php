<?php
header('Content-Type: application/json');

require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/inc/conn.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/db/dbFunctions.php');
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/taskFunctions.php');


$response = [];


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class GetAllTask
{
    private $conn;
    private $response;

    public function __construct($conn, &$response)
    {
        $this->conn = $conn;
        $this->response = &$response;
    }

    //TODO: user validation here

    public function getTaskData()
    {
        $userRoleId = 2;
        try {
            $dataToHandleInDb = [
                'table' => "Tasks t",
                'method' => "get",
                'columns' => [
                    't.id as id',
                    'tt.name as type',
                    'ts1.name as "status_partner"',
                    'ts2.name as "status_exohu"',
                    'ts2.color as "status_color"',
                    'tl.zip as "zip"',
                    'tl.city as "city"',
                    'tl.address as "address"',
                    'tl.type as location_type',
                    'tl.fixing_method',
                    '(SELECT GROUP_CONCAT(CONCAT(u.first_name, " ", u.last_name) SEPARATOR ",") FROM Task_responsibles tr LEFT JOIN Users u on u.id = tr.user_id where tr.task_id = t.id) as "responsibles_string"',
                    'td.planned_delivery_date',
                    'td.delivery_date',
                    'tlp.path'

                ],
                'others' => "
                        LEFT JOIN Task_types tt on tt.id = t.task_types_id
                        LEFT JOIN Task_statuses ts1 on ts1.id = t.status_by_partner_id
                        LEFT JOIN Task_statuses ts2 on ts2.id = t.status_by_exohu_id
                        LEFT JOIN Task_locations tl on tl.id = t.id
                        LEFT JOIN Task_location_photos tlp on tlp.location_id = tl.id
                        LEFT JOIN Task_dates td on td.task_id = t.id
                        LEFT JOIN Task_additional_info tai on tai.task_id = t.id
                        LEFT JOIN Task_additional_info_permissions taip on taip.task_additional_info_id = tai.id
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
                        OR tai.name is NULL"
            ];
            $result = dataToHandleInDb($this->conn, $dataToHandleInDb);

            return $result;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'errorInfo' => $errorInfo
            );
            return;
        }
    }

    public function dataManipulation(&$response)
    {
        $rowData = $this->getTaskData();
        $result = dataManipulation($this->conn, $rowData);
        $response = $result;
        //echo json_encode($rowData);
    }
}
$getAllTask = new GetAllTask($conn, $response);
$getAllTask->getTaskData();
$getAllTask->dataManipulation($response);
echo json_encode($response);
