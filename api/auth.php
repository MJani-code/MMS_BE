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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

        //Jött Token?
        if (!$token) {
            echo $this->createResponse(400, 'Nincs token!');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
                if ($decoded->expirationTime < time()) {
                    // A token lejárt
                    echo $this->createResponse(401, 'A token lejárt, kérjük jelentkezz be újra.');
                    return;
                } else {
                    // A token még érvényes
                    echo $this->createResponse(200, 'Érvényes token.');
                }
            } catch (Exception $e) {
                echo $this->createResponse(400, 'Hibás token. ' . $e);
            }
        }
    }
}

$authHandler = new AuthHandler($conn, $secretkey);
$authHandler->handleAuth();
