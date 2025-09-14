<?php
header("Access-Control-Allow-Origin: http://192.168.76.68:3000"); // Változtasd meg a frontend URL-t, ha szükséges
header("Access-Control-Allow-Methods: *"); // Engedélyezett HTTP metódusok (pl. POST)
header("Access-Control-Allow-Headers: *"); // Engedélyezett fejlécek
header("Content-Type: application/json"); // Példa: JSON válasz küldése

require('../inc/conn.php');
require('../functions/db/dbFunctions.php');
require('../inc/secretkey.php');
require('../vendor/autoload.php');

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;


class AuthHandler
{
    private $conn;
    private $secretKey;

    public function __construct($conn, $secretkey)
    {
        $this->conn = $conn;
        $this->secretKey = $secretkey;
    }

    private function createResponse($status, $message, $data = null)
    {
        return json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function handleAuth()
    {
        $jsonData = file_get_contents("php://input");
        $data = json_decode($jsonData, true);

        $token = $data['token'] ?? NULL;
        $urlTo = $data['urlTo'] ?? NULL;

        //Jött Token?
        if (!$token) {
            echo $this->createResponse(400, 'Nincs token!');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
                // Ellenőrizzük a token lejárati idejét
                if ($decoded->expirationTime < time()) {
                    // A token lejárt
                    echo $this->createResponse(401, 'A token lejárt, kérjük jelentkezz be újra.');
                    return;
                } else {
                    // A token még érvényes. Leellenőrizzük, hogy a token létezik-e az adatbázisban
                    $query = "SELECT *
                    FROM user_login                    
                    WHERE token = :token";
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute(['token' => $token]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$user) {
                        echo $this->createResponse(400, 'Hiányzik a token.');
                    } else {
                        //urlTo kikérdezése adatbázisból
                        $userId = $user['user_id'];
                        $query = "SELECT rr.is_visible, rr.role_id
                          FROM role_routes rr
                          LEFT JOIN users u ON rr.role_id = u.role_id
                          JOIN routes r ON rr.route_id = r.id
                          WHERE r.path = :urlTo AND u.id = :userId";
                        $stmt = $this->conn->prepare($query);
                        $stmt->execute(['urlTo' => $urlTo, 'userId' => $userId]);
                        $isPathAccessible = $stmt->fetchColumn();

                        if (!$isPathAccessible) {
                            http_response_code(404);
                            echo $this->createResponse(404, 'Hozzáférés megtagadva', ['urlTo' => '/admin/tasks', 'title' => 'Megbízások']);
                            return;
                        }

                        echo $this->createResponse(200, 'Érvényes token.', $user);
                    }
                }
            } catch (Exception $e) {
                echo $this->createResponse(400, 'Hibás token. ' . $e);
            }
        }
    }
}

$authHandler = new AuthHandler($conn, $secretkey);
$authHandler->handleAuth();
