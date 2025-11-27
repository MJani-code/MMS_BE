<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class StockMasterData
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

    public function getMasterData()
    {
        // User validation (use same permission id as stock list)
        $isAccess = $this->auth->authenticate(28);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $companyId = $isAccess['data']->companyId;
            if (in_array(32, $isAccess['data']->permissions)) {
                $canAddForAllOwners = true;
            } else {
                $canAddForAllOwners = false;
            }
        }

        try {
            // part categories
            $stmt = $this->conn->prepare("SELECT id, name FROM part_categories ORDER BY name");
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // warehouses
            $stmt = $this->conn->prepare("SELECT id, name FROM warehouses ORDER BY name");
            $stmt->execute();
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // suppliers
            $stmt = $this->conn->prepare("SELECT id, name FROM suppliers ORDER BY name");
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            //manufacturers
            $stmt = $this->conn->prepare("SELECT id, name FROM manufacturers ORDER BY name");
            $stmt->execute();
            $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            //currency
            $stmt = $this->conn->prepare("SELECT id, currency FROM part_supplier GROUP BY currency ORDER BY currency");
            $stmt->execute();
            $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            //companies
            if ($canAddForAllOwners) {
                $stmt = $this->conn->prepare("SELECT id, name FROM companies ORDER BY name");
            } else {
                $stmt = $this->conn->prepare("SELECT id, name FROM companies WHERE id = :companyId ORDER BY name");
                $stmt->bindValue(':companyId', $companyId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $payload = [
                'categories' => $categories ?: [],
                'warehouses' => $warehouses ?: [],
                'suppliers' => $suppliers ?: [],
                'manufacturers' => $manufacturers ?: [],
                'currencies' => $currencies ?: [],
                'companies' => $companies ?: [],
            ];


            return $this->response = $this->createResponse(200, "Master data fetched", $payload);
        } catch (Exception $e) {
            return $this->response = $this->createResponse(500, $e->getMessage(), null);
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? null;

$auth = new Auth($conn, $token, $secretkey);

$svc = new StockMasterData($conn, $response, $auth);
$svc->getMasterData();

echo json_encode($response);
