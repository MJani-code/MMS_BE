<?php
require_once __DIR__ . '/../../../functions/taskFunctions.php';
require_once __DIR__ . '/../../../inc/conn.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../api/user/auth/auth.php';

header('Content-Type: application/json');

$response = [];

class getLockerCondition
{
    private $conn;
    private $auth;
    private $response;
    private $tokenD4Me;
    private $d4meLockerCondition;
    private $lockerData;

    public function __construct($conn, &$response, $auth, $tokenD4Me, $d4meLockerCondition, $lockerData)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->tokenD4Me = $tokenD4Me;
        $this->d4meLockerCondition = $d4meLockerCondition;
        $this->lockerData = $lockerData ?? null;
    }

    private function createResponse($status, $message, $data = null)
    {
        return json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function callApi($url, $token, $locationId)
    {
        if (!empty($locationId)) {
            $url .= '/' . urlencode($locationId) . '/availability';
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
            curl_close($ch);
            return ['error' => $error];
        }
        curl_close($ch);
        return ['response' => $response];
    }

    private function extractSerialFromValue($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $parts = explode('-', $value);
        $tail = end($parts);

        if (!is_string($tail) || $tail === '') {
            return '';
        }

        if (preg_match('/^([A-Za-z]*)(\d+)$/', $tail, $matches)) {
            $prefix = $matches[1];
            $digits = ltrim($matches[2], '0');
            return $prefix . ($digits === '' ? '0' : $digits);
        }

        return preg_replace('/0+/', '', $tail);
    }

    public function getItems($locale)
    {
        // Get user ID from authentication
        $userId = null;
        $isAccess = $this->auth->authenticate(4);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        $apiResponse = $this->callApi($this->d4meLockerCondition, $this->tokenD4Me, $this->lockerData['boxId'] ?? null);
        
        if (isset($apiResponse['error'])) {
            return $this->response = $this->createResponse(500, localizeErrorMessage('errors.apiCallFailed', $locale) . $apiResponse['error']);
        }

        $decodedResponse = json_decode($apiResponse['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->response = $this->createResponse(500, localizeErrorMessage('errors.jsonDecodeError', $locale) . json_last_error_msg());
        }

        $serial = $this->extractSerialFromValue($this->lockerData['serial'] ?? '');
        if ($serial !== '' && isset($decodedResponse['data']) && is_array($decodedResponse['data'])) {
            $matchedItem = null;
            foreach ($decodedResponse['data'] as $item) {
                if (($item['terminalId'] ?? null) === $serial) {
                    $matchedItem = $item;
                    break;
                }
            }

            if ($matchedItem !== null) {
                $decodedResponse['data'] = [$matchedItem];
            }
        }

        //transform data
        $isLockerAdded = $decodedResponse['data'][0]['isEnabled'] ? 1 : 0;
        $isActive = $decodedResponse['data'][0]['availability'] > 0 ? 1 : 0;
        $privateKey1Error = 0;
        $batteryLevel = $decodedResponse['data'][0]['batteryState'];
        switch ($batteryLevel) {
            case '1':
                $batteryLevel = 100;
                break;
            case '2':
                $batteryLevel = 20;
                break;
            case '3':
                $batteryLevel = 5;
                break;
            default:
                $batteryLevel = 0;
                break;
        }
        $currentVersion = $decodedResponse['data'][0]['firmwareVersion'];
        $lastConnectionTimestampRaw = $decodedResponse['data'][0]['lastDelivery']['authorisedFrom'] ?? null;
        $lastConnectionTimestamp = $lastConnectionTimestampRaw ? strtotime($lastConnectionTimestampRaw) : null;
        if ($lastConnectionTimestamp === false) {
            $lastConnectionTimestamp = null;
        }

        $arrayToStoreResult = array(
            'id' => $this->lockerData['id'],
            'is_registered' => $isLockerAdded,
            'is_active' => $isActive,
            'privateKey1Error' => $privateKey1Error,
            'batteryLevel' => $batteryLevel,
            'currentVersion' => $currentVersion,
            'lastConnectionTimestamp' => $lastConnectionTimestamp
        );

        $result = array_merge($this->lockerData, $arrayToStoreResult);

        // echo json_encode($result);
        return $this->response = $this->createResponse(200, localizeSuccessMessage('success.success', $locale), $result);
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$jsonData = file_get_contents("php://input");
$lockerData = json_decode($jsonData, true);

$items = new getLockerCondition($conn, $response, $auth, $tokenD4Me, $d4meLockerCondition, $lockerData);
$items->getItems($lockerData['locale'] ?? 'hu');

echo $response;
