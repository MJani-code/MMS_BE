<?php
header('Content-Type: application/json');

require_once(__DIR__ . '/../../vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('getAvailableCompartments');
$logger->pushHandler(new RotatingFileHandler(__DIR__ . '/logs/getAvailableCompartments.log', 5));

// Capture warnings/notices, uncaught exceptions and fatal errors (the sources of HTTP 500)
set_error_handler(function ($severity, $message, $file, $line) use ($logger) {
    $logger->warning('PHP error', ['severity' => $severity, 'message' => $message, 'file' => $file, 'line' => $line]);
    return false;
});
set_exception_handler(function ($e) use ($logger) {
    $logger->critical('Uncaught exception', [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['status' => 500, 'message' => 'Internal server error', 'payload' => null]);
});
register_shutdown_function(function () use ($logger) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        $logger->critical('Fatal error', $err);
    }
});
$logger->info('Request started', [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
]);

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../functions/analyzeLockers.php');
require('../../api/user/auth/auth.php');
require(__DIR__ . '/helpers/getAvailableCompartmentsCache.php');


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$response = [];

const GAC_CACHE_TTL_SECONDS = 1800;
const GAC_STALE_WHILE_REVALIDATE_SECONDS = 60;
const GAC_D4ME_CACHE_TTL_SECONDS = 1800;
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

    public function getLockerDataFunction($lockerData, $isRetry = false)
    {
        set_time_limit(120);
        try {
            /*
            LOS http request to get locker data            
            */

            $token = $this->token;
            $pageSize = $lockerData['pageSize'] ?? 1000;
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
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $losData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            global $logger;
            $logger->info('LOS locker request done', ['httpCode' => $httpCode, 'curlError' => $losData === false ? curl_error($ch) : null]);

            if ($httpCode == 401) {
                curl_close($ch);
                if ($isRetry) {
                    $logger->error('LOS returned 401 again after re-login');
                    return $this->response = $this->createResponse(401, 'LOS authentication failed');
                }
                $logger->warning('LOS returned 401, re-login');
                $this->login();
                return $this->getLockerDataFunction($lockerData, true);
            }

            if ($losData === false) {
                $error = curl_error($ch);
                curl_close($ch);
                return $this->response = $this->createResponse(400, 'Failed to get locker data: ' . $error);
            }

            curl_close($ch);

            /*
            get d4meLocationIds
            */
            $d4meLocationIds = [];
            $d4meCacheFile = gacCacheFilePath('d4me_' . sha1((string)$this->d4meUtilizationUrl));
            $d4meCached = gacLoadCachedResponse($d4meCacheFile);
            $d4meFailed = false;
            $d4meData = ['data' => []];

            if ($d4meCached && $d4meCached['age'] <= GAC_D4ME_CACHE_TTL_SECONDS) {
                $d4meData = $d4meCached['response'];
                $logger->info('d4me cache HIT', ['age' => $d4meCached['age']]);
            } else {
            $exoboxPointsRawData = getExoboxPoints($this->getAllActivePointsUrl, $this->user, $this->password, null);
            $logger->info('Exobox points fetched', [
                'status' => $exoboxPointsRawData['status'] ?? null,
                'count' => is_array($exoboxPointsRawData['payload'] ?? null) ? count($exoboxPointsRawData['payload']) : null
            ]);
            if (!is_array($exoboxPointsRawData['payload'] ?? null)) {
                $logger->error('Exobox points payload invalid', ['response' => $exoboxPointsRawData]);
                if (!$d4meCached) {
                    return $this->response = $this->createResponse(500, 'Failed to get exobox points');
                }
                $d4meFailed = true;
            }
            foreach (($d4meFailed ? [] : $exoboxPointsRawData['payload']) as $point) {
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
            //$logger->info('D4me location IDs limited to first 10', ['d4meLocationIds' => array_slice($d4meLocationIds, 0, 10)]);
            //$d4meLocationIds = array_slice($d4meLocationIds, 0, 10);
            // echo json_encode($d4meLocationIds);

            /*
            d4me http request to get locker data
            */
            $d4meUrl = $this->d4meUtilizationUrl;

            foreach ($d4meLocationIds as $locationId) {
                $url = str_replace(':locationId', $locationId, $d4meUrl);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->tokenD4Me
                ]);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $d4meResponse = curl_exec($ch);
                if ($d4meResponse === false) {
                    $logger->error('d4me request failed', ['locationId' => $locationId, 'error' => curl_error($ch)]);
                    $d4meFailed = true;
                }
                $d4meResponse = json_decode((string)$d4meResponse, true);
                if (isset($d4meResponse['data']) && is_array($d4meResponse['data'])) {
                    $d4meData['data'] = array_merge($d4meData['data'], $d4meResponse['data']);
                } else {
                    $d4meFailed = true;
                }
                curl_close($ch);
            }

            if (!$d4meFailed) {
                gacWriteJsonFile($d4meCacheFile, ['createdAt' => time(), 'response' => $d4meData]);
            } elseif ($d4meCached) {
                // Incomplete fresh data: serve the last complete (stale) dataset instead
                $d4meData = $d4meCached['response'];
                $logger->warning('d4me fetch incomplete, using stale cache', ['age' => $d4meCached['age']]);
            }
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

            $logger->info('Locker data processed', ['d4meLocations' => count($d4meLocationIds), 'memory_peak' => memory_get_peak_usage(true)]);
            return $this->response = $this->createResponse(200, 'Locker data retrieved successfully', $availableData);
        } catch (Throwable $e) {
            global $logger;
            $logger->error('getLockerDataFunction exception', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
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
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

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
