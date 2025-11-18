<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class StockItems
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
    public function getStockItems()
    {
        //User validation here
        $isAccess = $this->auth->authenticate(14);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        //Data gathering
        try {
            $stmt = "SELECT
                    s.id as stockId,
                    p.id AS partId,
                    p.part_number AS partNumber,
                    p.name AS partName,
                    pc.name AS category,
                    ifnull(s.quantity, 0) AS quantity,
                    ifnull(bs.quantity, 0) AS badQuantity,
                    w.id AS warehouseId,
                    w.name AS warehouseName,
                    sup.id AS supplierId,
                    sup.name AS supplier,
                    m.name AS manufacturerName,
                    ps.price AS unitPrice,
                    p.min_stock AS minStock,
                    ps.currency AS currency
                    FROM stock s
                    LEFT JOIN parts p ON s.part_id = p.id
                    LEFT JOIN warehouses w ON w.id = s.warehouse_id
                    LEFT JOIN part_categories pc ON pc.id = p.part_category_id
                    LEFT JOIN part_supplier ps ON ps.part_id = p.id AND ps.supplier_id = s.supplier_id
                    LEFT JOIN suppliers sup ON sup.id = ps.supplier_id
                    LEFT JOIN manufacturers m ON m.id = p.manufacturer_id
                    LEFT JOIN bad_stock bs ON bs.part_id = s.part_id AND bs.warehouse_id = s.warehouse_id AND bs.supplier_id = s.supplier_id
                    ORDER BY p.id, w.name;";
            $query = $this->conn->prepare($stmt);
            $query->execute();
            $stockItems = $query->fetchAll(PDO::FETCH_ASSOC);

            if ($stockItems === false) {
                return $this->response = $this->createResponse(400, "Error fetching stock items.");
            } else {
                return $this->response = $this->createResponse(200, "Stock items fetched successfully.", $stockItems);
            }
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'payload' => NULL
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$getData = new StockItems($conn, $response, $auth);
$getData->getStockItems();

echo json_encode($response);
