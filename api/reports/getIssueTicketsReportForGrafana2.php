<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../vendor/autoload.php');

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

    private function getIssueTypeNames()
    {
        return [
            0 => 'NONE',
            1 => 'CANNOT_CONNECT_BLE',
            2 => 'COMPARTMENT_EMPTY',
            3 => 'COMPARTMENT_IS_ALREADY_OCCUPIED',
            4 => 'COMPARTMENT_DIRTY',
            5 => 'LID_NOT_OPENING',
            6 => 'LID_NOT_CLOSING',
            7 => 'BATTERY_IS_LOW',
            8 => 'BIGGER_COMPARTMENT_REQUIRED',
            9 => 'MISSING_BARCODES',
            10 => 'NON_COMPLIANT_FOR_LOCKER',
            11 => 'NO_SUITABLE_SIZE',
            12 => 'COMPARTMENT_LEFT_OPEN',
            13 => 'DAMAGED_COMPARTMENT',
            14 => 'Block Compartment For Maintenance Check',
            15 => 'Lid Not Detected As Closed',
            16 => 'Locker 4G Connection Drop'
        ];
    }

    private function normalizeIssueTypeName($name)
    {
        return strtoupper(str_replace(' ', '_', trim($name)));
    }

    private function resolveIssueTypesFromPayload($payload)
    {
        $defaultIssueTypes = [1, 2, 3, 4, 5, 6, 11, 13, 16];

        if (isset($payload['issueType']) && is_array($payload['issueType']) && !empty($payload['issueType'])) {
            return array_values(array_unique(array_map('intval', $payload['issueType'])));
        }

        if (!isset($payload['issueTypeNames']) || !is_array($payload['issueTypeNames']) || empty($payload['issueTypeNames'])) {
            return $defaultIssueTypes;
        }

        $issueTypeNameToId = [];
        foreach ($this->getIssueTypeNames() as $id => $name) {
            $issueTypeNameToId[$this->normalizeIssueTypeName($name)] = $id;
        }

        $resolvedIssueTypes = [];
        foreach ($payload['issueTypeNames'] as $issueTypeName) {
            if (!is_string($issueTypeName)) {
                continue;
            }

            $normalizedIssueTypeName = $this->normalizeIssueTypeName($issueTypeName);
            if (isset($issueTypeNameToId[$normalizedIssueTypeName])) {
                $resolvedIssueTypes[] = $issueTypeNameToId[$normalizedIssueTypeName];
            }
        }

        if (empty($resolvedIssueTypes)) {
            return $defaultIssueTypes;
        }

        return array_values(array_unique($resolvedIssueTypes));
    }

    public function getStoredData($from, $to)
    {
        // Adatok lekérése adatbázisból        
        $stmt = "SELECT payload FROM los_issue_tickets WHERE created_at BETWEEN :from AND :to ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($stmt);
        $stmt->bindParam(':from', $from);
        $stmt->bindParam(':to', $to);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    public function filteringStoredData($storedData, $payload)
    {
        $rules = array(
            [
                'issueType' => $this->resolveIssueTypesFromPayload($payload),
                'username' => $payload['username'] ?? ['consignee', 'cour.exohu', 'Exp Hu Courier', 'Locker'],
            ]
        );

        $filteredData = array_filter($storedData, function ($item) use ($rules) {
            foreach ($rules as $rule) {
                foreach ($rule as $key => $values) {
                    if (isset($item[$key]) && !in_array($item[$key], $values)) {
                        return false;
                    }
                }
            }
            return true;
        });
        return $filteredData;
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

            $storedData = $this->filteringStoredData($storedData, $payload);

            //get exoboxPoints            
            $result = getExoboxPoints($this->getAllActivePointsUrl, $this->user, $this->password, null);


            if (empty($result)) {
                $this->logger->error('Error fetching exobox points: ' . $result);
                return $this->response = $this->createResponse($result, null, null);
            }
            $exoboxPoints = $result['payload'];

            $payloadForFiltering = $payload;
            $payloadForFiltering['issueType'] = $this->resolveIssueTypesFromPayload($payload);
            unset($payloadForFiltering['issueTypeNames']);

            // Filter data based on payload
            $filteredData = array_filter($storedData, function ($item) use ($payloadForFiltering) {
                foreach ($payloadForFiltering as $key => $value) {
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
                // $this->logger->info('Data filtered successfully', ['filteredDataCount' => count($filteredData)]);
            }

            // exoboxPoints indexelése
            $exoboxIndex = [];
            foreach ($exoboxPoints as $point) {
                $exoboxIndex[$point['point_id']] = $point;
            }

            // enrichedData előállítása, gps adatok hozzáadásával
            $enrichedData = [];
            foreach ($filteredData as $key => $item) {
                $id = isset($item['lockerDisplayName']) ? str_replace('EXP-', '', $item['lockerDisplayName']) : null;
                if ($id && isset($exoboxIndex[$id])) {
                    $item['index'] = $key + 1;
                    $item['latitude'] = $exoboxIndex[$id]['lat'] ?? null;
                    $item['longitude'] = $exoboxIndex[$id]['lng'] ?? null;
                }
                $enrichedData[] = $item;
            }

            if (empty($enrichedData)) {
                $this->logger->warning('No enriched data found after processing');
                return $this->response = $this->createResponse(404, "No enriched data found");
            } else {
                // $this->logger->info('Enriched data created successfully', ['enrichedDataCount' => count($enrichedData)]);
            }

            $this->response = $enrichedData;

            return $this->response;
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
