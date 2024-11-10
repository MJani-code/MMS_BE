<?php
//require ('../../inc/conn.php');

// $dataToHandleInDb = array();

// $dataToHandleInDb = [
//     'table' => "polls_questions",
//     'method' => "get",
//     'columns' => ['question_id'],
//     'values' => [],
//     'others' => [],
//     'conditions' => []
// ];

function dataToHandleInDb($conn, $dataToHandleInDb)
{
    $columnsFormatted = '';
    $valuesFormatted = '';
    $table = $dataToHandleInDb['table'];
    $method = $dataToHandleInDb['method'];

    foreach ($dataToHandleInDb['columns'] as $key => $column) {
        $lastColumnKey = array_key_last($dataToHandleInDb['columns']);
        $columnsFormatted .= $column;
        if ($lastColumnKey != $key) {
            $columnsFormatted .= ",";
        }
    }
    foreach ($dataToHandleInDb['columns'] as $key => $column) {
        $lastValueKey = array_key_last($dataToHandleInDb['columns']);
        $valuesFormatted .= ":" . $column;
        if ($lastValueKey != $key) {
            $valuesFormatted .= ",";
        }
    }

    switch ($method) {
        case 'insert':
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO " . $table . "
                        (" . $columnsFormatted . ")
                        VALUES (" . $valuesFormatted . ");
                    "
                );
                foreach ($dataToHandleInDb['values'] as $key => $value) {
                    $column = ":" . $dataToHandleInDb['columns'][$key];
                    $stmt->bindValue($column, $value);
                }
                if ($stmt->execute()) {
                    $response = array(
                        "isInserted" => 1,
                        "message" => "Data inserted successfully."
                    );
                } else {
                    $response = array(
                        "isInserted" => 0,
                        "message" => "Data insertion failed."
                    );
                }
                return $response;
            } catch (Exception $e) {
                $response = array(
                    "isInserted" => 0,
                    "message" => "Hiba történt a művelet során: " . $e->getMessage()
                );
                return $response;
            }
        case 'get':
            $conditions = $dataToHandleInDb['conditions'] ?? '';
            $others = $dataToHandleInDb['others'] ?? '';
            $order = $dataToHandleInDb['order'] ?? '';
            $cte = $dataToHandleInDb['cte'] ?? '';

            //****÷A feltételeket már nem tömbben, hanem STRING-ként kell megadni *****/

            // $conditionString = implode(" AND ", array_map(function ($col) {
            //     return "$col = :cond_" . str_replace(".", "_", $col);
            // }, array_keys($conditions)));

            $conditionString = $conditions;

            $conditionExtra = $dataToHandleInDb['conditionExtra'] ?? "";

            try {
                $query = "";
                if (!empty($cte)) {
                    $query = "WITH $cte";
                }
                $query .= "SELECT $columnsFormatted FROM $table";
                if (!empty($others)) {
                    $query .= " $others";
                }
                if (!empty($conditionString)) {
                    $query .= " WHERE $conditionString";
                }
                if (!empty($conditionExtra)) {
                    $query .= " AND $conditionExtra";
                }
                if (!empty($order)) {
                    $query .= " $order";
                }
                $stmt = $conn->prepare($query);
                // foreach ($conditions as $col => $value) {
                //     $paramName = ":cond_" . str_replace(".", "_", $col);
                //     $stmt->bindValue($paramName, $value);
                // }

                $stmt->execute();
                // echo $query;
                $payload = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($stmt->execute()) {
                    $results['status'] = 200;
                    $results['message'] = 'success';
                    $results['payload'] = $payload;
                } else {
                    $errorInfo = $stmt->errorInfo();
                    $results['status'] = 500;
                    $results['message'] = 'Hiba történt a lekérdezés végrehajtása során.';
                    $results['errorInfo'] = $errorInfo;
                }
                return $results;
            } catch (Exception $e) {
                $errorInfo = $e->getMessage();
                $results['status'] = 500;
                $results['message'] = 'Hiba történt a lekérdezés végrehajtása során';
                $results['errorInfo'] = $errorInfo;
                return $results;
            }

        case 'update':
            $columns = $dataToHandleInDb['columns'];
            $values = $dataToHandleInDb['values'];
            $conditions = $dataToHandleInDb['conditions'];

            $setString = implode(", ", array_map(function ($col) {
                return "$col = :set_" . str_replace(".", "_", $col);
            }, $columns));

            $conditionString = implode(" AND ", array_map(function ($col) {
                return "$col = :cond_" . str_replace(".", "_", $col);
            }, array_keys($conditions)));

            try {
                $stmt = $conn->prepare(
                    "UPDATE $table SET $setString WHERE $conditionString"
                );

                foreach ($columns as $key => $column) {
                    $paramName = ":set_" . str_replace(".", "_", $column);
                    $stmt->bindValue($paramName, $values[$key]);
                }

                foreach ($conditions as $col => $value) {
                    $paramName = ":cond_" . str_replace(".", "_", $col);
                    $stmt->bindValue($paramName, $value);
                }
                if ($stmt->execute()) {
                    $response = array(
                        "status" => 200,
                        "isUpdated" => 1,
                        "message" => "Data update successful."
                    );
                } else {
                    $response = array(
                        "status" => 400,
                        "isUpdated" => 0,
                        "error" => "Data update failed."
                    );
                }
                return $response;
            } catch (Exception $e) {
                $response = array(
                    "isUpdated" => 0,
                    "error" => "Hiba történt a művelet során: " . $e->getMessage()
                );
                return $response;
            }
        case 'delete':
            $conditions = $dataToHandleInDb['conditions'];
            $conditionString = implode(" AND ", array_map(function ($col) {
                return "$col = :cond_" . str_replace(".", "_", $col);
            }, array_keys($conditions)));

            try {
                $stmt = $conn->prepare(
                    "DELETE FROM $table WHERE $conditionString"
                );

                foreach ($conditions as $col => $value) {
                    $paramName = ":cond_" . str_replace(".", "_", $col);
                    $stmt->bindValue($paramName, $value);
                }
                $stmt->execute();
                $response = array(
                    "isDeleted" => 1,
                    "message" => "Data deleted successfully."
                );
                return $response;
            } catch (Exception $e) {
                $error = "Hiba történt a művelet során: " . $e->getMessage();
                echo json_encode($error);
            }
            break;
    }
}

//dataToHandleInDb($conn, $dataToHandleInDb);