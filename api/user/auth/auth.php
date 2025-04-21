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
    private $permissionId;

    public function __construct($conn, $token, $secretkey)
    {
        $this->conn = $conn;
        $this->token = $token;
        $this->secretKey = $secretkey;
    }
    private function createResponse($status, $message, $data = null)
    {
        return ([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ]);
    }
    public function authenticate($permissionId)
    {
        // Token validation
        if ($this->token) {
            try {
                $decoded = JWT::decode($this->token, new Key($this->secretKey, 'HS256'));
                $roleId = $decoded->roleId;
                $companyId = $decoded->companyId;
                //Is Token expired
                if ($decoded->expirationTime < time()) {
                    return $this->createResponse(401, 'A token lejárt, kérjük jelentkezz be újra.', $decoded);
                }
                // SQL lekérdezés, amely a szerepkör összes jogosultságát visszaadja
                $query = "
                        SELECT p.id
                        FROM role_permissions rp
                        LEFT JOIN permissions p on p.id = rp.permission_id
                        WHERE rp.role_id = :roleId;";
                $stmt = $this->conn->prepare($query);
                $stmt->execute(['roleId' => $roleId]);
                $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                //decodedhoz hozzáadni a jogosultságokat
                $decoded->permissions = $permissions;
                $isAccesGranted = in_array($permissionId, $permissions);
                if (!$isAccesGranted) {
                    return $this->createResponse(403, 'Nincs hozzáférésed a kért művelethez', $decoded);
                }
            } catch (Exception $e) {
                return $this->createResponse(401, $e->getMessage(), $decoded);
            }
            return $this->createResponse(200, 'success', $decoded);
        }
    }
    public function isTheTaskVisibleForUser($taskId, $locationId, $companyId, $permissions)
    {
        //Ha a $permissions tömbb tartalmazza a 17-es jogosultságot, akkor visszatérünk true-val
        if (in_array(17, $permissions)) {
            return $this->createResponse(200, 'success', null);
        }
        //Ha a $permissions tömbb nem tartalmazza a 17-es jogosultságot, akkor a companyId-t validáljuk, hogy hozzáférhet-e az adott taskhoz/locationhoz

        if ($taskId) {
            try {
                $query = "SELECT tr.id FROM task_responsibles tr
                WHERE tr.task_id = :taskId AND tr.company_id = :companyId AND tr.deleted = 0";
                $stmt = $this->conn->prepare($query);
                $stmt->execute(['taskId' => $taskId, 'companyId' => $companyId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    return $this->createResponse(200, 'success', null);
                } else {
                    
                    return $this->createResponse(403, 'Nincs hozzáférésed ehhez az elemhez', null);
                }
            } catch (\Throwable $th) {
                return $this->createResponse(403, $th->getMessage(), null);
            }
        } else{
            return $this->createResponse(400, 'Hiányzó task id adat', null);
        }
        //Ha locationId értékünk van, a hozzá tartozó taskId-t előbb le kell kérdezni a task táblából, majd a taskId alapján a responsibles táblában ellenőrizni
        if ($locationId) {
            try {
                $query = "SELECT t.id FROM tasks t
                LEFT JOIN task_locations tl on tl.id = t.task_locations_id
                WHERE tl.id = :locationId";
                $stmt = $this->conn->prepare($query);
                $stmt->execute(['locationId' => $locationId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $taskId = $result['id'];
                    return $this->isTheTaskVisibleForUser($taskId, null, $companyId, $permissions);
                } else {
                    return $this->createResponse(403, 'Nincs hozzáférésed ehhez az elemhez', null);
                }
            } catch (\Throwable $th) {
                return $this->createResponse(403, $th->getMessage(), null);
            }
        }
    }
}
