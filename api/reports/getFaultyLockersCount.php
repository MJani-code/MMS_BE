<?php
header('Content-Type: application/json');

require('../../inc/conn.php');

//hibaüzenetek bekapcsolása
error_reporting(E_ALL);
ini_set('display_errors', 1);



$response = [];
$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class GetIssueTickets
{
    private $conn;
    private $response;
    private $token;
    private $tokenMMS;
    private $losUserName;
    private $losPassword;
    private $losLoginUrl;
    private $losGetIssueTicketsUrl;

    public function __construct($conn, &$response, $losUserName, $losPassword, $losLoginUrl, $losGetIssueTicketsUrl, $tokenMMS)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->losUserName = $losUserName;
        $this->losPassword = $losPassword;
        $this->losLoginUrl = $losLoginUrl;
        $this->losGetIssueTicketsUrl = $losGetIssueTicketsUrl;
        $this->tokenMMS = $tokenMMS;
    }

    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
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

        // Explicitly use $this->tokenMMS to access the token
        if ($tokenFromDb !== $this->tokenMMS) {
            return $this->createResponse(401, "Unauthorized", null);
        }
        return $this->createResponse(200, "Authorized", null);
    }

    public function getStoredData(){
        //getIssueTicketsReport.json fájl tartalmának lekérése
        $filePath = 'getIssueTicketsReport.json';
        if (file_exists($filePath)) {
            $jsonData = file_get_contents($filePath);
            return json_decode($jsonData, true);
        } else {
            return [];
        }
    }

    public function getToken()
    {
        try {
            // Token lekérése az adatbázisból
            $stmt = $this->conn->prepare("SELECT token FROM api_tokens where api='LOS' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $token = $result ? $result['token'] : null;
            if (!$token) {
                $loginResult = $this->login();
                if ($loginResult['status'] !== 200) {
                    return $this->response = $loginResult;
                }
                $token = $loginResult['payload']['token'];
            }
            return $token;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            return $this->createResponse(500, $errorInfo, null);
        }
    }

    public function getIssueTicketsFunction($payload)
    {
        //locker adatok lekérdezése
        try {
            //hozzáférés ellenőrzése
            $isUserAuthorizedResult = $this->isUserAuthorized();

            if ($isUserAuthorizedResult['status'] !== 200) {
                return $this->response = $isUserAuthorizedResult;
            }
            
            $allItems = $this->getStoredData();
            if (empty($allItems)) {
                return $this->response = $this->createResponse(404, 'No issue tickets found');
            }
            

            $issueTickets = [];
            $faultyLockersByDate = []; // To store unique UUIDs grouped by date

            foreach ($allItems as $item) {
                $issueType = $item['issueType'];
                $date = $item['date'];
                $uuid = $item['uuid'];

                if (!isset($faultyLockersByDate[$date])) {
                    $faultyLockersByDate[$date] = [];
                }

                $faultyLockersByDate[$date][] = $uuid;

                // // Check combined and separate conditions
                // $matchesUsername = isset($payload['username']) && $item['username'] == $payload['username'];
                // $matchesIssueType = isset($payload['issueType']) && $issueType == $payload['issueType'];

                // //Check if some of the conditions are null

                // if (empty($payload['username']) || empty($payload['issueType'])) {
                //     if (!in_array($uuid, $faultyLockersByDate[$date])) {
                //         $faultyLockersByDate[$date][] = $uuid;
                //     }
                // }

                // if (($matchesUsername && $matchesIssueType)) {
                //     if (!in_array($uuid, $faultyLockersByDate[$date])) {
                //         $faultyLockersByDate[$date][] = $uuid;
                //     }
                // }
            }

            // Sort the dates in ascending order
            ksort($faultyLockersByDate);

            // Calculate cumulative unique UUID counts by day
            $cumulativeCounts = [];
            $totalCount = 0;
            $uniqueUuids = []; // To track all unique UUIDs globally

            foreach ($faultyLockersByDate as $date => $uuids) {
                $newUniqueCount = 0;
                $uniqueUuidsForDate = [];

                foreach ($uuids as $uuid) {
                    if (!in_array($uuid, $uniqueUuids)) {
                        $uniqueUuids[] = $uuid;
                        $uniqueUuidsForDate[] = $uuid;
                        $newUniqueCount++;
                    }
                }

                $totalCount += $newUniqueCount;
                $cumulativeCounts[] = [
                    'date' => $date,
                    'uniqueUuidCount' => $newUniqueCount,
                    'cumulativeCount' => $totalCount,
                    'newFaultyLockers' => $uniqueUuidsForDate
                ];
            }

            // Re-index the array to remove keys
            $issueTickets = array_values($cumulativeCounts);

            return $this->response = $cumulativeCounts;
        } catch (Exception $e) {
            return $this->response = $this->createResponse(400, $e->getMessage());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$tokenMMS = $matches[1];

$getIssueTickets = new GetIssueTickets($conn, $response, $losUserName, $losPassword, $losLoginUrl, $losGetIssueTicketsUrl, $tokenMMS);
$getIssueTickets->getIssueTicketsFunction($payload, $tokenMMS);
echo json_encode($response);
