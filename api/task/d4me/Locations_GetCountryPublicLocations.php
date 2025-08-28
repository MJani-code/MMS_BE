<?php
header('Content-Type: application/json');
require('../../../inc/conn.php');
require_once('../../../vendor/autoload.php');
require(DOC_ROOT . '/api/user/auth/auth.php');

//error debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$log = new Logger('Locations_GetCountryPublicLocations');
$log->pushHandler(new RotatingFileHandler('logs/Locations_GetCountryPublicLocations.log', 5));

$response = [];

class getLocations
{
    private $conn;
    private $auth;
    private $response;
    private $tokenD4Me;
    private $d4meApiUrl;
    private $log;

    public function __construct($conn, &$response, $auth, $tokenD4Me, $d4meApiUrl, $log)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->tokenD4Me = $tokenD4Me;
        $this->d4meApiUrl = $d4meApiUrl;
        $this->log = $log;
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'data' => $data
        ];
    }

    private function callApi($url, $token)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        $response = curl_exec($ch);

        if ($response === false || curl_errno($ch)) {
            $error = curl_error($ch);
            $this->log->error('cURL error', ['error' => $error]);
            curl_close($ch);
            return ['error' => $error];
        }
        curl_close($ch);
        return ['response' => $response];
    }

    public function getItems()
    {
        // Get user ID from authentication
        $userId = null;
        $isAccess = $this->auth->authenticate(4);
        if ($isAccess['status'] !== 200) {
            $this->log->error('Authentication failed', ['userId' => $userId]);
            return $this->response = $isAccess;
        } else {
            $this->log->info('Authentication successful', ['userId' => $isAccess['data']->userId]);
            $userId = $isAccess['data']->userId;
        }

        //First API call to get dataCount
        $apiResponse = $this->callApi($this->d4meApiUrl, $this->tokenD4Me);

        if (isset($apiResponse['error'])) {
            $this->log->error('API call error', ['error' => $apiResponse['error']]);
            return $this->response = $this->createResponse(500, "API call error: " . $apiResponse['error']);
        }

        $data = json_decode($apiResponse['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log->error('JSON decode error', ['error' => json_last_error_msg()]);
            return $this->response = $this->createResponse(500, "JSON decode error: " . json_last_error_msg());
        }

        // Get dataCount value
        $dataCount = $data['pagination']['dataCount'] ?? 0;
        $this->log->info('Data count retrieved', ['dataCount' => $dataCount]);


        //Second API url with pageSize set to dataCount
        $urlWithPageSize = $this->d4meApiUrl . '&pageSize=' . $dataCount;
        $apiResponse = $this->callApi($urlWithPageSize, $this->tokenD4Me);

        if (isset($apiResponse['error'])) {
            $this->log->error('API call error', ['error' => $apiResponse['error']]);
            return $this->response = $this->createResponse(500, "API call error: " . $apiResponse['error']);
        }

        $data = json_decode($apiResponse['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log->error('JSON decode error', ['error' => json_last_error_msg()]);
            return $this->response = $this->createResponse(500, "JSON decode error: " . json_last_error_msg());
        }

        //logging the number of locations retrieved
        $this->log->info('Locations retrieved', ['count' => count($data['data'])]);
        return $this->response = $this->createResponse(200, "Locations retrieved successfully", $data['data']);
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$items = new getLocations($conn, $response, $auth, $tokenD4Me, $d4meApiUrl, $log);
$items->getItems();

echo json_encode($response);
