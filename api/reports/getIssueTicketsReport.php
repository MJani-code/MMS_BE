<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('getIssueTicketsReport.json');

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

    private function storeTokenInDatabase($token)
    {
        $stmt = $this->conn->prepare("INSERT INTO api_tokens (token, api) VALUES (:token, :api)");
        $stmt->execute([':token' => $token, ':api' => 'LOS']);
    }

    public function getStoredData()
    {
        //getIssueTicketsReport.json fájl tartalmának lekérése
        $filePath = 'getIssueTicketsReport.json';
        if (file_exists($filePath)) {
            $jsonData = file_get_contents($filePath);
            return json_decode($jsonData, true);
        } else {
            return [];
        }
    }

    public function getHighestDate()
    {
        // Lekéri a legnagyobb dátumot a getIssueTicketsReport.json fájlból
        $data = $this->getStoredData();
        if (empty($data)) {
            return null; // Ha nincs adat, visszatér null-lal
        }

        $highestDate = null;
        foreach ($data as $item) {
            if (isset($item['date'])) {
                $date = $item['date'];
                if ($highestDate === null || strtotime($date) > strtotime($highestDate)) {
                    $highestDate = $date;
                }
            }
        }
        return $highestDate;
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

    public function getToken()
    {
        try {
            // Token lekérése az adatbázisból
            $stmt = $this->conn->prepare("SELECT token FROM api_tokens where api='LOS' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $token = $result ? $result['token'] : null;
            $this->token = $token;

            return $token;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            return $this->createResponse(500, $errorInfo, null);
        }
    }

    public function getIssueTicketsFunction($payload)
    {
        try {
            // Authorization check
            $isUserAuthorizedResult = $this->isUserAuthorized();
            if ($isUserAuthorizedResult['status'] !== 200) {
                return $this->response = $isUserAuthorizedResult;
            }

            $ch = curl_init('https://loswebapi.expressone.hu/Los/GetIssueTicketsForReport');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getToken()
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($result === false) {
                return $this->response = $this->createResponse(400, 'Failed to get locker data: ' . curl_error($ch));
            }

            $loggedIn = 0;
            if ($httpCode === 401 && $loggedIn === 0) {
                // If unauthorized, attempt to log in
                $loginResponse = $this->login();
                if ($loginResponse['status'] !== 200) {
                    return $this->response = $loginResponse;
                }
                // Retry the request after successful login
                $loggedIn = 1;
                return $this->getIssueTicketsFunction($payload);
            }

            curl_close($ch);
            $result = json_decode($result, true);


            if (isset($result['payload']['items'])) {
                $loggedIn = 1;
                $allItems = $result['payload']['items'];
            }

            // Process the items to count issueType occurrences by day
            $issueTickets = [];
            foreach ($allItems as $item) {
                $issueType = $item['ticketDetails']['category']['topicDisplay'];

                $highestDate = $this->getHighestDate();
                if ($item['createdAt'] < $highestDate || $item['createdAt'] == $highestDate) {
                    continue; // Skip items with a date earlier than the highest date
                }
                $date = date('Y-m-d', strtotime($item['createdAt'])); // Assuming 'createdAt' contains the date

                if (isset($payload['username'])) {
                    if ($item['username'] == $payload['username']) {
                        if (!isset($issueTickets["$date-$issueType"])) {
                            $issueTickets["$date-$issueType"] = [
                                'date' => $date,
                                'issueType' => $issueType,
                                'count' => 0,
                                'uuid' => $item['uuid'],
                                'compartmentNumber' => $item['compartmentNumber'],
                                'integrationCode' => $item['integrationCode'],
                                'username' => $item['username']
                            ];
                        }
                        $issueTickets["$date-$issueType"]['count']++;
                        //a uuid értékeket egy tömbe a uuid kulcsban tárolja
                        if (!isset($issueTickets["$date-$issueType"]['uuids'])) {
                            $issueTickets["$date-$issueType"]['uuids'] = [];
                        }

                        $issueTickets["$date-$issueType"]['uuids'][] = $item['uuid'];
                    }
                } else {
                    if (!isset($issueTickets["$date-$issueType"])) {
                        $issueTickets["$date-$issueType"] = [
                            'date' => $date,
                            'issueType' => $issueType,
                            'count' => 0,
                            'uuid' => $item['uuid'],
                            'compartmentNumber' => $item['compartmentNumber'],
                            'integrationCode' => $item['integrationCode'],
                            'username' => $item['username']
                        ];
                    }
                    $issueTickets["$date-$issueType"]['count']++;
                    //a uuid értékeket egy tömbe a uuid kulcsban tárolja
                    if (!isset($issueTickets["$date-$issueType"]['uuids'])) {
                        $issueTickets["$date-$issueType"]['uuids'] = [];
                    }

                    $issueTickets["$date-$issueType"]['uuids'][] = $item['uuid'];
                }
            }

            // Re-index the array to remove keys
            $issueTickets = array_values($issueTickets);

            //hozzáadni az $issueTickets-t a getIssueTicketsReport.json fájlhoz
            $existingData = $this->getStoredData();
            $existingData = array_merge($existingData, $issueTickets);
            file_put_contents('getIssueTicketsReport.json', json_encode($existingData, JSON_PRETTY_PRINT));
            // Visszatérés a getIssueTicketsReport.json fájl tartalmával
            $issueTickets = $this->getStoredData();
            //Visszatérés a lekért adatokkal

            return $this->response = $issueTickets;
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
//ha a tokenMMS üres, akkor kinyerni az URL-ből a tokent
if (empty($tokenMMS)) {
    $tokenMMS = $_GET['token'] ?? null;
}

$getIssueTickets = new GetIssueTickets($conn, $response, $losUserName, $losPassword, $losLoginUrl, $losGetIssueTicketsUrl, $tokenMMS);
$getIssueTickets->getIssueTicketsFunction($payload, $tokenMMS);
echo json_encode($response);
