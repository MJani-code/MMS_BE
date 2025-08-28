<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../vendor/autoload.php');


//hibaüzenetek bekapcsolása
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

    public function __construct($conn, &$response, $token, $logger, $tofShopIdUrl)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->token = $token;
        $this->logger = $logger;
        $this->tofShopIdUrl = $tofShopIdUrl;
    }

    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
    }

    public function getStoredData()
    {
        //getIssueTicketsReport2.json fájl tartalmának lekérése
        $filePath = 'getIssueTicketsReport2.json';
        if (file_exists($filePath)) {
            $jsonData = file_get_contents($filePath);
            return json_decode($jsonData, true);
        } else {
            return [];
        }
    }

    public function isUserAuthorized()
    {
        try {
            $stmt = $this->conn->prepare("SELECT token FROM api_tokens where api='grafana/getInvoicedTasks' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tokenFromDb = $result ? $result['token'] : null;
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
            $storedData = $this->getStoredData();
            if (empty($storedData)) {
                $this->logger->error('No data found in getIssueTicketsReport2.json');
                return $this->response = $this->createResponse(404, "No data found");
            }

            //get exoboxPoints
            $result = getExoboxPoints($this->tofShopIdUrl);
            if ($result['status'] !== 200) {
                $this->logger->error('Error fetching exobox points: ' . $result);
                return $this->response = $this->createResponse($result['status'], $result['message']);
            }
            $exoboxPoints = $result['payload']['points'];

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

$getIssueTickets = new GetIssueTickets($conn, $response, $token, $logger, $tofShopIdUrl);
$getIssueTickets->getIssueTicketsFunction($payload);
echo json_encode($response);
