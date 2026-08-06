<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../functions/analyzeLockers.php');
require('../../api/user/auth/auth.php');
require(__DIR__ . '/helpers/getAvailableCompartmentsCache.php');


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$response = [];

const GAC_CACHE_TTL_SECONDS = 30;
const GAC_STALE_WHILE_REVALIDATE_SECONDS = 60;
const GAC_RATE_LIMIT_WINDOW_SECONDS = 60;
const GAC_RATE_LIMIT_MAX_REQUESTS = 90;

$jsonData = file_get_contents("php://input");
$lockerData = json_decode($jsonData, true);

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$tokenMMS = $matches[1] ?? '';

$requestIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$requestSignature = sha1('getAvailableCompartments|' . $tokenMMS . '|' . (string)$jsonData);
$rateLimitKey = sha1('gac_rate|' . $tokenMMS . '|' . $requestIp);
$cacheFilePath = gacCacheFilePath($requestSignature);

$cached = gacLoadCachedResponse($cacheFilePath);
if ($cached && $cached['age'] <= GAC_CACHE_TTL_SECONDS) {
    gacEmitResponse($cached['response'], GAC_CACHE_TTL_SECONDS, GAC_STALE_WHILE_REVALIDATE_SECONDS, $cached['age'], 'HIT');
}

$rateLimitState = gacIsRateLimited($rateLimitKey, GAC_RATE_LIMIT_WINDOW_SECONDS, GAC_RATE_LIMIT_MAX_REQUESTS);
if ($rateLimitState['limited']) {
    if ($cached && $cached['age'] <= (GAC_CACHE_TTL_SECONDS + GAC_STALE_WHILE_REVALIDATE_SECONDS)) {
        gacEmitResponse($cached['response'], GAC_CACHE_TTL_SECONDS, GAC_STALE_WHILE_REVALIDATE_SECONDS, $cached['age'], 'STALE-RATELIMIT');
    }

    header('Retry-After: ' . (int)$rateLimitState['retryAfter']);
    gacEmitResponse(
        gacCreateResponse(429, 'Too many requests. Please retry later.'),
        GAC_CACHE_TTL_SECONDS,
        GAC_STALE_WHILE_REVALIDATE_SECONDS,
        0,
        'MISS-RATELIMIT'
    );
}

class getAvailableCompartments
{
    private $conn;
    private $losUserName;
    private $losPassword;
    private $losLoginUrl;
    private $losGetLockerStationsForPortalUrl;
    private $response;
    private $auth;
    private $token;
    private $getAllActivePointsUrl;
    private $d4meUtilizationUrl;
    private $tokenD4Me;
    private $user;
    private $password;

    public function __construct($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, &$response, $auth, $getAllActivePointsUrl, $user, $password, $tokenD4Me, $d4meUtilizationUrl = null)
    {
        $this->tokenD4Me = $tokenD4Me;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
        $this->conn = $conn;
        $this->losUserName = $losUserName;
        $this->losPassword = $losPassword;
        $this->losLoginUrl = $losLoginUrl;
        $this->losGetLockerStationsForPortalUrl = $losGetLockerStationsForPortalUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->token = $this->getTokenFromDatabase();
        $this->d4meUtilizationUrl = $d4meUtilizationUrl;
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
        try {
            /*
            LOS http request to get locker data            
            */

            $token = $this->token;
            $pageSize = $lockerData['pageSize'];
            $url = $this->losGetLockerStationsForPortalUrl;
            $LockerStationHistoryModel = [];

            $data = array(
                'Countrycode' => 'HU',
                'Filter' => null,
                'LockerStationHistoryModel' => $LockerStationHistoryModel,
                'maxResultCount' => $pageSize,
                'skipCount' => 0
            );

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            $losData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode == 401) {
                $this->login();
                return $this->getLockerDataFunction($lockerData);
            }

            if ($losData === false) {
                return $this->response = $this->createResponse(400, 'Failed to get locker data: ' . curl_error($ch));
            }

            curl_close($ch);

            /*
            get d4meLocationIds
            */
            $d4meLocationIds = [];
            $exoboxPointsRawData = getExoboxPoints($this->getAllActivePointsUrl, $this->user, $this->password, null);
            // echo json_encode($exoboxPointsRawData);
            foreach ($exoboxPointsRawData['payload'] as $point) {
                if (
                    isset($point['manufacturer']) &&
                    $point['manufacturer'] === 'd4me' &&
                    $point['status'] == 1
                ) {
                    $d4meLocationIds[] = $point['point_id'];
                }
            }
            /*
            short the d4meLocationIds array to the first 10 elements
            */
            //$d4meLocationIds = array_slice($d4meLocationIds, 0, 10);
            // echo json_encode($d4meLocationIds);

            /*
            d4me http request to get locker data
            */
            $d4meUrl = $this->d4meUtilizationUrl;

            $d4meData['data'] = [];
            foreach ($d4meLocationIds as $locationId) {
                $url = str_replace(':locationId', $locationId, $d4meUrl);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->tokenD4Me
                ]);
                $d4meResponse = curl_exec($ch);
                $d4meResponse = json_decode($d4meResponse, true);
                //$d4meData tömbbe hozzáadjuk a d4meResponse data tömbjét, ha van benne data kulcs
                if (isset($d4meResponse['data'])) {
                    $d4meData['data'] = array_merge($d4meData['data'], $d4meResponse['data']);
                }
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }

            $data = json_decode($losData, true);
            if (!is_array($data)) {
                $data = [];
            }

            // Optional d4me payload can be passed in request body and is merged by analyzer.
            if (isset($d4meData) && !empty($d4meData)) {
                $data['data'] = $d4meData['data'];
            }

            $availableData = getAvailableCompartmentCountByLocation($data);

            return $this->response = $this->createResponse(200, 'Locker data retrieved successfully', $availableData);
        } catch (Exception $e) {
            return $this->response = $this->createResponse(400, $e->getMessage());
        }
    }

    public function login()
    {
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

            if ($result === false) {
                return $this->createResponse(400, 'Login failed: ' . curl_error($ch));
            }

            curl_close($ch);
            $result = json_decode($result, true);

            if (isset($result['payload']['token'])) {
                $this->token = $result['payload']['token'];
                $this->storeTokenInDatabase($this->token);
                return $this->createResponse(200, 'Login successful', $result);
            }

            return $this->createResponse(400, 'Login failed');
        } catch (Exception $e) {
            return $this->createResponse(400, $e->getMessage());
        }
    }
}
$auth = new Auth($conn, $tokenMMS, $secretkey);

$getAvailableCompartments = new getAvailableCompartments($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, $response, $auth, $getAllActivePointsUrl, $user, $password, $tokenD4Me, $d4meUtilizationUrl);
$getAvailableCompartments->getLockerDataFunction($lockerData);

if (is_array($response) && (($response['status'] ?? null) === 200)) {
    gacWriteJsonFile($cacheFilePath, [
        'createdAt' => time(),
        'response' => $response
    ]);
}

gacEmitResponse($response, GAC_CACHE_TTL_SECONDS, GAC_STALE_WHILE_REVALIDATE_SECONDS, 0, 'MISS');
