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

    public function __construct($conn, &$response, $token)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->token = $token;
    }

    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
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
        }else {
            return $this->createResponse(200, "Authorized", null);
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

            // Get stored data from JSON file
            $storedData = $this->getStoredData();
            if (empty($storedData)) {
                return $this->response = $this->createResponse(404, "No data found");
            }
            // Filter data based on payload
            $filteredData = array_filter($storedData, function ($item) use ($payload) {
                foreach ($payload as $key => $value) {
                    if (isset($item[$key]) && $item[$key] != $value) {
                        return false;
                    }
                }
                return true;
            });
            // If no data matches the filter, return a 404 response
            if (empty($filteredData)) {
                return $this->response = $this->createResponse(404, "No matching data found");
            }
            // Return the filtered data
            return $this->response = array_values($filteredData);
            
        } catch (Exception $e) {
            return $this->response = $this->createResponse(400, $e->getMessage());
        }
    }    
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$getIssueTickets = new GetIssueTickets($conn, $response, $token);
$getIssueTickets->getIssueTicketsFunction($payload);
echo json_encode($response);