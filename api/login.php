<?php
header('Content-Type: application/json');

// header("Access-Control-Allow-Origin: http://192.168.76.68:3000");
// header("Access-Control-Allow-Methods: *");
// header("Access-Control-Allow-Headers: *");
// header("Content-Type: application/json");

require('../inc/conn.php');
require('../inc/secretkey.php');
require('../vendor/autoload.php');
require('../functions/db/dbFunctions.php');

use \Firebase\JWT\JWT;
use Firebase\JWT\Key;

class LoginHandler
{
    private $secretKey;
    private $conn;

    public function __construct($conn, $secretkey)
    {
        $this->secretKey = $secretkey;
        $this->conn = $conn;
    }

    private function createResponse($status, $message, $data = null)
    {
        return json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function handleLogin()
    {
        $jsonData = file_get_contents("php://input");
        $data = json_decode($jsonData, true);

        $email = $data['email'];
        $password = $data['password'];

        if (!$email || !$password) {
            echo $this->createResponse(400, 'Hibás felhasználónév vagy jelszó');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $stmt = $this->conn->prepare(
                    "SELECT
                        u.id, u.role_id, u.first_name, u.last_name, u.email, u.password, GROUP_CONCAT(p.id) as permissions
                    FROM users u
                    LEFT JOIN roles r ON r.id = u.role_id
                    LEFT JOIN role_permissions rp on rp.role_id = u.role_id
                    LEFT JOIN permissions p on p.id = rp.permission_id
                    WHERE email = :email AND u.deleted = 0"
                );
                $stmt->bindParam(":email", $email);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result && password_verify($password, $result['password'])) {
                    $userId = $result['id'];
                    $roleId = $result['role_id'];
                    $firstName = $result['first_name'];

                    // JWT Token generálása
                    $currentTimestamp = time();
                    $expirationTimestamp = strtotime('+8 hours', $currentTimestamp);
                    $payload = [
                        'email' => $email,
                        'expirationTime' => $expirationTimestamp,
                        'userId' => $userId,
                        'roleId' => $roleId
                    ];
                    $jwt = JWT::encode($payload, $this->secretKey, 'HS256');

                    // Token adatok adatbázisba mentése
                    $stmt = $this->conn->prepare(
                        "INSERT INTO user_login
                        (user_id, token, token_created_date, token_expire_date)
                        VALUES (:user_id, :token, :token_created_date, :token_expire_date)"
                    );
                    $stmt->bindParam(":user_id", $userId);
                    $stmt->bindParam(":token", $jwt);
                    $stmt->bindParam(":token_created_date", date('Y-m-d H:i:s', $currentTimestamp));
                    $stmt->bindParam(":token_expire_date", date('Y-m-d H:i:s', $expirationTimestamp));
                    $stmt->execute();


                    // Sikeres válasz
                    echo $this->createResponse(200, 'success', [
                        "token" => $jwt,
                        "userId" => intval($userId),
                        "roleId" => $roleId,
                        "firstName" => $firstName,
                        "isLoggedIn" => true,
                        "email" => $email,
                        "xtg" => explode(',', $result['permissions'])
                    ]);
                } else {
                    // Hibás bejelentkezés válasza
                    echo $this->createResponse(400, 'Hibás felhasználónév vagy jelszó');
                }
            } catch (Exception $e) {
                // Kivétel esetén hibaüzenet visszaadása
                echo $this->createResponse(500, 'Hiba történt a bejelentkezés közben: ' . $e->getMessage());
            }
        }
    }
}

$loginHandler = new LoginHandler($conn, $secretkey);
$loginHandler->handleLogin();
