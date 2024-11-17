<?php
require('/Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE/functions/db/dbFunctions.php');

// Alapértelmezett válasz formázása
function createResponse($status, $errorMessage = '', $data = null)
{
    return [
        'status' => $status,
        'message' => $errorMessage,
        'payload' => $data,
    ];
}

function dataManipulation($conn, $data, $userAuthData)
{
    $manipulatedData = array(
        'headers' => [],
        'data' => [],
        'statuses' => []
    );
    if ($userAuthData['status'] !== 200) {
        return;
    }
    $userRoleId = $userAuthData['data']->roleId;
    // echo $userRoleId;

    //getData
    function getData($conn, $manipulatedData, $data)
    {
        // echo json_encode($data);
        if (isset($data['baseTaskData']['payload'])) {
            $groupedData = [];
            // Segédtömb az ismétlődések elkerülésére
            $uniqueTaskFees = [];

            foreach ($data['baseTaskData']['payload'] as $task) {
                $id = $task['id'];

                // Ellenőrizzük, hogy van-e már ilyen id-vel objektum a groupedData tömbben
                $existingIndex = array_search($id, array_column($groupedData, 'id'));

                // Ha az objektum még nem létezik, hozzuk létre és inicializáljuk a szükséges kulcsokat
                if ($existingIndex === false) {
                    $newTask = $task;
                    $newTask['taskTypes'] = [];
                    $newTask['responsibles'] = [];
                    $newTask['location_photos'] = [];
                    unset($newTask['types'], $newTask['responsible'], $newTask['url']);
                    $groupedData[] = $newTask;
                    $existingIndex = array_key_last($groupedData); // Frissítjük az existingIndex-et az új elem indexével
                }

                // Feldolgozzuk az egyes mezőket
                if (isset($task['url']) && !in_array(['url' => $task['url']], $groupedData[$existingIndex]['location_photos'])) {
                    $groupedData[$existingIndex]['location_photos'][] = ['url' => $task['url']];
                }

                if (isset($task['types']) && $task['types'] !== null && !in_array($task['types'], $groupedData[$existingIndex]['taskTypes'])) {
                    $groupedData[$existingIndex]['taskTypes'][] = $task['types'];
                }

                if (isset($task['responsible']) && $task['responsible'] !== null && !in_array($task['responsible'], $groupedData[$existingIndex]['responsibles'])) {
                    $groupedData[$existingIndex]['responsibles'][] = $task['responsible'];
                }

                $taskFeesFound = false; // Flag a taskFees ellenőrzésére

                // `taskFees` hozzáadása a `groupedData`-hoz, az ismétlődések elkerülésével
                if (isset($data['taskFees']['payload'])) {
                    foreach ($data['taskFees']['payload'] as $taskFee) {
                        $taskId = $taskFee['taskId'];
                        $taskFeeId = $taskFee['id'];

                        // Ellenőrizzük, hogy a `taskFee` már szerepel-e az `uniqueTaskFees` segédtömbben
                        if (!isset($uniqueTaskFees[$taskId][$taskFeeId]) && $taskId === $id) {
                            $groupedData[$existingIndex]['taskFees'][] = $taskFee;
                            $uniqueTaskFees[$taskId][$taskFeeId] = true; // Jelöljük, hogy ez az ID már hozzá lett adva
                            $taskFeesFound = true; // Ha találunk legalább egy taskFee-t
                        }
                        // Ha nem találunk taskFee-t, akkor nem módosítjuk a taskFees kulcsot
                        if (!$taskFeesFound && empty($groupedData[$existingIndex]['taskFees'])) {
                            // Csak akkor állítjuk üres tömbre, ha előzőleg nem lett hozzáadva adat
                            $groupedData[$existingIndex]['taskFees'] = [];
                        }
                    }
                } else {
                    $groupedData[$existingIndex]['taskFees'] = [];
                }
            }

            // Az átrendezett tömb újra indexelése, hogy numerikus tömb legyen
            $groupedData = array_values($groupedData);
            $manipulatedData['data'] = $groupedData;
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'message' => 'Nincsen megjeleníthető adat'
            );
            return $manipulatedData;
        }
    }

    //getHeaders
    function getHeaders($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'Task_columns tc',
                    'method' => "get",
                    'columns' => ['text', 'dbTable', 'dbColumn', 'align', 'filterable', 'value'],
                    'others' => "LEFT JOIN Task_column_permissions tcp on tcp.task_columns_id = tc.id",
                    'conditions' => "tcp.role_id >=
                        (CASE
                        WHEN $userRoleId = $userRoleId THEN $userRoleId
                        ELSE tcp.role_id
                        END) AND tc.task_column_types_id = 1 AND tc.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['headers'] = $result['payload'];
                    //return createResponse($result['status'], $result['message'], $result['payload']);
                } else {
                    return createResponse($result['status'], $result['message'] . '. ' . $result['errorInfo']);
                }
            } catch (Exception $e) {
                return createResponse(500, "Hiba történt: " . $e->getMessage());
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'message' => 'Nincsen megjeleníthető header'
            );
            return $manipulatedData;
        }
    }

    //getStatuses
    function getStatuses($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'Task_statuses ts',
                    'method' => "get",
                    'columns' => ['ts.id', 'name', 'color'],
                    'others' => "LEFT JOIN Task_status_permissions tsp on tsp.task_status_id = ts.id",
                    'conditions' => "tsp.role_id >=
                        (CASE
                        WHEN $userRoleId = $userRoleId THEN $userRoleId
                        ELSE tsp.role_id
                        END) AND ts.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['statuses'] = $result['payload'];
                    //return createResponse($result['status'], $result['message'], $result['payload']);
                } else {
                    return createResponse($result['status'], $result['message'] . '. ' . $result['errorInfo']);
                }
            } catch (Exception $e) {
                return createResponse(500, "Hiba történt: " . $e->getMessage());
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'message' => 'Nincsen megjeleníthető header'
            );
            return $manipulatedData;
        }
    }

    //getLocationTypes
    function getLocationTypes($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'Location_types lt',
                    'method' => "get",
                    'columns' => ['id', 'name', 'color'],
                    'others' => "",
                    'conditions' => "lt.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['locationTypes'] = $result['payload'];
                    //return createResponse($result['status'], $result['message'], $result['payload']);
                } else {
                    return createResponse($result['status'], $result['message'] . '. ' . $result['errorInfo']);
                }
            } catch (Exception $e) {
                return createResponse(500, "Hiba történt: " . $e->getMessage());
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'message' => 'Nincsen megjeleníthető header'
            );
            return $manipulatedData;
        }
    }

    //getLocationTypes
    function getTaskTypes($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'Task_type_details ttd',
                    'method' => "get",
                    'columns' => ['id', 'name', 'color'],
                    'others' => "",
                    'conditions' => "ttd.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['taskTypes'] = $result['payload'];
                    //return createResponse($result['status'], $result['message'], $result['payload']);
                } else {
                    return createResponse($result['status'], $result['message'] . '. ' . $result['errorInfo']);
                }
            } catch (Exception $e) {
                return createResponse(500, "Hiba történt: " . $e->getMessage());
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'message' => 'Nincsen megjeleníthető header'
            );
            return $manipulatedData;
        }
    }

    //getLocationTypes
    function getUsers($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'Responsibles r',
                    'method' => "get",
                    'columns' => ["r.id", "CONCAT(u.last_name,' ',u.first_name) as name"],
                    'others' => "LEFT JOIN Users u on u.id = r.user_id",
                    'conditions' => "r.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['users'] = $result['payload'];
                    //return createResponse($result['status'], $result['message'], $result['payload']);
                } else {
                    return createResponse($result['status'], $result['message'] . '. ' . $result['errorInfo']);
                }
            } catch (Exception $e) {
                return createResponse(500, "Hiba történt: " . $e->getMessage());
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'message' => 'Nincsen megjeleníthető header'
            );
            return $manipulatedData;
        }
    }

    $manipulatedData = getData($conn, $manipulatedData, $data);
    $manipulatedData = getHeaders($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getStatuses($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getLocationTypes($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getTaskTypes($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getUsers($conn, $manipulatedData, $data, $userRoleId);

    return $manipulatedData;
}

function uploadFile($conn, $file, $locationId, $userId, $maxFileSize, $DOC_ROOT, $DOC_URL)
{
    // Engedélyezett fájltípusok
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

    try {
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileError = $file['error'];

        // Fájl kiterjesztés ellenőrzése
        $fileActualExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($fileActualExt, $allowedExtensions)) {
            return createResponse(400, "Ez a fájltípus nem engedélyezett!");
        }

        // Hibaellenőrzés
        if ($fileError !== 0) {
            return createResponse(400, "Hiba történt a fájl feltöltése közben. Kérlek fordulj a rendszergazdához! Hiba: $fileError");
        }

        // Fájlméret ellenőrzése
        if ($fileSize > $maxFileSize) {
            return createResponse(400, "A fájl mérete túl nagy!");
        }

        // Útvonalak létrehozása
        $fileDestination = $DOC_ROOT . '/' . $fileName;
        $fileUrl = $DOC_URL . '/' . $fileName;

        // Ellenőrzi, hogy létezik-e a fájl
        if (file_exists($fileDestination)) {
            return createResponse(400, "A fájl már létezik");
        }

        // Adatok beszúrása az adatbázisba
        $stmt = $conn->prepare(
            "INSERT INTO Task_location_photos
            (location_id, filename, url, created_by)
            VALUES (:location_id, :filename, :url, :created_by)"
        );

        $stmt->execute([
            ':location_id' => $locationId,
            ':filename' => $fileName,
            ':url' => $fileUrl,
            ':created_by' => $userId,
        ]);

        $payload = array(
            'photoUpload' => true,
            'locationId' => intval($locationId),
            'url' => $fileUrl
        );

        // Ellenőrzés, hogy sikeres volt-e a beszúrás
        if ($stmt->rowCount() > 0) {
            // Fájl mozgatása és jogosultságok beállítása
            $isFileUplaoded = move_uploaded_file($fileTmpName, $fileDestination);
            chmod($fileDestination, 0777);
            if ($isFileUplaoded) {
                return createResponse(200, "A fájl sikeresen feltöltve.", $payload);
            }
        } else {
            return createResponse(500, "Az adatbázis művelet sikertelen.");
        }
    } catch (Exception $e) {
        return createResponse(500, "Hiba történt: " . $e->getMessage());
    }
}

function addFee($conn, $dbTable, $newItems, $userId)
{
    try {
        $created_at = date('Y-m-d H:i:s');

        $insert_query = "INSERT INTO $dbTable (task_id, fee_id, other_items, net_unit_price, quantity, total, created_at, created_by) VALUES (?, ?, ?,?,?,?,?,?)";
        $stmt = $conn->prepare($insert_query);
        $params = [$newItems['taskId'], $newItems['feeId'], $newItems['otherItems'], $newItems['netUnitPrice'], $newItems['quantity'], $newItems['total'], $created_at, $userId];
        // $stmt->execute($params);

        $dataToHandleInDb = [
            'table' => $dbTable,
            'method' => "get",
            'columns' => ['MAX(id)'],
            'others' => "",
            'order' => ""
        ];
        $getMaxId = dataToHandleInDb($conn, $dataToHandleInDb);
        if ($getMaxId['status'] === 200) {
            $newItems['id'] = $getMaxId['payload'][0]['MAX(id)'];
        } else {
            return createResponse($getMaxId['status'], $getMaxId['message'] . '. ' . $getMaxId['errorInfo']);
        }
        if ($stmt->execute($params)) {
            return createResponse(200, "Item insertion success", $newItems);
        }
    } catch (Exception $e) {
        return createResponse(500, "Hiba történt: " . $e->getMessage());
    }
}

function deleteFee($conn, $dbTable, $id, $taskId, $userId)
{
    try {
        $deleted_at = date('Y-m-d H:i:s');

        $dataToHandleInDb = [
            'table' => $dbTable,
            'method' => "update",
            'columns' => ['deleted', 'deleted_at', 'deleted_by'],
            'values' => [1, $deleted_at, $userId],
            'others' => "",
            'conditions' => ['task_id' => $taskId, 'id' => $id]
        ];
        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        if ($result['status'] === 200) {
            return createResponse($result['status'], $result['message'], $id = array('id' => $id, 'taskId' => $taskId));
        } else {
            return createResponse($result['status'], $result['message'] . '. ' . $result['error']);
        }
    } catch (Exception $e) {
        return createResponse(500, "Hiba történt: " . $e->getMessage());
    }
}
