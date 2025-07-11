<?php
header('Content-Type: application/json');
require('../../../inc/conn.php');
require(DOC_ROOT . '/api/user/auth/auth.php');

//debug error
error_reporting(E_ALL);
ini_set('display_errors', 1);


$response = [];

class getLocations
{
    private $conn;
    private $auth;
    private $response;
    private $tokenD4Me;
    private $d4meApiUrl;

    public function __construct($conn, &$response, $auth, $tokenD4Me, $d4meApiUrl)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->tokenD4Me = $tokenD4Me;
        $this->d4meApiUrl = $d4meApiUrl;
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'data' => $data
        ];
    }

    public function getItems()
    {
        $userId = null;
        $isAccess = $this->auth->authenticate(4);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        try {
            $url = $this->d4meApiUrl;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization: Bearer ' . $this->tokenD4Me));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            $response = curl_exec($ch);
            if ($response === false) {
                // cURL hiba
                return $this->response = $this->createResponse(500, "cURL error: " . curl_error($ch));
            }

            if (curl_errno($ch)) {
                return $this->response = $this->createResponse(500, "cURL error: " . curl_error($ch));
            }
            curl_close($ch);
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->response = $this->createResponse(500, "JSON decode error: " . json_last_error_msg());
            }
            return $this->response = $this->createResponse(200, "Locations retrieved successfully", $data['data']);
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error: " . $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$items = new getLocations($conn, $response, $auth, $tokenD4Me, $d4meApiUrl);
$items->getItems();

echo json_encode($response);