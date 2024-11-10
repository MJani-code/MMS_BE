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

function dataManipulation($conn, $data)
{
    $manipulatedData = array(
        'headers' => [],
        'data' => []
    );


    //getData
    function getData($manipulatedData, $data)
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
    function getHeaders($manipulatedData, $data)
    {
        if (isset($manipulatedData['data'])) {
            foreach ($manipulatedData['data'] as $key => $task) {
                foreach ($task as $key => $header) {
                    switch ($key) {
                        case 'extra_column_value':
                            break;

                        case 'extra_column_permission':
                            break;

                        case 'status_color':
                            break;

                        case 'id';
                            break;

                        case 'responsibles_string':
                            break;

                        case 'location_photos':
                            break;

                        case 'location_id':
                            break;

                        case 'fixing_method':
                            break;

                        case 'status_exohu':
                            break;

                        case 'required_site_preparation':
                            break;

                        case 'taskFees':
                            break;

                        case 'taskTypes':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Típus',
                                    'dbTable' => 'Task_types',
                                    'dbColumn' => 'type_id',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'status_partner_id':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Státusz(partner)',
                                    'dbTable' => 'Tasks',
                                    'dbColumn' => 'status_by_partner_id',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'status_exohu_id':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Státusz(exohu)',
                                    'dbTable' => 'Tasks',
                                    'dbColumn' => 'status_by_exohu_id',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'zip':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Zip',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'zip',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'city':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Település',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'city',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'address':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Cím',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'address',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'location_type':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Lokáció típus',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'location_type',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'fixing_method':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Rögzítési mód',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'fixing_method',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'responsibles':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Megbízottak',
                                    'dbTable' => 'Task_responsibles',
                                    'dbColumn' => 'id',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'planned_delivery_date':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Kivitelezési dátum(terv)',
                                    'dbTable' => 'Task_dates',
                                    'dbColumn' => 'planned_delivery_date',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'delivery_date':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Kivitelezési dátum(tény)',
                                    'dbTable' => 'Task_dates',
                                    'dbColumn' => 'delivery_date',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'extra_column':
                            $exists = in_array($task[$key], array_column($manipulatedData['headers'], 'value'));
                            if (!$exists && $task[$key] != NULL) {
                                $manipulatedData['headers'][] = array(
                                    'text' => $header,
                                    'dbTable' => 'Task_additional_info',
                                    'dbColumn' => 'name',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $task[$key]
                                );
                            }
                            break;

                        default:
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'text'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => $key,
                                    'dbTable' => 'Task_additional_info',
                                    'dbColumn' => 'name',
                                    'align' => 'start',
                                    'isReadonly' => false,
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;
                    }
                }
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

    $manipulatedData = getData($manipulatedData, $data);
    $manipulatedData = getHeaders($manipulatedData, $data);

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
