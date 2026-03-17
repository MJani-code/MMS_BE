<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

class GetAllLosTickets
{
    private $conn;
    private $response;
    private $auth;
    private $getAllActivePointsUrl;
    private $user;
    private $password;

    public function __construct($conn, &$response, $auth, $getAllActivePointsUrl, $user, $password)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
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

    public function getAllLosTicketsData($token)
    {
        //token értékét kikérni közvetlenül db-ből
        try {
            $stmt = $this->conn->prepare("SELECT token FROM api_tokens where api='grafana/getInvoicedTasks' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tokenFromDb = $result ? $result['token'] : null;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'data' => NULL
            );
            return;
        }

        if ($tokenFromDb !== $token) {
            return $this->response = $this->createResponse(401, "Unauthorized", $token);
        }

        //Data gathering
        try {
            // Base query
            $query = "SELECT
                        payload
                        FROM los_tickets
                        WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', 1, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && isset($result['payload']) && is_string($result['payload'])) {
                $payloadRaw = $result['payload'];

                // 1) Normal case: payload is valid JSON (array/object)
                $decoded = json_decode($payloadRaw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // 1/a) Sometimes payload is itself a JSON-encoded string of JSON
                    if (is_string($decoded)) {
                        $decoded2 = json_decode($decoded, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $decoded = $decoded2;
                        }
                    }
                    $result['payload'] = $decoded;
                } else {
                    // 2) Legacy case: payload stored like [{\"uuid\":...}] (not valid JSON)
                    // Try unescaping backslashes once and decode again.
                    $unescaped = stripslashes($payloadRaw);
                    $decoded = json_decode($unescaped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $result['payload'] = $decoded;
                    }
                }
            }

            $exoboxPointsRawData = getExoboxPoints($this->getAllActivePointsUrl, $this->user, $this->password, null);
            $exoboxPoints = $exoboxPointsRawData['payload'];

            // exoboxPoints indexelése
            $exoboxIndex = [];
            foreach ($exoboxPoints as $point) {
                $exoboxIndex[$point['point_id']] = $point;
            }

            // enrichedData előállítása, gps adatok hozzáadásával
            $enrichedData = [];
            foreach ($result['payload'] as $ticket) {
                $id = isset($ticket['lockerDisplayName']) ? str_replace('EXP-', '', $ticket['lockerDisplayName']) : null;
                if ($id && isset($exoboxIndex[$id])) {
                    $ticket['latitude'] = $exoboxIndex[$id]['lat'] ?? null;
                    $ticket['longitude'] = $exoboxIndex[$id]['lng'] ?? null;
                }
                $enrichedData[] = $ticket;
            }

            return $this->response = $enrichedData ?? null;
        } catch (Exception $e) {
            $errorInfo = $e->getMessage();
            $this->response = array(
                'status' => 500,
                'message' => $errorInfo,
                'data' => [
                    'query' => strval($query),
                    'stmt' => $stmt
                ]
            );
            return;
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$getAllLosTickets = new GetAllLosTickets($conn, $response, $auth, $getAllActivePointsUrl, $user, $password);
$getAllLosTickets->getAllLosTicketsData($token);

echo json_encode($response);
