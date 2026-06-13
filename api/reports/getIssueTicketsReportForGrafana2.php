<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../vendor/autoload.php');

//debug error
error_reporting(E_ALL);
ini_set('display_errors', 1);

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('getIssueTicketsReportForGrafana2');
$logger->pushHandler(new RotatingFileHandler('logs/getIssueTicketsReportForGrafana2.log', 5));

$response = [];
$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class GetIssueTickets
{
    private $conn;
    private $response;
    private $token;
    private $logger;
    private $tofShopIdUrl;
    private $getAllActivePointsUrl;
    private $user;
    private $password;

    public function __construct($conn, &$response, $token, $logger, $tofShopIdUrl, $getAllActivePointsUrl, $user, $password)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->token = $token;
        $this->logger = $logger;
        $this->tofShopIdUrl = $tofShopIdUrl;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
    }

    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
    }

    public function getStoredData($from, $to)
    {
        // Adatok lekérése adatbázisból
        $start = microtime(true);
        $stmt = "SELECT payload FROM los_issue_tickets WHERE created_at BETWEEN :from AND :to ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($stmt);
        $stmt->bindParam(':from', $from);
        $stmt->bindParam(':to', $to);
        $stmt->execute();
        $end = microtime(true);
        $this->logger->info('getStoredData function - Time taken to execute query on los_issue_tickets database: ' . ($end - $start) . ' seconds');

        $start = microtime(true);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $end = microtime(true);
        $this->logger->info('getStoredData function - Time taken to fetch data from los_issue_tickets database: ' . ($end - $start) . ' seconds', ['resultCount' => count($results)]);

        $allData = [];
        foreach ($results as $row) {
            $data = json_decode($row['payload'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $allData[] = $data;
            } else {
                $this->logger->error('json_decode error for payload', ['error' => json_last_error_msg()]);
            }
        }

        return $allData;
    }

    public function isUserAuthorized()
    {
        try {
            $start = microtime(true);
            $stmt = $this->conn->prepare("SELECT token FROM api_tokens where api='grafana/getInvoicedTasks' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tokenFromDb = $result ? $result['token'] : null;
            $end = microtime(true);
            $this->logger->info('isUserAuthorized function - Time taken to fetch token from api_tokens database: ' . ($end - $start) . ' seconds');
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            return $this->createResponse(500, $errorInfo, null);
        }

        // Explicitly use $this->token to access the token
        if ($tokenFromDb !== $this->token) {
            return $this->createResponse(401, "Unauthorized", null);
        } else {
            return $this->createResponse(200, "Authorized", null);
        }
    }

    public function getIssueTicketsFunction($payload)
    {
        try {
            // Authorization check
            $isUserAuthorizedResult = $this->isUserAuthorized();
            if ($isUserAuthorizedResult['status'] !== 200) {
                $this->logger->warning('User not authorized');
                return $this->response = $isUserAuthorizedResult;
            }

            // Get stored data from JSON file
            $storedData = $this->getStoredData($payload['from'], $payload['to']);
            if (empty($storedData)) {
                $this->logger->error('No data found in the database');
                return $this->response = $this->createResponse(404, "No data found");
            }

            //get exoboxPoints
            $start = microtime(true);
            $result = getExoboxPoints($this->getAllActivePointsUrl, $this->user, $this->password, null);
            $end = microtime(true);
            $this->logger->info('getIssueTicketsFunction - Time taken to fetch exobox points: ' . ($end - $start) . ' seconds');

            if (empty($result)) {
                $this->logger->error('Error fetching exobox points: ' . $result);
                return $this->response = $this->createResponse($result, null, null);
            }
            $exoboxPoints = $result['payload'];

            // Filter data based on payload
            $filteredData = array_filter($storedData, function ($item) use ($payload) {
                foreach ($payload as $key => $value) {
                    if ($item['uuid'] === null) {
                        return false; // Skip items with null UUID
                    }
                    if (is_array($value) && $item[$key] !== null) {
                        if (!in_array($item[$key], $value)) {
                            return false;
                        }
                        return true;
                    }
                    if (isset($item[$key]) && $item[$key] != $value) {
                        return false;
                    }
                }
                return true;
            });
            // If no data matches the filter, return a 404 response
            if (empty($filteredData)) {
                $this->logger->warning('No matching data found');
                return $this->response = $this->createResponse(404, "No matching data found");
            } else {
                $this->logger->info('Data filtered successfully', ['filteredDataCount' => count($filteredData)]);
            }

            // exoboxPoints indexelése
            $exoboxIndex = [];
            foreach ($exoboxPoints as $point) {
                $exoboxIndex[$point['point_id']] = $point;
            }

            // enrichedData előállítása, gps adatok hozzáadásával
            $enrichedData = [];
            foreach ($filteredData as $item) {
                $id = isset($item['lockerDisplayName']) ? str_replace('EXP-', '', $item['lockerDisplayName']) : null;
                if ($id && isset($exoboxIndex[$id])) {
                    $item['latitude'] = $exoboxIndex[$id]['lat'] ?? null;
                    $item['longitude'] = $exoboxIndex[$id]['lng'] ?? null;
                }
                $enrichedData[] = $item;
            }

            if (empty($enrichedData)) {
                $this->logger->warning('No enriched data found after processing');
                return $this->response = $this->createResponse(404, "No enriched data found");
            } else {
                $this->logger->info('Enriched data created successfully', ['enrichedDataCount' => count($enrichedData)]);
            }

            return $this->response = $enrichedData;
        } catch (Exception $e) {
            return $this->response = $this->createResponse(400, $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$getIssueTickets = new GetIssueTickets($conn, $response, $token, $logger, $tofShopIdUrl, $getAllActivePointsUrl, $user, $password);
$getIssueTickets->getIssueTicketsFunction($payload);
echo json_encode($response);
