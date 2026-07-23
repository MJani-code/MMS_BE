<?php
header("Content-Type: application/json");

require('../inc/conn.php');
//require('../functions/db/dbFunctions.php');
require('../inc/secretkey.php');
require('../vendor/autoload.php');
require('../functions/taskFunctions.php');

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

    public function handleAuth($locale = 'en')
    {
        $jsonData = file_get_contents("php://input");
        $data = json_decode($jsonData, true);

        $token = $data['token'] ?? NULL;
        $urlTo = $data['urlTo'] ?? NULL;

        //Jött Token?
        if (!$token) {
            echo createLocalizedErrorResponse(400, 'no_token', $locale);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
                // Ellenőrizzük a token lejárati idejét
                if ($decoded->expirationTime < time()) {
                    // A token lejárt
                    echo json_encode(createLocalizedErrorResponse(401, 'errors.token_expired', $locale));
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
                        echo json_encode(createLocalizedErrorResponse(401, 'errors.invalid_token', $locale));
                        return;
                    } else {
                        //urlTo kikérdezése adatbázisból
                        // Normalizáljuk az urlTo-t: ha nem csak '/', akkor távolítsuk el a végéről a '/' jelet
                        if ($urlTo !== '/' && substr($urlTo, -1) === '/') {
                            $urlTo = rtrim($urlTo, '/');
                        }

                        $userId = $user['user_id'];
                        $query = "SELECT rr.is_visible, rr.role_id
                          FROM role_routes rr
                          LEFT JOIN users u ON rr.role_id = u.role_id
                          JOIN routes r ON rr.route_id = r.id
                          WHERE :urlTo LIKE CONCAT(r.path, '%') AND u.id = :userId";
                        $stmt = $this->conn->prepare($query);
                        $stmt->execute(['urlTo' => $urlTo, 'userId' => $userId]);
                        $isPathAccessible = $stmt->fetchColumn();

                        if (!$isPathAccessible) {
                            http_response_code(404);
                            echo json_encode(createLocalizedErrorResponse(404, 'errors.access_denied', $locale, ['urlTo' => '/admin/tasks', 'title' => 'Megbízások']));
                            return;
                        }

                        echo json_encode(createLocalizedErrorResponse(200, 'success.valid_token', $locale));
                    }
                }
            } catch (Exception $e) {
                echo json_encode(createLocalizedErrorResponse(400, 'errors.invalid_token', $locale, ['error' => $e->getMessage()]));
            }
        }
    }
}

$authHandler = new AuthHandler($conn, $secretkey);
$authHandler->handleAuth();
