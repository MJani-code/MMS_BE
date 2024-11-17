<?php
require('../../inc/secretkey.php');
require('../../vendor/autoload.php');

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class Auth
{
    private $conn;
    private $token;
    private $secretKey;
    private $userAuthData = [];
    private $permissionName;

    public function __construct($conn, $token, $secretkey, $permissionName)
    {
        $this->conn = $conn;
        $this->token = $token;
        $this->secretKey = $secretkey;
        $this->permissionName = $permissionName;
    }
    private function createResponse($status, $message, $data = null)
    {
        return ([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ]);
    }
    public function authenticate()
    {
        // Token validation
        if ($this->token) {
            try {
                $decoded = JWT::decode($this->token, new Key($this->secretKey, 'HS256'));
                $roleId = $decoded->roleId;
                //Is Token expired
                if ($decoded->expirationTime < time()) {
                    return $this->createResponse(401, 'A token lejárt, kérjük jelentkezz be újra.', $decoded);
                }
                // SQL lekérdezés, amely a szerepkör összes jogosultságát visszaadja
                $query = "
                        SELECT p.name
                        FROM Role_permissions rp
                        LEFT JOIN Permissions p on p.id = rp.permission_id
                        WHERE rp.role_id = :roleId;";
                $stmt = $this->conn->prepare($query);
                $stmt->execute(['roleId' => $roleId]);
                $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // return in_array($permissionName, $permissions);
                $isAccesGranted = in_array($this->permissionName, $permissions);
                if (!$isAccesGranted) {
                    return $this->createResponse(403, 'Nincs hozzáférésed a kért művelethez');
                }
            } catch (Exception $e) {
                return $this->createResponse(401, $e, $decoded);
            }
            return $this->createResponse(200, 'success', $decoded);
            // return $this->userAuthData[] = $decoded;
        }
    }
}