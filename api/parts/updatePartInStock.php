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

    private function permissionCheck($data)
    {
        // implement permission check logic here
        $isAccess = $this->auth->authenticate(33);
        if ($isAccess['status'] !== 200) {
            return $isAccess;
        }

        $userId = $isAccess['data']->userId;
        if (in_array(34, $isAccess['data']->permissions)) {
            // can update parts for any owner
            $ownerId = $data['ownerId'] ?? null;
        } else {
            // can only update parts for own company
            $ownerId = $isAccess['data']->companyId;
        }

        return [
            'status' => 200,
            'userId' => $userId,
            'ownerId' => $ownerId,
        ];
    }

    private function validationError($data, $partToUpdate, $stockToUpdate, $partSupplierToUpdate)
    {

        if (empty($data) || empty($data['part']['partId'])) {
            return $this->response = $this->createResponse(400, 'Hiányzó adatok a frissítéshez');
        }
        try {
            #1 parts tábla frissítés lehetőségének ellenőrzése          
            if ($partToUpdate) {
                //mandatory fields ellenőrzése
                if (empty($data['part']['partName']) || empty($data['part']['partNumber']) || empty($data['part']['categoryId']) || empty($data['part']['manufacturerId'])) {
                    return $this->response = $this->createResponse(400, 'Hiányzó kötelező mezők a parts frissítéséhez');
                }
                // létezik-e az alkatrész
                $partId = (int)$data['part']['partId'];

                $stmt = $this->conn->prepare("SELECT id, part_number FROM parts WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
                $stmt->execute();
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    return $this->response = $this->createResponse(404, 'Alkatrész nem található');
                }
            }
            #2 stock tábla frissítés lehetőségének ellenőrzése
            if ($stockToUpdate) {
                //mandatory fields ellenőrzése
                if (empty($data['stockId']) || empty($data['ownerId']) || empty($data['warehouseId']) || empty($data['supplierId'])) {
                    return $this->response = $this->createResponse(400, 'Hiányzó kötelező mezők a stock frissítéséhez');
                }
                // létezik-e a készlet
                if (empty($data['stockId'])) {
                    return $this->response = $this->createResponse(400, 'Hiányzó készlet azonosító a stock frissítéséhez');
                }
                //mennyiségi változás esetén ellenőrizni, hogy az összes mező megvan-e
                if ($data['quantityDifference'] !== 0) {
                    if (empty($data['part']['partId']) || empty($data['unitPrice']) || empty($data['currency']) || empty($data['reference']) || empty($data['note'])) {
                        return $this->response = $this->createResponse(400, 'Hiányzó kötelező mezők a mennyiség változtatásához');
                    }
                }

                $stockId = (int)$data['stockId'];
                $stmt = $this->conn->prepare("SELECT id FROM stock WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', $stockId, PDO::PARAM_INT);
                $stmt->execute();
                $existingStock = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingStock) {
                    return $this->response = $this->createResponse(404, 'Készlet nem található');
                }
            }
            #3 part_supplier tábla frissítés lehetőségének ellenőrzése
            if ($partSupplierToUpdate) {
                //mandatory fields ellenőrzése
                if (empty($data['supplierId']) || empty($data['part']['partId'])) {
                    return $this->response = $this->createResponse(400, 'Hiányzó kötelező mezők a part_supplier frissítéséhez');
                }
                // létezik-e a part_supplier rekord
                $partId = (int)$data['part']['partId'];
                $supplierId = (int)$data['supplierId'];
                $stmt = $this->conn->prepare("SELECT part_id, supplier_id FROM part_supplier WHERE part_id = :part_id AND supplier_id = :supplier_id LIMIT 1");
                $stmt->bindValue(':part_id', $partId, PDO::PARAM_INT);
                $stmt->bindValue(':supplier_id', $supplierId, PDO::PARAM_INT);
                $stmt->execute();
                $existingPartSupplier = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingPartSupplier) {
                    return $this->response = $this->createResponse(404, 'Ez a beszállító nem szállítja ezt az alkatrészt');
                }
            }
        } catch (Exception $e) {
            return $this->response = $this->createResponse(500, $e->getMessage());
        }
        return null;
    }

    public function updatePart($data)
    {
        #1 Persmission check
        $perm = $this->permissionCheck($data);
        if ($perm['status'] !== 200) {
            return $this->response = $perm;
        }

        #2 Validation
        $partToUpdate = false;
        $stockToUpdate = false;
        $partSupplierToUpdate = false;

        if ($data['part']['partNameChanged'] || $data['part']['partNumberChanged'] || $data['part']['categoryIdChanged'] || $data['part']['manufacturerIdChanged']) {
            $partToUpdate = true;
        }
        if ($data['ownerIdChanged'] || $data['warehouseIdChanged'] || $data['supplierIdChanged'] || $data['quantityDifference'] !== 0) {
            $stockToUpdate = true;
        }
        if ($data['unitPriceChanged'] || $data['currencyChanged'] || $data['supplierIdChanged']) {
            $partSupplierToUpdate = true;
        }

        $validationError = $this->validationError($data, $partToUpdate, $stockToUpdate, $partSupplierToUpdate);
        if ($validationError !== null) {
            return $validationError;
        }

        #3 Update
        try {
            $this->conn->beginTransaction();

            if ($partToUpdate) {
                // parts tábla frissítése
                $sql = "UPDATE parts
                        SET name = :name,
                            part_number = :part_number,
                            part_category_id = :part_category_id,
                            manufacturer_id = :manufacturer_id
                        WHERE id = :id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':name', $data['part']['partName'], PDO::PARAM_STR);
                $stmt->bindValue(':part_number', $data['part']['partNumber'], PDO::PARAM_STR);
                $stmt->bindValue(':part_category_id', (int)$data['part']['categoryId'], PDO::PARAM_INT);
                $stmt->bindValue(':manufacturer_id', (int)$data['part']['manufacturerId'], PDO::PARAM_INT);
                $stmt->bindValue(':id', (int)$data['part']['partId'], PDO::PARAM_INT);
                $stmt->execute();
            }
            if ($stockToUpdate) {
                // stock tábla frissítése
                $sqlStock = "UPDATE stock
                            SET owner_id = :owner_id,
                                warehouse_id = :warehouse_id,
                                supplier_id = :supplier_id
                            WHERE id = :id";
                $stmt = $this->conn->prepare($sqlStock);
                $stmt->bindValue(':owner_id', (int)$data['ownerId'], PDO::PARAM_INT);
                $stmt->bindValue(':warehouse_id', (int)$data['warehouseId'], PDO::PARAM_INT);
                $stmt->bindValue(':supplier_id', (int)$data['supplierId'], PDO::PARAM_INT);
                $stmt->bindValue(':id', (int)$data['stockId'], PDO::PARAM_INT);
                $stmt->execute();

                // stock_movements tábla beszúrás
                $reason = '';
                $qty = (int)$data['quantityDifference'];
                if ($qty < 0) {
                    $reason = 'OUT';
                } elseif ($qty === 0) {
                    $reason = 'ADJUSTMENT';
                } elseif ($qty > 0) {
                    $reason = 'IN';
                }
                $sqlStockMovements = "INSERT INTO stock_movements (part_id, owner_id, warehouse_id, supplier_id, change_amount, unit_price, currency, reason, reference, note, created_at, created_by)
                             VALUES (:part_id, :owner_id, :warehouse_id, :supplier_id, :change_amount, :unit_price, :currency, :reason, :reference, :note, NOW(), :created_by)";
                $stmt = $this->conn->prepare($sqlStockMovements);
                $stmt->bindValue(':part_id', (int)$data['part']['partId'], PDO::PARAM_INT);
                $stmt->bindValue(':owner_id', (int)$data['ownerId'], PDO::PARAM_INT);
                $stmt->bindValue(':warehouse_id', (int)$data['warehouseId'], PDO::PARAM_INT);
                $stmt->bindValue(':supplier_id', (int)$data['supplierId'], PDO::PARAM_INT);
                $stmt->bindValue(':change_amount', (int)$data['quantityDifference'], PDO::PARAM_INT);
                $stmt->bindValue(':unit_price', $data['unitPrice'], $data['unitPrice'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':currency', $data['currency'], $data['currency'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
                $stmt->bindValue(':reference', $data['reference'], PDO::PARAM_STR);
                $stmt->bindValue(':note', $data['note'], PDO::PARAM_STR);
                $stmt->bindValue(':created_by', (int)$perm['userId'], PDO::PARAM_INT);
                $stmt->execute();
            }
            if ($partSupplierToUpdate) {
                // part_supplier tábla frissítése
                $sql = "UPDATE part_supplier
                        SET price = :price,
                            currency = :currency
                        WHERE part_id = :part_id AND supplier_id = :supplier_id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':price', $data['unitPrice'], $data['unitPrice'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':currency', $data['currency'], $data['currency'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':part_id', (int)$data['part']['partId'], PDO::PARAM_INT);
                $stmt->bindValue(':supplier_id', (int)$data['supplierId'], PDO::PARAM_INT);
                $stmt->execute();
            }
            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollBack();
            return $this->response = $this->createResponse(500, 'Frissítés sikertelen: ' . $e->getMessage());
        }
        return $this->response = $this->createResponse(200, 'Frissítés sikeres', $data);
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? null;

$auth = new Auth($conn, $token, $secretkey);

$updater = new UpdatePart($conn, $response, $auth);
$updater->updatePart($updateItem);

echo json_encode($response);
