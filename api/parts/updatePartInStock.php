<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$updateItem = json_decode($jsonData, true);

class UpdatePart
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

    public function updatePart($data)
    {
        // permission check
        $userId = null;
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        if (empty($data) || empty($data['partId'])) {
            return $this->response = $this->createResponse(400, 'Hiányzó partId');
        }

        $partId = (int)$data['partId'];

        try {
            // létezik-e az alkatrész
            $stmt = $this->conn->prepare("SELECT id, part_number FROM parts WHERE id = :id LIMIT 1");
            $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $stmt->execute();
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return $this->response = $this->createResponse(404, 'Alkatrész nem található');
            }

            // ha partNumber változik, ellenőrizzük az ütközést
            if (isset($data['partNumber']) && $data['partNumber'] !== $existing['part_number']) {
                $stmt = $this->conn->prepare("SELECT id FROM parts WHERE part_number = :pn AND id != :id LIMIT 1");
                $stmt->execute([':pn' => $data['partNumber'], ':id' => $partId]);
                if ($stmt->fetchColumn()) {
                    return $this->response = $this->createResponse(409, 'Már létezik ilyen cikkszámú alkatrész');
                }
            }

            //ha a quantityDifference nem nulla, akkor a note és reference mezők kötelezőek
            if (isset($data['quantityDifference']) && $data['quantityDifference'] != 0) {
                if (empty($data['note']) || empty($data['reference'])) {
                    return $this->response = $this->createResponse(400, 'A mennyiség változtatásához meg kell adni a megjegyzést és a hivatkozást.');
                }
            }

            $this->conn->beginTransaction();

            // 1) update parts (dinamikusan csak a megadott mezők)
            $updateFields = [];
            $params = [':id' => $partId];
            if (isset($data['partNumber'])) {
                $updateFields[] = 'part_number = :part_number';
                $params[':part_number'] = $data['partNumber'];
            }
            if (isset($data['partName'])) {
                $updateFields[] = 'name = :name';
                $params[':name'] = $data['partName'];
            }
            if (array_key_exists('categoryId', $data)) {
                $updateFields[] = 'part_category_id = :category_id';
                $params[':category_id'] = $data['categoryId'] === null ? null : (int)$data['categoryId'];
            }
            // további mezők (pl. manufacturer_id) ha szükséges
            if (!empty($updateFields)) {
                $updateFields[] = 'updated_at = NOW()';
                $updateFields[] = 'updated_by = :updated_by';
                $updateFields[] = 'manufacturer_id = :manufacturer_id';
                $params[':manufacturer_id'] = isset($data['manufacturerId']) ? (int)$data['manufacturerId'] : null;
                $params[':updated_by'] = $userId;
                $sql = "UPDATE parts SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $stmt = $this->conn->prepare($sql);
                foreach ($params as $k => $v) {
                    if ($v === null) {
                        $stmt->bindValue($k, null, PDO::PARAM_NULL);
                    } elseif (is_int($v)) {
                        $stmt->bindValue($k, $v, PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue($k, $v, PDO::PARAM_STR);
                    }
                }
                $stmt->execute();
            }


            // 2) part_supplier upsert (ha supplierId vagy ár/currency meg van adva)
            if (isset($data['supplierId']) || isset($data['unitPrice']) || isset($data['currency'])) {
                if (empty($data['supplierId'])) {
                    // ha supplierId nincs, töröljük a kapcsolódó rekordot (opcionális)
                } else {
                    $supplierId = (int)$data['supplierId']['id'];
                    $unitPrice = $data['unitPrice'] ?? null;
                    $currency = $data['currency'] ?? null;
                    $sqlSupplier = "UPDATE part_supplier
                                    SET price = :price, currency = :currency 
                                    WHERE part_id = :part_id AND supplier_id = :supplier_id";
                    $stmt = $this->conn->prepare($sqlSupplier);
                    $stmt->bindValue(':part_id', $partId, PDO::PARAM_INT);
                    $stmt->bindValue(':supplier_id', $supplierId, PDO::PARAM_INT);
                    $stmt->bindValue(':price', $unitPrice, $unitPrice === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(':currency', $currency, $currency === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->execute();
                }
            }

            // 3) stock movement (ha quantity és warehouseId meg van adva) - quantity itt változás (positive/negative)
            if (isset($data['quantityDifference']) && isset($data['warehouseId'])) {
                $supplierId = (int)$data['supplierId']['id'];
                $changeAmount = (float)$data['quantityDifference'];
                $warehouseId = (int)$data['warehouseId']['id'];
                $unitPrice = $data['unitPrice'] ?? null;
                $currency = $data['currency'] ?? null;
                $reference = $data['reference'] ?? null;
                $note = $data['note'] ?? null;
                $sqlStock = "INSERT INTO stock_movements (part_id, warehouse_id, supplier_id, change_amount, unit_price, currency, reason, reference, note, created_at, created_by)
                             VALUES (:part_id, :warehouse_id, :supplier_id, :change_amount, :unit_price, :currency, :reason, :reference, :note, NOW(), :created_by)";
                $stmt = $this->conn->prepare($sqlStock);
                $stmt->bindValue(':part_id', $partId, PDO::PARAM_INT);
                $stmt->bindValue(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                $stmt->bindValue(':supplier_id', $supplierId, PDO::PARAM_INT);
                $stmt->bindValue(':change_amount', $changeAmount);
                $stmt->bindValue(':unit_price', $unitPrice, $unitPrice === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':currency', $currency, $currency === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':reason', $data['reason'] ?? 'ADJUSTMENT', PDO::PARAM_STR);
                $stmt->bindValue(':reference', $reference, $reference === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':note', $note, $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
                $stmt->execute();
            }

            $this->conn->commit();

            return $this->response = $this->createResponse(200, 'Alkatrész frissítve', ['partId' => $partId]);
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return $this->response = $this->createResponse(500, $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? null;

$auth = new Auth($conn, $token, $secretkey);

$updater = new UpdatePart($conn, $response, $auth);
$updater->updatePart($updateItem);

echo json_encode($response);
