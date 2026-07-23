<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$lockerData = json_decode($jsonData, true);
//Eltávolítani az isActive mezőt a lockerData tömbből
unset($lockerData['is_active']);

class CheckLocker
{
    private $conn;
    private $losUserName;
    private $losPassword;
    private $losLoginUrl;
    private $losGetLockerStationsForPortalUrl;
    private $response;
    private $auth;
    private $token;

    public function __construct($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, &$response, $auth)
    {
        $this->conn = $conn;
        $this->losUserName = $losUserName;
        $this->losPassword = $losPassword;
        $this->losLoginUrl = $losLoginUrl;
        $this->losGetLockerStationsForPortalUrl = $losGetLockerStationsForPortalUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->token = $this->getTokenFromDatabase();
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'payload' => $data
        ];
    }

    private function getTokenFromDatabase()
    {
        $stmt = $this->conn->prepare("SELECT token FROM api_tokens ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['token'] : null;
    }

    private function storeTokenInDatabase($token)
    {
        $stmt = $this->conn->prepare("INSERT INTO api_tokens (token, api) VALUES (:token, :api)");
        $stmt->execute([':token' => $token , ':api' => 'LOS']);
    }

    public function getLockerDataFunction($lockerData)
    {
        //locker adatok lekérdezése
        try {
            $token = $this->token;
            $url = $this->losGetLockerStationsForPortalUrl;
            $LockerStationHistoryModel = [array('LockerStationFilterType' => 'Uuid', 'Filter' => $lockerData['serial'])];
            
            $data = array('Countrycode' => 'HU', 'Filter' => null, 'LockerStationHistoryModel' => $LockerStationHistoryModel, 'maxResultCount' => 10, 'skipCount' => 0);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode == 401) {
                $loginResult = $this->login();
                return $this->getLockerDataFunction($lockerData);
            }

            if ($result === false) {
                return $this->response = $this->createResponse(400, 'Failed to get locker data: ' . curl_error($ch));
            }

            curl_close($ch);
            $result = json_decode($result, true);

            if (!isset($result['payload']['items'][0])) {
                return $this->response = createResponse(404, 'Nem található ilyen szériaszámú csomagautomata');
            }

            $isLockerAdded = isset($result['payload']['items'][0]['lockerStationId']) ? 1 : 0;
            $isActive = $result['payload']['items'][0]['lockerList'][0]['isPassive'] ? 0 : 1;
            $privateKey1Error = $result['payload']['items'][0]['lockerList'][0]['privateKey1Error'] ? 1 : 0;
            $batteryLevel = $result['payload']['items'][0]['lockerList'][0]['batteryLevel'];
            $currentVersion = $result['payload']['items'][0]['lockerList'][0]['currentVersion'];
            $lastConnectionTimestamp = $result['payload']['items'][0]['lockerList'][0]['lastConnectionTimestamp'];

            $arrayToStoreResult = array(
                'id' => $lockerData['id'],
                'is_registered' => $isLockerAdded,
                'is_active' => $isActive,
                'privateKey1Error' => $privateKey1Error,
                'batteryLevel' => $batteryLevel,
                'currentVersion' => $currentVersion,
                'lastConnectionTimestamp' => $lastConnectionTimestamp
            );
            //Get user data
            $userId = null;
            $isAccess = $this->auth->authenticate(4);
            if ($isAccess['status'] !== 200) {
                return $this->response = $isAccess;
            } else {
                $userId = $isAccess['data']->userId;
            }
            $result = updateCheckLockerResult($this->conn, $arrayToStoreResult, $userId);
            if ($result['status'] == 200) {
                //put lockerData and arrayToStoreResult into one array
                $lockerData = array_merge($lockerData, $arrayToStoreResult);
                $this->response = $this->createResponse(200, 'Sikeres lekérdezés', $lockerData);
            }
        } catch (Exception $e) {
            return $this->response = $this->createResponse(400, $e->getMessage());
        }
    }

    public function login()
    {
        //bejelentkezés
        try {
            $url = $this->losLoginUrl;
            $data = array('username' => $this->losUserName, 'password' => $this->losPassword);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($result === false) {
                return $this->createResponse(400, 'Login failed: ' . curl_error($ch));
            }

            curl_close($ch);
            $result = json_decode($result, true);

            if (isset($result['payload']['token'])) {
                $this->token = $result['payload']['token'];
                $this->storeTokenInDatabase($this->token);
                return $this->createResponse(200, 'Login successful', $result);
            } else {
                return $this->createResponse(400, 'Login failed');
            }
        } catch (Exception $e) {
            return $this->createResponse(400, $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$tokenMMS = $matches[1];

$auth = new Auth($conn, $tokenMMS, $secretkey);

$checkLocker = new CheckLocker($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, $response, $auth);
$checkLocker->getLockerDataFunction($lockerData);

echo json_encode($response);
