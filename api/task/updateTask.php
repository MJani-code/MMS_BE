<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];


$isFileUpload = NULL;
if ($_FILES['file']) {
    $isFileUpload = true;
} else {
    $isFileUpload = NULL;
}
// $_FILES['file'] ?? NULL;
$locationId = $_POST['locationId'] ?? NULL;
$file = $_FILES['file'] ?? NULL;

class updateTask
{
    private $conn;
    private $response;
    private $auth;
    private $userAuthData;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    public function updateTaskData($isFileUpload, $conn, $file, $locationId)
    {
        //Adat frissítés
        $jsonData = file_get_contents("php://input");
        $data = json_decode($jsonData, true);

        $taskId = $data['task_id'];
        $id = $data['id'] ?? null;
        $dbTable = $data['dbTable'];
        $dbColumn = $data['dbColumn'];
        $value = $data['value'] ?? '';

        //Update Engedély ellenőrzése
        $userId = null;
        $isAccess = $this->auth->authenticate(2);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        //File feltőltés
        if ($isFileUpload) {
            $maxFileSize = 20000000;
            $DOC_ROOT = DOC_ROOT . '/uploads/' . $locationId;
            $DOC_URL = DOC_URL . '/uploads/' . $locationId;

            //Upload Engedély ellenőrzése
            $isAccess = $this->auth->authenticate(13);
            if ($isAccess['status'] !== 200) {
                return $this->response = $isAccess;
            }
            $result = uploadFile($conn, $file, $locationId, $userId, $maxFileSize, $DOC_ROOT, $DOC_URL);
            return $this->response = $result;
        } else {
            //Speciális engedély ellenőrzés a státuszra
            $statusIdNeedAccess = [9];
            if ($data['dbColumn'] == 'status_by_exohu_id' && in_array($data['value'], $statusIdNeedAccess)) {
                $isAccess = $this->auth->authenticate(8);
                if ($isAccess['status'] !== 200) {
                    return $this->response = $isAccess;
                }
            }

            try {
                switch ($dbTable) {
                    case 'task_types':
                        $taskId = $data['id'];
                        $new_items = $value; // a felhasználó által kiválasztott új tételek
                        $deleted_by = $userId; // például azonos felhasználó végzi a "törlést"
                        $deleted_at = date('Y-m-d H:i:s'); // a frissítés ideje
                        $updated_at = date('Y-m-d H:i:s'); // a frissítés ideje

                        //1. Lekérünk minden elemet
                        $query = "SELECT type_id FROM $dbTable WHERE task_id = :task_id";
                        $stmt = $conn->prepare($query);
                        $stmt->execute(['task_id' => $taskId]);
                        $all_items = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        // 2. Lekérjük az aktuális tételeket az adatbázisból
                        $query = "SELECT type_id FROM $dbTable WHERE task_id = :task_id AND deleted = 0";
                        $stmt = $conn->prepare($query);
                        $stmt->execute(['task_id' => $taskId]);
                        $current_items = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        // 3. Meghatározzuk a törlendő és a hozzáadandó elemeket
                        $items_to_delete = array_diff($current_items, $new_items);
                        $items_to_add = array_diff($new_items, $current_items);


                        // 4. Törlünk az eltávolított tételeket az adatbázisból
                        if (!empty($items_to_delete)) {
                            $update_query = "UPDATE $dbTable SET deleted = 1, deleted_at = ?, deleted_by = ?
                                                 WHERE task_id = ? AND type_id IN (" . implode(',', array_fill(0, count($items_to_delete), '?')) . ")";
                            $stmt = $conn->prepare($update_query);

                            // A frissítési értékek paraméterei
                            $params = [$deleted_at, $deleted_by, $taskId];
                            $params = array_merge($params, $items_to_delete); // hozzáadjuk az `item_id`-kat

                            $stmt->execute($params);
                            if ($stmt->execute()) {
                                $payload = array(
                                    'id' => intval($taskId),
                                    'column' => 'taskTypes',
                                    'value' => $new_items
                                );
                                $this->response = array(
                                    'status' => 200,
                                    'message' => 'Data update successful',
                                    'payload' => $payload
                                );
                            }
                        }

                        //5. Ha már létezik a státusz, módosítjuk annak deleted státuszát, ha nem, akkor új sort hozunk létre
                        if (!empty($items_to_add)) {
                            // Először végigmegyünk az új elemek listáján
                            foreach ($items_to_add as $item_id) {
                                // Ha az elem már szerepel a $current_items listában, akkor UPDATE
                                if (in_array($item_id, $all_items)) {
                                    $update_query = "UPDATE $dbTable SET deleted = ?, updated_at = ?, updated_by = ? WHERE task_id = ? AND type_id = ?";
                                    $stmt = $conn->prepare($update_query);
                                    $params = [0, $updated_at, $userId, $taskId, $item_id]; // Paraméterek a frissítéshez
                                    $stmt->execute($params);
                                    if ($stmt->execute()) {
                                        $payload = array(
                                            'id' => intval($taskId),
                                            'column' => 'taskTypes',
                                            'value' => $new_items
                                        );
                                        $this->response = array(
                                            'status' => 200,
                                            'message' => 'Data update successful',
                                            'payload' => $payload
                                        );
                                    }
                                } else {
                                    // Ha az elem még nincs benne, akkor INSERT
                                    $insert_query = "INSERT INTO $dbTable (task_id, type_id, created_by) VALUES (?, ?, ?)";
                                    $stmt = $conn->prepare($insert_query);
                                    $params = [$taskId, $item_id, $userId];
                                    $stmt->execute($params);
                                    if ($stmt->execute()) {
                                        $payload = array(
                                            'id' => intval($taskId),
                                            'column' => 'taskTypes',
                                            'value' => $new_items
                                        );
                                        $this->response = array(
                                            'status' => 200,
                                            'message' => 'Data update successful',
                                            'payload' => $payload
                                        );
                                    }
                                }
                            }
                        }
                        break;
                    case 'task_responsibles':
                        $taskId = $data['id'];
                        $new_items = $value; // a felhasználó által kiválasztott új tételek
                        $deleted_by = $userId; // például azonos felhasználó végzi a "törlést"
                        $deleted_at = date('Y-m-d H:i:s'); // a frissítés ideje
                        $updated_at = date('Y-m-d H:i:s'); // a frissítés ideje

                        //1. Lekérünk minden elemet
                        $query = "SELECT company_id FROM $dbTable WHERE task_id = :task_id";
                        $stmt = $conn->prepare($query);
                        $stmt->execute(['task_id' => $taskId]);
                        $all_items = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        // 2. Lekérjük az aktuális tételeket az adatbázisból
                        $query = "SELECT company_id FROM $dbTable WHERE task_id = :task_id AND deleted = 0";
                        $stmt = $conn->prepare($query);
                        $stmt->execute(['task_id' => $taskId]);
                        $current_items = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        // 3. Meghatározzuk a törlendő és a hozzáadandó elemeket
                        $items_to_delete = array_diff($current_items, $new_items);
                        $items_to_add = array_diff($new_items, $current_items);

                        // 4. Törlünk az eltávolított tételeket az adatbázisból
                        if (!empty($items_to_delete)) {

                            //Ellenőrizni, hogy a felhasználó törölheti-e responsibles mezőt
                            $isAccess = $this->auth->authenticate(20);
                            if ($isAccess['status'] !== 200) {
                                return $this->response = $isAccess;
                            }

                            $update_query = "UPDATE $dbTable SET deleted = 1, deleted_at = ?, deleted_by = ?
                                                 WHERE task_id = ? AND company_id IN (" . implode(',', array_fill(0, count($items_to_delete), '?')) . ")";
                            $stmt = $conn->prepare($update_query);

                            // A frissítési értékek paraméterei
                            $params = [$deleted_at, $deleted_by, $taskId];
                            $params = array_merge($params, $items_to_delete); // hozzáadjuk az `item_id`-kat

                            $stmt->execute($params);
                            if ($stmt->execute()) {
                                $payload = array(
                                    'id' => intval($taskId),
                                    'column' => 'responsibles',
                                    'value' => $new_items
                                );
                                $this->response = array(
                                    'status' => 200,
                                    'message' => 'Data update successful',
                                    'payload' => $payload
                                );
                            }
                        }

                        //5. Ha már létezik a státusz, módosítjuk annak deleted státuszát, ha nem, akkor új sort hozunk létre
                        if (!empty($items_to_add)) {
                            //Ellenőrizni, hogy a felhasználó módosíthatja-e responsibles mezőt
                            $isAccess = $this->auth->authenticate(18);
                            if ($isAccess['status'] !== 200) {
                                unset($isAccess['data']);
                                return $this->response = $isAccess;
                            }

                            // Először végigmegyünk az új elemek listáján
                            foreach ($items_to_add as $item_id) {
                                // Ha az elem már szerepel a $current_items listában, akkor UPDATE
                                if (in_array($item_id, $all_items)) {
                                    $update_query = "UPDATE $dbTable SET deleted = ?, updated_at = ?, updated_by = ? WHERE task_id = ? AND company_id = ?";
                                    $stmt = $conn->prepare($update_query);
                                    $params = [0, $updated_at, $userId, $taskId, $item_id]; // Paraméterek a frissítéshez
                                    $stmt->execute($params);
                                    if ($stmt->execute()) {
                                        $payload = array(
                                            'id' => intval($taskId),
                                            'column' => 'responsibles',
                                            'value' => $new_items
                                        );
                                        $this->response = array(
                                            'status' => 200,
                                            'message' => 'Data update successful',
                                            'payload' => $payload
                                        );
                                    }
                                } else {
                                    // Ha az elem még nincs benne, akkor INSERT

                                    //Ellenőrizni, hogy a felhasználó adhat-e hozzá responsibles mezőt
                                    $isAccess = $this->auth->authenticate(19);
                                    if ($isAccess['status'] !== 200) {
                                        return $this->response = $isAccess;
                                    }

                                    $insert_query = "INSERT INTO $dbTable (task_id, company_id, created_by) VALUES (?, ?, ?)";
                                    $stmt = $conn->prepare($insert_query);
                                    $params = [$taskId, $item_id, $userId];
                                    $stmt->execute($params);
                                    if ($stmt->execute()) {
                                        $payload = array(
                                            'id' => intval($taskId),
                                            'column' => 'responsibles',
                                            'value' => $new_items
                                        );
                                        $this->response = array(
                                            'status' => 200,
                                            'message' => 'Data update successful',
                                            'payload' => $payload
                                        );
                                    }
                                }
                            }
                        }
                        break;

                    case 'tasks':
                        $taskId = $data['id'];
                        $dataToHandleInDb = [
                            'table' => $dbTable,
                            'method' => "update",
                            'columns' => [$dbColumn, 'updated_by'],
                            'values' => [$value, $userId],
                            'others' => "",
                            'order' => "",
                            'conditions' => ['id' => $taskId]
                        ];
                        $result = dataToHandleInDb($this->conn, $dataToHandleInDb);
                        break;
                    case 'task_lockers':
                        $taskId = $data['id'];
                        $dataToHandleInDb = [
                            'table' => $dbTable,
                            'method' => "update",
                            'columns' => [$dbColumn, 'updated_by'],
                            'values' => [$value, $userId],
                            'others' => "",
                            'order' => "",
                            'conditions' => ['id' => $taskId]
                        ];
                        $result = dataToHandleInDb($this->conn, $dataToHandleInDb);
                        break;
                    case 'task_locations':
                        $dataToHandleInDb = [
                            'table' => $dbTable,
                            'method' => "update",
                            'columns' => [$dbColumn, 'updated_by'],
                            'values' => [$value, $userId],
                            'others' => "",
                            'order' => "",
                            'conditions' => ['id' => $id]
                        ];
                        $result = dataToHandleInDb($this->conn, $dataToHandleInDb);
                        break;
                    default:
                        $taskId = $data['id'];
                        $dataToHandleInDb = [
                            'table' => $dbTable,
                            'method' => "update",
                            'columns' => [$dbColumn],
                            'values' => [$value],
                            'others' => "",
                            'order' => "",
                            'conditions' => ['task_id' => $taskId]
                        ];
                        $result = dataToHandleInDb($this->conn, $dataToHandleInDb);
                        break;
                }
                if (isset($result)) {
                    if ($result['isUpdated']) {
                        $payload = array(
                            'id' => $id,
                            'taskId' => intval($taskId),
                            'column' => $dbColumn,
                            'value' => $value
                        );
                        $this->response = array(
                            'status' => 200,
                            'message' => $result['message'],
                            'payload' => $payload
                        );
                    } else {
                        $this->response = array(
                            'status' => 400,
                            'message' => $result['error'],
                            'payload' => null
                        );
                    }
                }
            } catch (Exception $e) {
                //echo $e;
                $this->response['error'] =  $e->getMessage();
            }
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$updateTask = new updateTask($conn, $response, $auth);
$updateTask->updateTaskData($isFileUpload, $conn, $file, $locationId);

echo json_encode($response);
