<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$lockerData = json_decode($jsonData, true);

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
        $stmt = $this->conn->prepare("INSERT INTO api_tokens (token) VALUES (:token)");
        $stmt->execute([':token' => $token]);
    }

    public function getLockerDataFunction($lockerData)
    {
        //locker adatok lekérdezése
        try {
            $token = $this->token;
            $page = $lockerData['page'];
            $pageSize = $lockerData['pageSize'];
            $url = $this->losGetLockerStationsForPortalUrl;
            $data = array('Countrycode' => 'HU', 'Filter' => null, 'IsActive' => true, 'PageNumber' => $page, 'PageSize' => $pageSize);
            $options = array(
                'http' => array(
                    'header'  => "Content-type: application/json\r\n" .
                        "Authorization: Bearer " . $token . "\r\n",
                    'method'  => 'POST',
                    'content' => json_encode($data)
                )
            );
            $context  = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            if ($result === false) {
                $headers = $http_response_header;
                foreach ($headers as $header) {
                    if (strpos($header, 'HTTP/1.1 401') !== false) {
                        $loginResult = $this->login();
                        return $this->getLockerDataFunction($lockerData);
                    }
                }
            } else {
                $result = json_decode($result, true);
                $this->response = $result;
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
            $options = array(
                'http' => array(
                    'header'  => "Content-type: application/json\r\n",
                    'method'  => 'POST',
                    'content' => json_encode($data)
                )
            );
            $context  = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
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
