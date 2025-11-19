<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

$jsonData = file_get_contents("php://input");
$newItem = json_decode($jsonData, true);

class AddItemToStock
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

    public function insertPart($newItem)
    {
        //User validation here
        $userId = null;
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        //Data insertion
        try {
            //ellenőrizzük, hogy van-e $newItem
            if (empty($newItem)) {
                $this->response = $this->createResponse(400, 'Nincs adat a kérésben!', null);
                return;
            }

            //ellenőrizzük a kötelező elemeket
            $requiredFields = ['partNumber', 'partName', 'categoryId', 'supplierId', 'manufacturerId', 'warehouseId', 'quantity', 'reference'];
            foreach ($requiredFields as $field) {
                if (empty($newItem[$field])) {
                    return $this->response = $this->createResponse(400, "Hiányzó mezők: $field");
                }
            }

            //ellenőrizzük, hogy van-e már ilyen part number készleten
            $sqlCheck = "SELECT id FROM stock WHERE part_id = :part_id AND supplier_id = :supplier_id AND warehouse_id = :warehouse_id";
            $stmtCheck = $this->conn->prepare($sqlCheck);
            $stmtCheck->bindValue(':part_id', $newItem['partId'], PDO::PARAM_INT);
            $stmtCheck->bindValue(':supplier_id', $newItem['supplierId']['id'], PDO::PARAM_INT);
            $stmtCheck->bindValue(':warehouse_id', $newItem['warehouseId']['id'], PDO::PARAM_INT);
            $stmtCheck->execute();

            if ($stmtCheck->fetch()) {
                if ($newItem['quantityDifference'] < 0) {
                    return $this->response = $this->createResponse(400, 'Ez a készlet már létezik. Készlethiány esetén a "Készlet szerkesztése" funkciót használd!');
                }
                if ($newItem['quantityDifference'] >= 0) {
                    $sqlStock = "INSERT INTO stock_movements (part_id, warehouse_id, supplier_id, change_amount, unit_price, currency, reason, reference, note, created_at, created_by) VALUES (:part_id, :warehouse_id, :supplier_id, :change_amount, :unit_price, :currency, :reason, :reference, :note, NOW(), :created_by)
                         ON DUPLICATE KEY UPDATE change_amount = change_amount + VALUES(change_amount), unit_price = VALUES(unit_price), currency = VALUES(currency), reason = VALUES(reason) , reference = VALUES(reference), note = VALUES(note) , created_at = NOW(), created_by = VALUES(created_by)";
                    $stmt = $this->conn->prepare($sqlStock);
                    $stmt->execute([':part_id' => $newItem['partId'], ':warehouse_id' => $newItem['warehouseId']['id'], ':supplier_id' => $newItem['supplierId']['id'], ':change_amount' => $newItem['quantityDifference'], ':unit_price' => $newItem['unitPrice'], ':currency' => $newItem['currency'], ':reason' => 'IN', ':reference' => $newItem['reference'], ':note' => $newItem['note'], ':created_by' => $userId]);
                    return $this->response = $this->createResponse(200, 'Alkatrész készlet frissítve', ['partId' => (int)$newItem['partId'], 'partNumber' => $newItem['partNumber']]);
                }
            }

            $this->conn->beginTransaction();

            //1) parts            
            $sqlPart = "INSERT INTO parts (part_number, name, part_category_id, manufacturer_id, created_at, created_by) VALUES (:part_number, :name, :category_id, :manufacturer_id, NOW(), :created_by)";
            $stmt = $this->conn->prepare($sqlPart);
            $stmt->bindValue(':part_number', $newItem['partNumber'], PDO::PARAM_STR);
            $stmt->bindValue(':name', $newItem['partName'], PDO::PARAM_STR);
            $stmt->bindValue(':category_id', $newItem['categoryId'], $newItem['categoryId'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':manufacturer_id', $newItem['manufacturerId'], $newItem['manufacturerId'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $partId = $this->conn->lastInsertId();

            // 2) part_supplier (ha van supplier)
            if ($newItem['supplierId'] !== null) {
                $sqlSupplier = "INSERT INTO part_supplier (part_id, supplier_id, price, currency) VALUES (:part_id, :supplier_id, :price, :currency)
                         ON DUPLICATE KEY UPDATE price = VALUES(price), currency = VALUES(currency)";
                $stmt = $this->conn->prepare($sqlSupplier);
                $stmt->execute([':part_id' => $partId, ':supplier_id' => $newItem['supplierId'], ':price' => $newItem['unitPrice'], ':currency' => $newItem['currency']]);
            }

            // 3) stock (ha van warehouse és mennyiség)
            if ($newItem['warehouseId'] !== null) {
                $sqlStock = "INSERT INTO stock_movements (part_id, warehouse_id, supplier_id, change_amount, unit_price, currency, reason, reference, note, created_at, created_by) VALUES (:part_id, :warehouse_id, :supplier_id, :change_amount, :unit_price, :currency, :reason, :reference, :note, NOW(), :created_by)
                         ON DUPLICATE KEY UPDATE change_amount = change_amount + VALUES(change_amount), unit_price = VALUES(unit_price), currency = VALUES(currency), reason = VALUES(reason) , reference = VALUES(reference), note = VALUES(note) , created_at = NOW(), created_by = VALUES(created_by)";
                $stmt = $this->conn->prepare($sqlStock);
                $stmt->execute([':part_id' => $partId, ':warehouse_id' => $newItem['warehouseId'], ':supplier_id' => $newItem['supplierId'], ':change_amount' => $newItem['quantity'], ':unit_price' => $newItem['unitPrice'], ':currency' => $newItem['currency'], ':reason' => 'IN', ':reference' => $newItem['reference'], ':note' => $newItem['note'], ':created_by' => $userId]);
            }

            $this->conn->commit();
            return $this->response = $this->createResponse(200, 'Alkatrész hozzáadva', ['partId' => (int)$partId, 'partNumber' => $newItem['partNumber']]);
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            return $this->response = $this->createResponse(500, $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$addItem = new AddItemToStock($conn, $response, $auth);
$addItem->insertPart($newItem);

echo json_encode($response);
