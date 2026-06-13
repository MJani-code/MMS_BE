<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$lockerData = json_decode($jsonData, true);
$locale = $lockerData['locale'] ?? 'hu';

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
    private $locale;

    public function __construct($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, &$response, $auth, $locale = 'hu')
    {
        $this->conn = $conn;
        $this->losUserName = $losUserName;
        $this->losPassword = $losPassword;
        $this->losLoginUrl = $losLoginUrl;
        $this->losGetLockerStationsForPortalUrl = $losGetLockerStationsForPortalUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->token = $this->getTokenFromDatabase();
        $this->locale = $locale;
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
        $stmt = $this->conn->prepare("INSERT INTO api_tokens (token) VALUES (:token)");
        $stmt->execute([':token' => $token]);
    }

    public function getLockerDataFunction($lockerData, $locale = 'hu')
    {
        //locker adatok lekérdezése
        try {
            $token = $this->token;
            $page = $lockerData['pageNumber'];
            $pageSize = $lockerData['pageSize'];
            $url = $this->losGetLockerStationsForPortalUrl;
            $LockerStationHistoryModel = [];

            $data = array('Countrycode' => 'HU', 'Filter' => null, 'LockerStationHistoryModel' => $LockerStationHistoryModel, 'maxResultCount' => $pageSize, 'skipCount' => 0);

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
                return $this->response = createLocalizedErrorResponse(400, 'errors.locker_data_fetch_failed', $this->locale, ['message' => curl_error($ch)]);
            }

            curl_close($ch);
            $result = json_decode($result, true);
            //A result list items nevű tömb átnevezése resultList-re
            $result['payload']['resultList'] = $result['payload']['items'];
            unset($result['payload']['items']);

            $this->response = $result;
        } catch (Exception $e) {
            return $this->response = createLocalizedErrorResponse(400, 'errors.unexpected', $this->locale, ['message' => $e->getMessage()]);
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
                return $this->response = createLocalizedErrorResponse(400, 'errors.login_failed_with_reason', $this->locale, ['message' => curl_error($ch)]);
            }

            curl_close($ch);
            $result = json_decode($result, true);

            if (isset($result['payload']['token'])) {
                $this->token = $result['payload']['token'];
                $this->storeTokenInDatabase($this->token);
                return $this->response = createLocalizedResponse(200, 'success.login_successful', $result, $this->locale);
            } else {
                return $this->response = createLocalizedErrorResponse(400, 'errors.login_failed_simple', $this->locale);
            }
        } catch (Exception $e) {
            return $this->response = createLocalizedErrorResponse(400, 'errors.unexpected', $this->locale, ['message' => $e->getMessage()]);
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
