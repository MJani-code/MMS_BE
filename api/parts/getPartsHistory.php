<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class PartHistory
{
    private $conn;
    private $response;
    private $auth;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    private function createResponse($status, $message, $payload = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
        ];
    }

    public function getHistory($data)
    {
        // Permission check (use same permission as stock list)
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        }

        $partId = isset($data['partId']) ? (int)$data['partId'] : 0;
        if ($partId <= 0) {
            return $this->response = $this->createResponse(400, 'Hiányzó vagy érvénytelen partId');
        }

        // optional filters
        $warehouseId = isset($data['warehouseId']) ? (int)$data['warehouseId'] : null;
        $from = isset($data['from']) ? $data['from'] : null; // expect 'YYYY-MM-DD' or full datetime
        $to = isset($data['to']) ? $data['to'] : null;
        $limit = isset($data['limit']) ? (int)$data['limit'] : 100;
        if ($limit <= 0) $limit = 100;

        try {
            $sql = "
                SELECT sm.id,
                       tli.task_id AS taskId,                       
                       GROUP_CONCAT(DISTINCT ttd.name SEPARATOR ',') AS taskTypes,
                       GROUP_CONCAT(DISTINCT ttd.color SEPARATOR ',') AS taskTypesColors,
                       tlo.tof_shop_id AS tofShopId,
                       tlo.box_id AS boxId,
                       tl.serial AS serial,
                       sm.part_id AS partId,
                       p.part_number AS partNumber,
                       p.name AS partName,
                       sm.warehouse_id AS warehouseId,
                       w.name AS warehouseName,
                       sm.supplier_id AS supplierId,
                       s.name AS supplierName,
                       sm.change_amount AS changeAmount,
                       sm.unit_price AS unitPrice,
                       sm.currency AS currency,
                       sm.reason AS reason,
                       sm.reference AS reference,
                       sm.note AS note,
                       sm.created_at AS createdAt,
                       sm.created_by AS createdBy,
                       CONCAT(UPPER(LEFT(u.last_name, 1)), UPPER(LEFT(u.first_name, 1))) AS createdByName
                FROM stock_movements sm
                LEFT JOIN parts p ON p.id = sm.part_id
                LEFT JOIN warehouses w ON w.id = sm.warehouse_id
                LEFT JOIN suppliers s ON s.id = sm.supplier_id
                LEFT JOIN users u ON u.id = sm.created_by
                LEFT JOIN task_locker_intervention_parts tlip ON tlip.id = sm.task_locker_intervention_parts_id
                LEFT JOIN task_lockers_interventions tli ON tli.id = tlip.intervention_id
                LEFT JOIN task_lockers tl ON tl.task_id = tli.task_id
                LEFT JOIN tasks t ON t.id = tli.task_id
                LEFT JOIN task_locations tlo ON tlo.id = t.task_locations_id
                LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
                LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
                WHERE sm.part_id = :part_id
                GROUP BY sm.id
            ";

            $params = [':part_id' => $partId];

            if ($warehouseId !== null) {
                $sql .= " AND sm.warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $warehouseId;
            }
            if ($from) {
                $sql .= " AND sm.created_at >= :from";
                $params[':from'] = $from;
            }
            if ($to) {
                $sql .= " AND sm.created_at <= :to";
                $params[':to'] = $to;
            }

            $sql .= " ORDER BY sm.created_at DESC LIMIT :limit";
            $stmt = $this->conn->prepare($sql);

            // bind values (PDO doesn't allow binding LIMIT as string on some drivers, use bindValue with explicit type)
            foreach ($params as $k => $v) {
                if (is_int($v)) {
                    $stmt->bindValue($k, $v, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($k, $v, PDO::PARAM_STR);
                }
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert comma-separated taskTypes and into object of strings
            //Add taskTypesColors to the array as well
            foreach ($rows as &$r) {
                $typeNames = explode(',', $r['taskTypes']);
                $typeColors = explode(',', $r['taskTypesColors']);
                $types = [];

                if (count($typeNames) > 0 && $typeNames[0] !== '') {
                    for ($i = 0; $i < count($typeNames); $i++) {
                        $types[] = [
                            'name' => $typeNames[$i],
                            'color' => $typeColors[$i] ?? null
                        ];
                    }
                    $r['taskTypes'] = $types;
                } else {
                    $r['taskTypes'] = [];
                }
                unset($r['taskTypesColors']);
            }
            unset($r);

            return $this->response = $this->createResponse(200, 'Part history fetched', $rows);
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error (getHistory): " . $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? null;

$auth = new Auth($conn, $token, $secretkey);

$svc = new PartHistory($conn, $response, $auth);
$svc->getHistory($payload);

echo json_encode($response);
