<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require_once('../../vendor/autoload.php');


use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('getIssueTicketsReport');
$logger->pushHandler(new RotatingFileHandler('logs/getIssueTicketsReport.log', 5));

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
    private $losGetIssueTicketsDevUrl;
    private $logger;
    public function __construct($conn, &$response, $losUserName, $losPassword, $losLoginUrl, $losGetIssueTicketsUrl, $losGetIssueTicketsDevUrl, $tokenMMS, $logger)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->losUserName = $losUserName;
        $this->losPassword = $losPassword;
        $this->losLoginUrl = $losLoginUrl;
        $this->losGetIssueTicketsUrl = $losGetIssueTicketsUrl;
        $this->losGetIssueTicketsDevUrl = $losGetIssueTicketsDevUrl;
        $this->tokenMMS = $tokenMMS;
        $this->logger = $logger;
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
        // Lekérni adatbázisból az előző napi adatokat
        $stmt = "SELECT * FROM los_issue_tickets ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($stmt);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $highestDate = $results ? $results[0]['created_at'] : null;

        return [
            'data' => $results,
            'highestDate' => $highestDate
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
            $this->logger->error('Error fetching token from database', ['error' => $errorInfo]);
            return $this->createResponse(500, $errorInfo, null);
        }

        // Explicitly use $this->tokenMMS to access the token
        if ($tokenFromDb !== $this->tokenMMS) {
            $this->logger->error('Unauthorized access attempt', ['token' => $this->tokenMMS]);
            return $this->createResponse(401, "Unauthorized", null);
        }
        //logging
        $this->logger->info('User authorized', ['token' => $this->tokenMMS]);
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
            $this->logger->error('Error fetching token from database', ['error' => $errorInfo]);
            return $this->createResponse(500, $errorInfo, null);
        }
    }

    public function storeDataToDatabase($data)
    {
        try {
            // Prepare upsert statement
            $sql = "INSERT INTO los_issue_tickets (`payload`, `created_at`, `inserted_at`) VALUES (:payload, :created_at, NOW())";
            $stmt = $this->conn->prepare($sql);

            // Data importálása
            $this->conn->beginTransaction();
            $count = 0;
            foreach ($data as $item) {
                // Determine external id
                $externalId = null;
                if (isset($item['id'])) $externalId = (string)$item['id'];
                elseif (isset($item['externalId'])) $externalId = (string)$item['externalId'];
                else {
                    // skip items without id
                    continue;
                }

                // createdAt -> MySQL DATETIME
                $createdAt = null;
                if (isset($item['createdAt'])) {
                    $ts = strtotime($item['createdAt']);
                    if ($ts !== false) $createdAt = date('Y-m-d H:i:s', $ts);
                }

                $payload = json_encode($item, JSON_UNESCAPED_UNICODE);

                $stmt->bindValue(':payload', $payload, PDO::PARAM_STR);
                $stmt->bindValue(':created_at', $createdAt, PDO::PARAM_STR);
                $stmt->execute();

                $count++;
            }
            $this->conn->commit();
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            $this->logger->error('Error storing data to database', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function storeWholeDataToDatabase($data)
    {
        try {
            $sql = "UPDATE los_tickets SET `payload` = :payload WHERE `id` = :id";
            $stmt = $this->conn->prepare($sql);

            $this->conn->beginTransaction();

            $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

            $stmt->bindValue(':payload', $payload, PDO::PARAM_STR);
            $stmt->bindValue(':id', 1, PDO::PARAM_INT);
            $stmt->execute();

            $this->conn->commit();

            return true;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            $this->logger->error('Error storing whole data to database', ['error' => $e->getMessage()]);
            throw $e;
            return false;
        }
    }

    public function getIssueTicketsFunction()
    {
        try {
            // Authorization check
            $isUserAuthorizedResult = $this->isUserAuthorized();
            if ($isUserAuthorizedResult['status'] !== 200) {
                $this->logger->error('User not authorized', ['response' => $isUserAuthorizedResult]);
                return $this->response = $isUserAuthorizedResult;
            }

            //Login
            $loginResponse = $this->login();
            if ($loginResponse['status'] !== 200) {
                $this->logger->error('Login failed', ['response' => $loginResponse]);
                return $this->response = $loginResponse;
            }

            //API hívás
            $ch = curl_init($this->losGetIssueTicketsUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getToken()
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $result = json_decode($result, true);
            curl_close($ch);

            //API hívás ellenőrzése
            if ($result === false) {
                $this->logger->error('Failed to get locker data', ['error' => curl_error($ch)]);
                return $this->response = $this->createResponse(400, 'Failed to get locker data: ' . curl_error($ch));
            }
            // Ellenőrizzük a payload létezését
            if (!isset($result['payload'])) {
                $this->logger->error('Failed to get issue tickets', ['httpCode' => $httpCode, 'response' => $result, 'token' => $this->getToken()]);
                return $this->response = $this->createResponse($httpCode, 'Failed to get issue tickets: ' . $result['payload']);
            }
            // Ellenőrizzük, hogy van-e 'items' a payload-ban
            if (isset($result['payload']['items'])) {
                $this->logger->info('Issue tickets retrieved successfully', ['count' => count($result['payload']['items'])]);
                $newItems = $result['payload']['items'];
            }
            // Ha nincs 'items', akkor üres tömböt adunk vissza
            else {
                $this->logger->info('No issue tickets found in payload', ['response' => $result]);
                $newItems = [];
            }

            // Lekérjük a legmagasabb dátumot és szűrjük az új elemeket helyes módon
            $highestDate = $this->getStoredData()['highestDate'];
            $highestTs = $highestDate ? strtotime($highestDate) : 0;

            // rendezzük az új elemeket csökkenő createdAt szerint (ha az API nem garantálja)
            usort($newItems, function ($a, $b) {
                $ta = isset($a['createdAt']) ? strtotime($a['createdAt']) : 0;
                $tb = isset($b['createdAt']) ? strtotime($b['createdAt']) : 0;
                return $tb <=> $ta;
            });

            $filteredNewItems = [];
            foreach ($newItems as $item) {
                if (!isset($item['createdAt'])) continue;
                $itemTs = strtotime($item['createdAt']);
                if ($itemTs > $highestTs) {
                    $filteredNewItems[] = $item;
                }
            }

            $existingData = $this->getStoredData()['data'];

            $this->logger->info('Diagnostics before writing', [
                'newItems_count' => count($newItems),
                'highestDate' => $highestDate,
                'filtered_count' => count($filteredNewItems),
                'existing_count' => count($existingData)
            ]);

            // Adatok tárolása az adatbázisban
            $this->storeDataToDatabase($filteredNewItems);

            // Logoljuk a sikeres műveletet
            $this->logger->info('Data stored successfully', ['new_records' => count($filteredNewItems)]);

            // Az összes adat tárolása
            $isSuccess = $this->storeWholeDataToDatabase($result['payload']['items'] ?? []);
            if ($isSuccess) {
                $this->logger->info('Whole data stored successfully');
            } else {
                $this->logger->error('Failed to store whole data');
            }
        } catch (Exception $e) {
            return $this->logger->error('Error in getIssueTicketsFunction', ['error' => $e->getMessage()]);
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
            $result = json_decode($result, true);

            if ($result === false) {
                $this->logger->error('Login failed', ['curl_error' => curl_error($ch)], ['httpCode' => $httpCode], ['response' => $result]);
                return $this->createResponse(400, 'Login failed: ' . curl_error($ch));
            }

            curl_close($ch);

            if (isset($result['payload']['token'])) {
                $this->token = $result['payload']['token'];
                $this->storeTokenInDatabase($this->token);
                $this->logger->info('Login successful', ['token' => $this->token]);
                return $this->createResponse(200, 'Login successful', $result);
            } else {
                $this->logger->error('Login failed', ['response' => $result]);
                return $this->createResponse(400, 'Login failed');
            }
        } catch (Exception $e) {
            $this->logger->error('Error during login', ['error' => $e->getMessage()]);
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
$getIssueTickets = new GetIssueTickets($conn, $response, $losUserName, $losPassword, $losLoginUrl, $losGetIssueTicketsUrl, $losGetIssueTicketsDevUrl, $tokenMMS, $logger);
$getIssueTickets->getIssueTicketsFunction($payload, $tokenMMS);
echo json_encode($response);
