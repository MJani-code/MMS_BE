<?php
require('db/dbFunctions.php');
require('../../vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Hyperlink;

// Alapértelmezett válasz formázása
function createResponse($status, $errorMessage = '', $data = null)
{
    return [
        'status' => $status,
        'message' => $errorMessage,
        'payload' => $data,
    ];
}

function dataManipulation($conn, $data, $userAuthData, $tofShopIds, $getAllActivePointsUrl, $user, $password)
{
    $manipulatedData = array(
        'status' => 200,
        'headers' => [],
        'data' => [],
        'statuses' => []
    );
    if ($userAuthData['status'] !== 200) {
        return;
    }
    $userRoleId = $userAuthData['data']->roleId;

    //getData
    function getData($conn, $manipulatedData, $data, $tofShopIds, $getAllActivePointsUrl, $user, $password)
    {

        if (isset($data['baseTaskData']['payload'])) {
            //getExoboxPoints
            $exoboxPoints = getExoboxPoints($getAllActivePointsUrl, $user, $password, null);

            $groupedData = [];
            // Segédtömb az ismétlődések elkerülésére
            $uniqueTaskFees = [];
            $uniqueLockers = [];

            foreach ($data['baseTaskData']['payload'] as $task) {
                $id = $task['id'];
                //$tofShopId = $task['tof_shop_id'];                

                // Ellenőrizzük, hogy van-e már ilyen id-vel objektum a groupedData tömbben
                $existingIndex = array_search($id, array_column($groupedData, 'id'));

                // Ha az objektum még nem létezik, hozzuk létre és inicializáljuk a szükséges kulcsokat
                if ($existingIndex === false) {
                    $newTask = $task;
                    $newTask['taskTypes'] = [];
                    $newTask['lockerSerials'] = [];
                    $newTask['responsibles'] = [];
                    $newTask['location_photos'] = [];
                    $newTask['isActiveInAdmin'] = null;
                    $newTask['pointId'] = null;
                    unset($newTask['types'], $newTask['responsible'], $newTask['url']);
                    $groupedData[] = $newTask;
                    $existingIndex = array_key_last($groupedData); // Frissítjük az existingIndex-et az új elem indexével
                }

                // Feldolgozzuk az egyes mezőket
                if (isset($task['url']) && !in_array(['url' => $task['url']], $groupedData[$existingIndex]['location_photos'])) {
                    $groupedData[$existingIndex]['location_photos'][] = ['url' => $task['url']];
                }

                //ha a $task['tof_shop_id'] értéke szerepel a $tofShopIds tömbben, akkor az isActiveInAdmin értéke true, egyébként false
                if (in_array($task['tof_shop_id'], $tofShopIds)) {
                    $groupedData[$existingIndex]['isActiveInAdmin'] = true;
                } else {
                    $groupedData[$existingIndex]['isActiveInAdmin'] = false;
                }

                foreach ($exoboxPoints['payload'] as $point) {
                    //echo json_encode($point);
                    if ($point['id'] == $task['tof_shop_id']) {
                        $groupedData[$existingIndex]['pointId'] = $point['point_id'];
                        break;
                    }
                }

                if (isset($task['types']) && $task['types'] !== null && !in_array($task['types'], $groupedData[$existingIndex]['taskTypes'])) {
                    $groupedData[$existingIndex]['taskTypes'][] = $task['types'];
                }

                if (isset($task['responsible']) && $task['responsible'] !== null && !in_array($task['responsible'], $groupedData[$existingIndex]['responsibles'])) {
                    $groupedData[$existingIndex]['responsibles'][] = $task['responsible'];
                }


                // `taskFees` hozzáadása a `groupedData`-hoz, az ismétlődések elkerülésével
                $taskFeesFound = false; // Flag a taskFees ellenőrzésére
                if (!empty($data['taskFees']['payload'])) {
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

                //Add lockers into lockerSerials
                // if (isset($data['lockers']['payload'])) {
                //     foreach ($data['lockers']['payload'] as $locker) {
                //         if (isset($locker['serial']) && $locker['serial'] !== null && !in_array($locker['serial'], $groupedData[$existingIndex]['lockerSerials'])) {
                //             $groupedData[$existingIndex]['lockerSerials'][] = $locker['serial'];
                //         }
                //     }
                // }


                $lockerFound = false; // Flag a locker ellenőrzésére
                if (!empty($data['lockers']['payload'])) {
                    foreach ($data['lockers']['payload'] as $locker) {
                        $lockerId = $locker['id'];
                        $locationId = $locker['task_locations_id'];
                        $lockerTaskId = $locker['task_id'];

                        // Ellenőrizzük, hogy a `locker` már szerepel-e az `uniqueLockers` segédtömbben
                        if (!isset($uniqueLockers[$lockerId][$lockerTaskId]) && $lockerTaskId === $task['id']) {
                            $groupedData[$existingIndex]['lockers'][] = $locker;
                            $groupedData[$existingIndex]['lockerSerials'][] = $locker['serial'];
                            $uniqueLockers[$lockerId][$lockerTaskId] = true; // Jelöljük, hogy ez az ID már hozzá lett adva
                            $lockerFound = true; // Ha találunk legalább egy locker-t
                        }
                        // Ha nem találunk locker-t, akkor nem módosítjuk a locker kulcsot
                        if (!$lockerFound && empty($groupedData[$existingIndex]['lockers'])) {
                            // Csak akkor állítjuk üres tömbre, ha előzőleg nem lett hozzáadva adat
                            $groupedData[$existingIndex]['lockers'] = [];
                            $groupedData[$existingIndex]['lockerSerials'] = [];
                        }
                    }
                } else {
                    $groupedData[$existingIndex]['lockers'] = [];
                }
            }

            //fees hozzáadása
            if (!empty($data['fees']['payload'])) {
                $manipulatedData['fees'] = $data['fees']['payload'];
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
                    'table' => 'task_columns tc',
                    'method' => "get",
                    'columns' => ['text', 'dbTable', 'dbColumn', 'align', 'filterable', 'value'],
                    'others' => "LEFT JOIN task_column_permissions tcp on tcp.task_columns_id = tc.id",
                    'conditions' => "tcp.role_id >=
                        (CASE
                        WHEN $userRoleId = $userRoleId THEN $userRoleId
                        ELSE tcp.role_id
                        END) AND tc.task_column_types_id = 1 AND tc.is_active = 1 ORDER BY tc.orderId ASC"
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
                    'table' => 'task_statuses ts',
                    'method' => "get",
                    'columns' => ['ts.id', 'name', 'color'],
                    'others' => "LEFT JOIN task_status_permissions tsp on tsp.task_status_id = ts.id",
                    'conditions' => "ts.is_active = 1"
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

    //getStatuses
    function getAllowedStatuses($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'task_statuses ts',
                    'method' => "get",
                    'columns' => ['ts.id', 'name', 'color'],
                    'others' => "LEFT JOIN task_status_permissions tsp on tsp.task_status_id = ts.id",
                    'conditions' => "tsp.role_id >=
                        (CASE
                        WHEN $userRoleId = $userRoleId THEN $userRoleId
                        ELSE tsp.role_id
                        END) AND ts.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['allowedStatuses'] = $result['payload'];
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
                    'table' => 'location_types lt',
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
                    'table' => 'task_type_details ttd',
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
    function getResponsibles($conn, $manipulatedData, $data, $userRoleId)
    {
        if (isset($manipulatedData['data'])) {
            try {
                $dataToHandleInDb = [
                    'table' => 'responsibles r',
                    'method' => "get",
                    'columns' => ["r.company_id as id", "c.name as name"],
                    'others' => "LEFT JOIN companies c on c.id = r.company_id",
                    'conditions' => "r.is_active = 1"
                ];
                $result = dataToHandleInDb($conn, $dataToHandleInDb);
                if ($result['status'] === 200) {
                    $manipulatedData['companies'] = $result['payload'];
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

    $manipulatedData = getData($conn, $manipulatedData, $data, $tofShopIds, $getAllActivePointsUrl, $user, $password);
    $manipulatedData = getHeaders($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getStatuses($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getAllowedStatuses($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getLocationTypes($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getTaskTypes($conn, $manipulatedData, $data, $userRoleId);
    $manipulatedData = getResponsibles($conn, $manipulatedData, $data, $userRoleId);

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
            "INSERT INTO task_location_photos
            (task_locations_id, filename, url, created_by)
            VALUES (:task_locations_id, :filename, :url, :created_by)"
        );

        $stmt->execute([
            ':task_locations_id' => $locationId,
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
            // Ellenőrizd, hogy létezik-e a könyvtár
            if (!is_dir(dirname($fileDestination))) {
                mkdir(dirname($fileDestination), 0777, true);
            }
            // Ha a kép file mérete nagyobb, mint 1MB, akkor felezze a méretét
            if ($fileSize > 1000000) {
                $image = imagecreatefromstring(file_get_contents($fileTmpName));
                $newImage = imagescale($image, imagesx($image) / 2, imagesy($image) / 2);
                if (function_exists('exif_read_data')) {
                    $exif = @exif_read_data($fileTmpName);
                    if ($exif && isset($exif['Orientation'])) {
                        $orientation = $exif['Orientation'];
                        switch ($orientation) {
                            case 3:
                                $newImage = imagerotate($newImage, 180, 0);
                                break;
                            case 6:
                                $newImage = imagerotate($newImage, -90, 0);
                                break;
                            case 8:
                                $newImage = imagerotate($newImage, 90, 0);
                                break;
                        }
                    }
                }
                imagejpeg($newImage, $fileDestination);
                // Eredeti kép törlése a memóriából
                imagedestroy($image);
            } else {
                move_uploaded_file($fileTmpName, $fileDestination);
            }

            // Jogosultságok beállítása
            chmod($fileDestination, 0777);

            return createResponse(200, "A fájl sikeresen feltöltve.", $payload);
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

        $insert_query = "INSERT INTO $dbTable (task_id, fee_id, serial, other_items, net_unit_price, quantity, total, created_at, created_by) VALUES (?, ?, ?, ?,?,?,?,?,?)";
        $stmt = $conn->prepare($insert_query);
        $params = [$newItems['taskId'], $newItems['feeId'], $newItems['lockerSerial'], $newItems['otherItems'], $newItems['netUnitPrice'], $newItems['quantity'], $newItems['total'], $created_at, $userId];
        // $stmt->execute($params);

        $dataToHandleInDb = [
            'table' => $dbTable,
            'method' => "get",
            'columns' => ['MAX(id)'],
            'others' => "",
            'order' => ""
        ];
        if ($stmt->execute($params)) {
            $getMaxId = dataToHandleInDb($conn, $dataToHandleInDb);
            if ($getMaxId['status'] === 200) {
                $newItems['id'] = $getMaxId['payload'][0]['MAX(id)'];
            } else {
                return createResponse($getMaxId['status'], $getMaxId['message'] . '. ' . $getMaxId['errorInfo']);
            }
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

function addLocker($conn, $newItems, $userId)
{
    try {
        $created_at = date('Y-m-d H:i:s');
        $dbTable = $newItems['dbTable'];

        $insert_query = "INSERT INTO $dbTable (task_id, tof_shop_id, task_locations_id, serial, created_by) VALUES (?,?,?,?,?)";
        $stmt = $conn->prepare($insert_query);
        $params = [$newItems['task_id'], $newItems['tof_shop_id'], $newItems['task_locations_id'], $newItems['value'], $userId];

        $lockers = [
            'table' => "$dbTable tl",
            'method' => "get",
            'columns' => [
                'tl.id',
                'tl.task_id as taskId',
                'tl.brand',
                'tl.serial',
                'tl.task_locations_id',
                'tl.is_active as isActive'
            ],
            'others' => "",
            'conditions' => "tl.deleted = 0 AND tl.task_locations_id = $newItems[task_locations_id] AND tl.task_id = $newItems[task_id]"
        ];

        if ($stmt->execute($params)) {
            $newLockerData = dataToHandleInDb($conn, $lockers);
            return createResponse(200, "Item insertion success", $newLockerData['payload']);
        }
    } catch (Exception $e) {
        return createResponse(400, "Hiba történt: " . $e->getMessage());
    }
}

function removeLocker($conn, $lockerToRemove)
{
    try {
        $serial = $lockerToRemove['value'];

        $dataToHandleInDb = [
            'table' => "task_lockers",
            'method' => "delete",
            'columns' => [],
            'conditions' => ['serial' => $serial, 'id' => $lockerToRemove['id']]
        ];
        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        if ($result['status'] === 200) {
            return createResponse($result['status'], "Delete of locker is success");
        } else {
            return createResponse($result['status'], $result['message'] . '. ' . $result['error']);
        }
    } catch (Exception $e) {
        return createResponse(400, "Hiba történt: " . $e->getMessage());
    }
}

function getUserPassword($conn, $userId)
{
    try {
        $dataToHandleInDb = [
            'table' => "users u",
            'method' => "get",
            'columns' => ['u.password'],
            'others' => "",
            'conditions' => "u.id = $userId",
            'order' => ""
        ];
        $data = dataToHandleInDb($conn, $dataToHandleInDb);
        if ($data['status'] === 200) {
            return createResponse($data['status'], "success", $data['payload'][0]);
        } else {
            return createResponse($data['status'], $data['message'] . '. ' . $data['errorInfo']);
        }
    } catch (Exception $e) {
        return createResponse(500, "Hiba történt: " . $e->getMessage());
    }
}

function updateUser($conn, $hashedNewPassword, $firstName, $lastName, $email, $userId)
{
    try {
        $updated_at = date('Y-m-d H:i:s');

        $dataToHandleInDb = [
            'table' => "users u",
            'method' => "update",
            'columns' => ['first_name', 'last_name', 'email', 'password', 'updated_at', 'updated_by'],
            'values' => [$firstName, $lastName, $email, $hashedNewPassword, $updated_at, $userId],
            'others' => "",
            'conditions' => ['u.id' => $userId]
        ];
        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        if ($result['status'] === 200) {
            return createResponse($result['status'], $result['message']);
        } else {
            return createResponse($result['status'], $result['message'] . '. ' . $result['error']);
        }
    } catch (Exception $e) {
        return createResponse(500, "Hiba történt: " . $e->getMessage());
    }
}

function xlsFileRead($filePath)
{
    try {
        $spreadsheet = IOFactory::load($filePath);

        // Az első munkalap kiválasztása
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Fejléc beolvasása
        $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', NULL, TRUE, FALSE)[0];
        $highestRow = $sheet->getHighestRow('A');

        // 2. Az érdekes fejlécek meghatározása
        $wantedHeaders = ['Name', 'Serial Number', 'TofShop ID', 'ZIP code', 'City', 'Address', 'External / Internal', 'Fixing', 'Site Preparation required', 'Comment'];
        $requiredFields = ['TofShop ID', 'External / Internal'];
        $headerIndexes = [];
        $keyMapping = [
            'Name' => 'name',
            'TofShop ID' => 'tof_shop_id',
            'ZIP code' => 'zip',
            'City' => 'city',
            'Address' => 'address',
            'Contact' => 'contact',
            'Phone' => 'phone',
            'Email' => 'email',
            'External / Internal' => 'location_type_id',
            'Fixing' => 'fixing_method',
            'Site Preparation required' => 'required_site_preparation',
            'Comment' => 'comment',
            'Serial Number' => 'serial'
        ];

        foreach ($wantedHeaders as $wantedHeader) {
            $index = array_search($wantedHeader, $headerRow); // Az oszlop indexének keresése
            if ($index !== false) {
                $headerIndexes[$wantedHeader] = $index; // Tároljuk az oszlop indexét
            }
        }

        // Ellenőrizzük, hogy minden szükséges fejléc megtalálható
        if (count($headerIndexes) !== count($wantedHeaders)) {
            return createResponse(400, "Nem minden szükséges fejléc található meg az Excel fájlban!");
        }

        //Kicseréljük a header-t az adatbázis header-re.
        $headerIndexesFitToDb = [];
        foreach ($headerIndexes as $headerKey => $headerValue) {
            foreach ($keyMapping as $key => $value) {
                if ($headerKey == $key) {
                    $headerIndexesFitToDb[$value] = $headerValue;
                }
            }
        }

        // 3. Adatok kigyűjtése
        $data = [];

        foreach ($sheet->getRowIterator(2, $highestRow) as $row) { // A második sortól indulva
            $rowIndex = $row->getRowIndex(); // Az aktuális sor indexe
            $rowData = $sheet->rangeToArray(
                'A' . $rowIndex . ':' . $sheet->getHighestColumn() . $rowIndex,
                NULL,
                TRUE,
                FALSE
            )[0];

            // Csak a kívánt oszlopok értékeinek kigyűjtése
            $filteredData = [];

            foreach ($headerIndexesFitToDb as $header => $index) {
                $headerValue = array_search($index, $headerIndexes);
                $value = $rowData[$index];
                if (in_array($headerValue, $requiredFields)) {
                    if ($value === null || $value == "") {
                        return createResponse(400, "A betöltés nem sikerült. Van olyan kötelező mező, aminél nincsen adat megadva");
                    }
                }

                if ($value === 'Internal') {
                    $value = 1;
                }
                if ($value === 'External') {
                    $value = 2;
                }
                $filteredData[$header] = $value;
            }
            $data[] = $filteredData;
        }
        return createResponse(200, "success", $data);
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        return createResponse(400, $e->getMessage());
    } catch (Exception $e) {
        return createResponse(400, $e->getMessage());
    }
}

function xlsFileDataToWrite($conn, $filePath, $userId)
{
    $created_at = date('Y-m-d H:i:s');

    $data = xlsFileRead($filePath);
    if ($data['status'] !== 200) {
        return $data;
    }
    $newLocations = $data['payload'];

    $stmt = $conn->query('SELECT tof_shop_id FROM task_locations');
    $alreadyExistedTofShopId = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $multipleTofShopId = [];


    try {
        // Tranzakció indítása
        $conn->beginTransaction();

        // `tasks` tábla beszúró lekérdezés
        $taskSql = "INSERT INTO tasks (task_locations_id, created_by) VALUES (?,?)";
        $taskStmt = $conn->prepare($taskSql);

        // `task_locations` tábla beszúró lekérdezés
        $taskLocationSql = "INSERT INTO task_locations (name, tof_shop_id, zip, city, address, created_by, location_type_id, fixing_method, required_site_preparation, comment) VALUES (?,?,?,?,?,?,?,?,?,?)";
        $taskLocationStmt = $conn->prepare($taskLocationSql);

        //task_types táblába beszúró lekérdezés
        $taskTypesSql = "INSERT INTO task_types (type_id, task_id, created_by) VALUES (?,?,?)";
        $taskTypesStmt = $conn->prepare($taskTypesSql);

        //task_dates táblába beszúró lekérdezés
        $taskDatesSql = "INSERT INTO task_dates (task_id, created_by) VALUES (?,?)";
        $taskDatesStmt = $conn->prepare($taskDatesSql);

        //lockers táblába beszúró lekérdezés
        // $lockersSql = "INSERT INTO lockers (tof_shop_id, brand, serial, type, created_by) VALUES (?,?,?,?,?)";
        // $lockersStmt = $conn->prepare($lockersSql);


        foreach ($newLocations as $newLocation) {

            //Ha már létezik a TofShop ID visszadobjuk
            // if (in_array($newLocation['tof_shop_id'], $alreadyExistedTofShopId)) {
            //     return createResponse(400, $newLocation['tof_shop_id'] . " azonosítóval már létezik elem. A betöltés nem sikerült");
            // }

            // Location adatok bezsúrása a task_locations táblába
            $taskLocationStmt->execute([$newLocation['name'], $newLocation['tof_shop_id'], $newLocation['zip'], $newLocation['city'], $newLocation['address'], $userId, $newLocation['location_type_id'], $newLocation['fixing_method'], $newLocation['required_site_preparation'], $newLocation['comment']]);
            $taskLocationId = $conn->lastInsertId();

            // Task beszúrása a `tasks` táblába
            $taskStmt->execute([$taskLocationId, $userId]);
            $taskId = $conn->lastInsertId();

            // Type adatok bezsúrása a task_types táblába
            $taskTypesStmt->execute([3, $taskId, $userId]);

            // Date adatok bezsúrása a task_dates táblába
            $taskDatesStmt->execute([$taskId, $userId]);

            //Ha a serial nem üres akkor beszúrjuk a lockers táblába
            // if ($newLocation['serial'] !== null) {
            //     $lockersStmt->execute([$newLocation['tof_shop_id'], 'ARKA', $newLocation['serial'], 'Master', $userId]);
            // }
        }

        // Tranzakció lezárása
        $conn->commit();
        return createResponse(200, "Sikeres betöltés");
    } catch (Exception $e) {
        // Hiba esetén rollback
        $conn->rollBack();
        return createResponse(400, $e->getMessage());
    }
}

function downloadTig($conn, $companyId)
{
    try {
        // SQL Lekérdezés
        $stmt = $conn->query("SELECT tl.name, CONCAT(tl.zip,' ',tl.city,' ',tl.address) as helyszín ,td.delivery_date, f.name as díjtípus, tf.serial, tf.net_unit_price, tf.quantity, tf.total, tl.tof_shop_id, c.name as companyName
        FROM task_fees tf
        LEFT JOIN tasks t on t.id = tf.task_id
        LEFT JOIN task_locations tl on tl.id = t.task_locations_id
        LEFT JOIN task_dates td on td.task_id = t.id
        LEFT JOIN (
            SELECT MIN(id) as id, task_id
            FROM task_responsibles tr_min
            GROUP BY task_id
            ) tr_min ON tr_min.task_id = t.id
        LEFT JOIN task_responsibles tr on tr.task_id = t.id AND tr.id = tr_min.id AND tr.deleted = 0
        LEFT JOIN companies c on c.id = tr.company_id
        LEFT JOIN fees f on f.id = tf.fee_id 
        WHERE t.status_by_exohu_id = 9 AND tf.deleted = 0 AND tr.company_id = $companyId;");
        $adatok = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Excel generálása
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Fejléc
        $fejlec = ['Helyszín neve', 'Cím', 'Telepítés dátuma', 'Díjtípus', 'Sorozatszám', 'Nettó egységár', 'Mennyiség', 'Összesen', 'TofShop ID', 'Cég neve'];
        $sheet->fromArray($fejlec, NULL, 'A1');

        // Adatok beírása
        $startRow = 2;
        foreach ($adatok as $index => $sor) {
            $sheet->fromArray(array_values($sor), NULL, 'A' . ($startRow + $index));
        }

        // Ideiglenes fájl létrehozása
        $temp_file = tempnam(sys_get_temp_dir(), 'excel');
        $temp_file_with_ext = $temp_file . '.xlsx';
        rename($temp_file, $temp_file_with_ext);

        $writer = new Xlsx($spreadsheet);
        $writer->save($temp_file_with_ext);

        // Fájl letöltése
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="tig.xlsx"');
        header('Cache-Control: max-age=0');
        readfile($temp_file_with_ext);

        // Fájl törlése
        unlink($temp_file_with_ext);
        exit();
    } catch (Exception $e) {
        // Győződj meg róla, hogy nincs semmilyen extra kimenet a HTTP fejlécek előtt
        header('Content-Type: application/json');
        echo json_encode(createResponse(400, $e->getMessage()));
        exit();
    }
}

function downloadTasks($conn, $inputData)
{
    try {
        $statuses = implode(',', $inputData[0]['statuses']);
        // SQL Lekérdezés
        $stmt = $conn->query("SELECT t.id as taskId, tl.tof_shop_id as tofShopId, tl.name as name, concat(tl.city,' ',tl.address) as address, tf.net_unit_price as NetUnitPrice, tf.quantity as quantity, tf.total as total, f.name as fee, td.delivery_date as deliveryDate, c.name as companyName from task_fees tf
            LEFT JOIN tasks t on tf.task_id = t.id
            LEFT JOIN task_dates td on td.task_id = t.id
            LEFT JOIN task_locations tl on tl.id = t.task_locations_id AND t.status_by_exohu_id IN ($statuses)
            LEFT JOIN (
            SELECT MIN(id) as id, task_id
            FROM task_responsibles tr_min
            GROUP BY task_id
            ) tr_min ON tr_min.task_id = t.id
            LEFT JOIN fees f on f.id = tf.fee_id
            LEFT JOIN task_responsibles tr on tr.task_id = t.id AND tr.id = tr_min.id
            LEFT JOIN companies c on c.id = tr.company_id
            WHERE t.status_by_exohu_id IN ($statuses) AND tf.deleted = 0;");
        $adatok = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Excel generálása
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Fejléc
        $fejlec = ['taskId', 'TofShop ID', 'name', 'address', 'unitPrice(net)', 'quantity', 'total', 'fee', 'deliveryDate', 'companyName'];
        $sheet->fromArray($fejlec, NULL, 'A1');

        // Adatok beírása
        $startRow = 2;
        foreach ($adatok as $index => $sor) {
            $sheet->fromArray(array_values($sor), NULL, 'A' . ($startRow + $index));
        }

        // Ideiglenes fájl létrehozása
        $temp_file = tempnam(sys_get_temp_dir(), 'excel');
        $temp_file_with_ext = $temp_file . '.xlsx';
        rename($temp_file, $temp_file_with_ext);

        $writer = new Xlsx($spreadsheet);
        $writer->save($temp_file_with_ext);

        // Fájl letöltése
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="completedtasks.xlsx"');
        header('Cache-Control: max-age=0');
        readfile($temp_file_with_ext);

        // Fájl törlése
        unlink($temp_file_with_ext);
        exit();
    } catch (Exception $e) {
        // Győződj meg róla, hogy nincs semmilyen extra kimenet a HTTP fejlécek előtt
        header('Content-Type: application/json');
        echo json_encode(createResponse(400, $e->getMessage()));
        exit();
    }
}

function updateCheckLockerResult($conn, $data, $userId)
{
    try {
        $dataToHandleInDb = [
            'table' => "task_lockers",
            'method' => "update",
            'columns' => ['is_registered', 'is_active', 'private_key1_error', 'battery_level', 'current_version', 'last_connection_timestamp', 'updated_by'],
            'values' => [$data['is_registered'], $data['is_active'], $data['privateKey1Error'] ? 1 : 0, $data['batteryLevel'], $data['currentVersion'], $data['lastConnectionTimestamp'], $userId],
            'conditions' => ['id' => $data['id']]
        ];
        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        if ($result['status'] === 200) {
            return createResponse($result['status'], $result['message']);
        } else {
            return createResponse($result['status'], $result['message'] . '. ' . $result['error']);
        }
    } catch (Exception $e) {
        return createResponse(500, "Hiba történt: " . $e->getMessage());
    }
}

function addTask($conn, $newTask, $userId)
{
    $typeId = $newTask['taskType'];
    $responsible = $newTask['responsible'];
    $plannedDeliveryDate = $newTask['plannedDeliveryDate'];
    $tofShopId = $newTask['selectedLocation']['tofShopId'];
    $taskLocationsId = $newTask['selectedLocation']['id'];


    //ha a fenti adatok üresek akkor hibaüzenetet kell visszaadni
    if (empty($typeId) || empty($tofShopId)) {
        return createResponse(400, "A kötelező mezők nincsenek kitöltve");
    }

    //Ha a typeId egyenlő 1-el vagy 2-vel és a newTask['lockers'] tömb üres akkor hibaüzenetet kell visszaadni
    if (($typeId == 1 || $typeId == 2)) {
        if (empty($newTask['lockers'])) {
            return createResponse(400, "A lockers mező nincsen kitöltve");
        }
        foreach ($newTask['lockers'] as $locker) {
            if (empty($locker['serial'])) {
                return createResponse(400, "A uuid mező nincsen kitöltve");
            }
            foreach ($locker['issues'] as $issue) {
                if (!isset($issue['type']) || !isset($issue['compartmentNumber'])) {
                    return createResponse(400, "A locker hibamező nincsen kitöltve");
                }
            }
        }
    }

    try {
        // Tranzakció indítása
        $conn->beginTransaction();

        //Helyszín adatainak lekérése tofShopId alapján
        $dataToHandleInDb = [
            'table' => "task_locations tl",
            'method' => "get",
            'columns' => ['tl.id', 'tl.tof_shop_id', 'tl.box_id', 'tl.location_type_id', 'tl.name', 'tl.zip', 'tl.city', 'tl.address'],
            'others' => "",
            'conditions' => "tof_shop_id = $tofShopId"
        ];

        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        $boxId = $result['payload'][0]['box_id'];
        $locationTypeId = $result['payload'][0]['location_type_id'];
        $name = $result['payload'][0]['name'];
        $zip = $result['payload'][0]['zip'];
        $city = $result['payload'][0]['city'];
        $address = $result['payload'][0]['address'];


        // `tasks_locations` tábla beszúró lekérdezés
        $taskLocationsSql = "INSERT INTO task_locations (tof_shop_id, box_id, location_type_id, name, zip, city, address, created_by) VALUES (?,?,?,?,?,?,?,?)";
        $taskLocationsStmt = $conn->prepare($taskLocationsSql);
        $taskLocationsStmt->execute([$tofShopId, $boxId, $locationTypeId, $name, $zip, $city, $address, $userId]);

        //legutóbbi location_id lekérdezése
        $dataToHandleInDb = [
            'table' => "task_locations",
            'method' => "get",
            'columns' => ['MAX(id)'],
            'others' => "",
            'conditions' => ""
        ];
        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        $locationId = $result['payload'][0]['MAX(id)'];

        //Ha a newTask['location'] tömbb tartalmaz location adatokat akkor updatelni kell a location táblát location_id alapján
        if (isset($newTask['location'])) {
            $fixingMethod = $newTask['location']['fixingMethod'];
            $requiredSitePreparation = $newTask['location']['requiredSitePreparation'];
            $comment = $newTask['location']['comment'];

            $dataToHandleInDb = [
                'table' => "task_locations",
                'method' => "update",
                'columns' => ['fixing_method', 'required_site_preparation', 'comment'],
                'values' => [$fixingMethod, $requiredSitePreparation, $comment],
                'conditions' => ['id' => $locationId]
            ];
            $result = dataToHandleInDb($conn, $dataToHandleInDb);
            if ($result['status'] !== 200) {
                return createResponse($result['status'], $result['message'] . '. ' . $result['error']);
            }
        }

        // `tasks` tábla beszúró lekérdezés
        $taskSql = "INSERT INTO tasks (task_locations_id, created_by) VALUES (?,?)";
        $taskStmt = $conn->prepare($taskSql);
        $taskStmt->execute([$locationId, $userId]);

        //legutóbbi task_id lekérdezése
        $dataToHandleInDb = [
            'table' => "tasks",
            'method' => "get",
            'columns' => ['MAX(id)'],
            'others' => "",
            'conditions' => ""
        ];
        $result = dataToHandleInDb($conn, $dataToHandleInDb);
        $taskId = $result['payload'][0]['MAX(id)'];

        //task_lockers táblába beszúró lekérdezés
        if (isset($newTask['lockers'])) {
            foreach ($newTask['lockers'] as $locker) {
                $lockerSql = "INSERT INTO task_lockers (task_id, task_locations_id, tof_shop_id, brand, serial, type, created_by) VALUES (?,?,?,?,?,?,?)";
                $lockerStmt = $conn->prepare($lockerSql);
                $lockerStmt->execute([$taskId, $taskLocationsId, $tofShopId, $locker['brand'], $locker['serial'], $locker['type'], $userId]);
            }
        }

        // `task_types` tábla beszúró lekérdezés
        $taskTypesSql = "INSERT INTO task_types (type_id, task_id, created_by) VALUES (?,?,?)";
        $taskTypesStmt = $conn->prepare($taskTypesSql);
        $taskTypesStmt->execute([$typeId, $taskId, $userId]);

        //task_dates táblába beszúró lekérdezés
        $taskDatesSql = "INSERT INTO task_dates (task_id, planned_delivery_date, created_by) VALUES (?,?,?)";
        $taskDatesStmt = $conn->prepare($taskDatesSql);
        $taskDatesStmt->execute([$taskId, $plannedDeliveryDate, $userId]);

        //task_responsibels táblába beszúró lekérdezés
        if ($responsible) {
            $taskResponsiblesSql = "INSERT INTO task_responsibles (company_id, task_id, created_by) VALUES (?,?,?)";
            $taskResponsiblesStmt = $conn->prepare($taskResponsiblesSql);
            $taskResponsiblesStmt->execute([$responsible, $taskId, $userId]);
        }

        //newTask['lockers'] körbejárása és adatainak beszúrása a task_locker_issues táblába
        foreach ($newTask['lockers'] as $locker) {
            foreach ($locker['issues'] as $issue) {
                $lockerSql = "INSERT INTO task_lockers_issues (task_lockers_id, task_id, tof_shop_id, uuid, issue_type, description, compartment_number, created_by) VALUES (?,?,?,?,?,?,?,?)";
                $lockerStmt = $conn->prepare($lockerSql);
                $lockerStmt->execute([$locker['lockerId'], $taskId, $tofShopId, $locker['serial'], $issue['type'], $locker['description'], $issue['compartmentNumber'], $userId]);
            }
        }

        // Tranzakció lezárása
        $conn->commit();
        return createResponse(200, "Sikeres betöltés", $newTask);
    } catch (Exception $e) {
        // Hiba esetén rollback
        $conn->rollBack();
        return createResponse(400, "Hiba történt a művelet során: " . $e->getMessage());
    }
}

function deleteImage($conn, $url, $DOC_ROOT)
{
    try {
        // Get the file URL from the database
        $stmt = $conn->prepare("SELECT url, task_locations_id, filename FROM task_location_photos WHERE url = :url");
        $stmt->execute([':url' => $url]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        //echo json_encode($file);
        //echo $DOC_ROOT;
        if ($file) {
            $filePath = $DOC_ROOT . '/uploads' . '/' . $file['task_locations_id'] . '/' . $file['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
                // Delete the file from the database
                $stmt = $conn->prepare("DELETE FROM task_location_photos WHERE url = :url");
                $stmt->execute([':url' => $url]);

                return createResponse(200, "A kép sikeresen törölve lett.", ['url' => $url, 'taskLocationsId' => $file['task_locations_id']]);
            }
        } else {
            return createResponse(404, "A fájl nem található az adatbázisban.");
        }
    } catch (Exception $e) {
        return createResponse(400, "Hiba történt: " . $e->getMessage());
    }
}

function getTofShopId($url)
{
    try {
        //API hívás és eredményének feldolgozása
        $response = file_get_contents($url);
        $data = json_decode($response, true);
        //$data['points'] körbejárása és az ID értékek kigyűjtése
        $tofShopIds = [];
        foreach ($data['points'] as $point) {
            $tofShopIds[] = $point['id'];
        }
        return createResponse(200, "success", $tofShopIds);
    } catch (Exception $e) {
        return createResponse(400, "Hiba történt: " . $e->getMessage());
    }
}

function getExoboxPoints($url, $user, $password, $id)
{
    if (!isset($id)) {
        try {
            //API hívás és eredményének feldolgozása
            $context = stream_context_create([
                'http' => [
                    'header' => "Authorization: Basic " . base64_encode("$user:$password")
                ]
            ]);
            $response = file_get_contents($url, false, $context);
            $data = json_decode($response, true);

            return $data;
        } catch (Exception $e) {
            return createResponse(400, "Hiba történt: " . $e->getMessage());
        }
    } else {
        try {
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            //echo json_encode($data);
            //visszadni az a $data['point_id']-t, ahol az $data['id'] = $id
            foreach ($data['points'] as $point) {
                if ($point['id'] == $id) {
                    //echo $point['point_id'];

                    return $point['point_id'];
                }
            }
            return createResponse(404, "Nem található a megadott ID-hoz tartozó pont.");
        } catch (Exception $e) {
            return createResponse(400, "Hiba történt: " . $e->getMessage());
        }
    }
}

function addIntervention($conn, $taskId, $newIntervention, $userId)
{
    try {
        // Tranzakció indítása
        $conn->beginTransaction();

        // Beavatkozás beszúró lekérdezés
        $taskLockersInterventionSql = "INSERT INTO task_lockers_interventions (task_id, uuid, performed_by, notes) VALUES (?,?,?,?)";
        $taskLockersInterventionStmt = $conn->prepare($taskLockersInterventionSql);

        // Beavatkozás és hiba összekapcsolása beszúró lekérdezés
        $interventionIssuesSql = "INSERT INTO intervention_issues (intervention_id, issue_id) VALUES (?, ?)";
        $interventionIssuesStmt = $conn->prepare($interventionIssuesSql);        

        // task_locker_intervention_parts táblába beszúró lekérdezés
        $taskLockerInterventionPartsSql = "INSERT INTO task_locker_intervention_parts (intervention_id, part_id, quantity) VALUES (?, ?, ?)";
        $taskLockerInterventionPartsStmt = $conn->prepare($taskLockerInterventionPartsSql);

        foreach ($newIntervention as $intervention) {

            // Beavatkozás beszúrása
            $taskLockersInterventionStmt->execute([$taskId, $intervention['uuid'], $userId, $intervention['notes']]);
            $interventionId = $conn->lastInsertId();

            // Beavatkozás és hiba összekapcsolása
            foreach ($intervention['issues'] as $issue) {
                $interventionIssuesStmt->execute([$interventionId, $issue['id']]);
            }

            // Alkatrész felhasználása a beavatkozásban
            foreach ($intervention['parts'] as $part) {
                $taskLockerInterventionPartsStmt->execute([$interventionId, $part['id'], $part['quantity']]);
            }
        }

        // Tranzakció lezárása
        $conn->commit();
        return createResponse(200, "Sikeres betöltés");
    } catch (Exception $e) {
        // Hiba esetén rollback
        $conn->rollBack();
        return createResponse(400, $e->getMessage());
    }
}

function downloadNewPoints($data)
{
    try {
        $adatok = $data;

        // Excel generálása
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Fejléc
        $fejlec = ['tof_shop_id', 'box_id', 'status_exohu', 'latitude', 'longitude', 'location_photos', 'delivery_date', 'lockerApproach'];
        $sheet->fromArray($fejlec, NULL, 'A1');

        $startRow = 2;
        $currentRow = $startRow;

        foreach ($adatok as $sor) {
            // Ellenőrizzük, hogy van-e tömb típusú value
            $hasArray = false;
            $arrayCol = '';
            foreach ($fejlec as $col) {
                if (is_array($sor[$col] ?? null)) {
                    $hasArray = true;
                    $arrayCol = $col;
                    break;
                }
            }

            if ($hasArray && !empty($sor[$arrayCol])) {
                foreach ($sor[$arrayCol] as $arrayElem) {
                    $rowData = [];
                    foreach ($fejlec as $col) {
                        if ($col === $arrayCol) {
                            // Ha a tömbelem asszociatív tömb és van benne 'url', csak azt írd ki
                            if (is_array($arrayElem) && isset($arrayElem['url'])) {
                                $rowData[] = $arrayElem['url'];
                            }else {
                                $rowData[] = is_array($arrayElem) ? json_encode($arrayElem, JSON_UNESCAPED_UNICODE) : $arrayElem;
                            }
                        } else {
                            $value = $sor[$col] ?? '';
                            if (is_array($value)) {
                                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                            }
                            $rowData[] = $value;
                        }
                    }
                    $sheet->fromArray($rowData, NULL, 'A' . $currentRow);

                    $cell = $sheet->getCell('F' . $currentRow);
                    $cell->getHyperlink()->setUrl($rowData[5]);

                    $currentRow++;
                }
            } else {
                // Nincs tömb, sima sor
                $rowData = [];
                foreach ($fejlec as $col) {
                    $value = $sor[$col] ?? '';
                    if (is_array($value)) {
                        // Ha asszociatív tömb és van benne 'url', csak azt írd ki
                        if (isset($value['url'])) {
                            $value = $value['url'];
                        } else {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                        }
                    }
                    $rowData[] = $value;
                }
                $sheet->fromArray($rowData, NULL, 'A' . $currentRow);
                $currentRow++;
            }
        }

        // Ideiglenes fájl létrehozása
        $temp_file = tempnam(sys_get_temp_dir(), 'excel');
        $temp_file_with_ext = $temp_file . '.xlsx';
        rename($temp_file, $temp_file_with_ext);

        $writer = new Xlsx($spreadsheet);
        $writer->save($temp_file_with_ext);

        // Fájl letöltése
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="newpoints.xlsx"');
        header('Cache-Control: max-age=0');
        readfile($temp_file_with_ext);

        // Fájl törlése
        unlink($temp_file_with_ext);
        exit();
    } catch (Throwable $e) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'status' => 400,
            'message' => $e->getMessage()
        ]);
        exit();
    }
}
