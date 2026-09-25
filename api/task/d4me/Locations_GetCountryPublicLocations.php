<?php
require_once __DIR__ . '/../../../inc/conn.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../api/user/auth/auth.php';


use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

class getLocations
{
    private $conn;
    private $auth;
    private $response;
    private $tokenD4Me;
    private $d4meApiGetPhotoUrl;
    private $log;
    private $searchTerm;

    public function __construct($conn, &$response, $auth, $tokenD4Me, $d4meApiGetPhotoUrl, $log, $searchTerm)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->tokenD4Me = $tokenD4Me;
        $this->d4meApiGetPhotoUrl = $d4meApiGetPhotoUrl;
        $this->log = $log;
        $this->searchTerm = $searchTerm;
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'data' => $data
        ];
    }

    private function callApi($url, $token, $searchTerm = '')
    {
        if (!empty($searchTerm)) {
            $url .= '&SearchTerm=' . urlencode($searchTerm) . '&SearchFields[]=id';
        }
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

    public function getItems($skipAuth = false)
    {
        if (!$skipAuth) {
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
        }

        //First API call to get dataCount
        //$apiResponse = $this->callApi($this->d4meApiGetPhotoUrl, $this->tokenD4Me);

        // $data = json_decode($apiResponse['response'], true);
        // if (json_last_error() !== JSON_ERROR_NONE) {
        //     $this->log->error('JSON decode error', ['error' => json_last_error_msg()]);
        //     return $this->response = $this->createResponse(500, "JSON decode error: " . json_last_error_msg());
        // }

        // Get dataCount value
        // $dataCount = $data['pagination']['dataCount'] ?? 0;
        // $this->log->info('Data count retrieved', ['dataCount' => $dataCount]);


        //Second API url with pageSize set to dataCount
        // $urlWithPageSize = $this->d4meApiGetPhotoUrl . '&pageSize=' . $dataCount;
        $apiResponse = $this->callApi($this->d4meApiGetPhotoUrl, $this->tokenD4Me, $this->searchTerm);

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

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json');

    $log = new Logger('Locations_GetCountryPublicLocations');
    $log->pushHandler(new RotatingFileHandler(__DIR__ . '/logs/Locations_GetCountryPublicLocations.log', 5));

    $response = [];
    $tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
    $token = $matches[1] ?? null;
    $searchTerm = $_GET['boxId'] ?? '';

    if ($token === null || $token === '') {
        echo json_encode([
            'status' => 401,
            'message' => 'Missing or invalid authorization token',
            'data' => null,
        ]);
        exit;
    }

    $auth = new Auth($conn, $token, $secretkey);
    $items = new getLocations($conn, $response, $auth, $tokenD4Me, $d4meApiGetPhotoUrl, $log, $searchTerm);
    $result = $items->getItems();

    echo json_encode($result);
}
