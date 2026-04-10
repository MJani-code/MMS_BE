<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../functions/analyzeLockerErrors.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class getCompartmentsAvailability
{
    private $conn;
    private $losUserName;
    private $losPassword;
    private $losLoginUrl;
    private $losGetLockerStationsForPortalUrl;
    private $response;
    private $auth;
    private $token;

    public function __construct($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, &$response, $auth)
    {
        $this->conn = $conn;
        $this->losUserName = $losUserName;
        $this->losPassword = $losPassword;
        $this->losLoginUrl = $losLoginUrl;
        $this->losGetLockerStationsForPortalUrl = $losGetLockerStationsForPortalUrl;
        $this->response = &$response;
        $this->auth = $auth;
        $this->token = $this->getTokenFromDatabase();
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

    public function getCompartmentsAvailabilityData(?string $fromDate = null, ?string $toDate = null)
    {
        try {
            $fromDate = $fromDate ?: date('Y-m-d');
            $toDate = $toDate ?: $fromDate;

            $fromDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate);
            $toDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $toDate);

            if (!$fromDateObj || !$toDateObj || $fromDateObj->format('Y-m-d') !== $fromDate || $toDateObj->format('Y-m-d') !== $toDate) {
                return $this->response = $this->createResponse(400, 'Hibás dátum formátum. Várt formátum: YYYY-MM-DD.');
            }

            if ($toDateObj < $fromDateObj) {
                return $this->response = $this->createResponse(400, 'A toDate nem lehet korábbi, mint a fromDate.');
            }

            $stmt = $this->conn->prepare("SELECT
                    r.id AS reportId,
                    r.tof_shop_id AS tofShopId,
                    r.locker_display_name AS lockerDisplayName,
                    r.report_day AS day,
                    r.report_created_day AS reportCreatedDay,
                    r.is_enabled AS isEnabled,
                    r.compartment_count AS compartmentCount,
                    r.uuid AS uuid,
                    c.compartment_number AS compartmentNumber,
                    c.issue_type AS issueType,
                    c.fault_minutes AS faultTime,
                    c.operating_minutes AS operatingTime
                FROM locker_daily_reports r
                LEFT JOIN locker_daily_report_compartments c ON c.report_id = r.id
                WHERE r.report_day BETWEEN :fromDate AND :toDate
                ORDER BY r.report_day ASC, r.tof_shop_id ASC, c.compartment_number ASC");

            $stmt->execute([
                ':fromDate' => $fromDate,
                ':toDate' => $toDate
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // echo json_encode($rows);
            // return;

            if (empty($rows)) {
                return $this->response = $this->createResponse(404, 'Nincs találat a megadott időszakra.', []);
            }

            $flattened = [];
            foreach ($rows as $row) {
                if (($row['compartmentNumber'] ?? null) === null || (string)$row['compartmentNumber'] === '') {
                    continue;
                }

                $flattened[] = [
                    'tofShopId' => (int)($row['tofShopId'] ?? 0),
                    'lockerDisplayName' => (string)($row['lockerDisplayName'] ?? ''),
                    'day' => (string)($row['day'] ?? ''),
                    'reportCreatedDay' => (string)($row['reportCreatedDay'] ?? ''),
                    'isEnabled' => (int)($row['isEnabled'] ?? 0),
                    'compartmentCount' => (int)($row['compartmentCount'] ?? 0),
                    'uuid' => (string)($row['uuid'] ?? ''),
                    'compartmentNumber' => (string)$row['compartmentNumber'],
                    'issueType' => $row['issueType'] ?? null,
                    'faultTime' => max(0, min(1440, (int)($row['faultTime'] ?? 0))),
                    'operatingTime' => max(0, min(1440, (int)($row['operatingTime'] ?? 0)))
                ];
            }

            if (empty($flattened)) {
                return $this->response = $this->createResponse(404, 'Nincs compartment szintű találat a megadott időszakra.', []);
            }

            $this->response = $flattened;
        } catch (Throwable $th) {
            return $this->response = $this->createResponse(500, 'Hiba történt az adatok lekérése során: ' . $th->getMessage(), null);
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$tokenMMS = $matches[1];

$auth = new Auth($conn, $tokenMMS, $secretkey);

$getCompartmentsAvailability = new getCompartmentsAvailability($conn, $losUserName, $losPassword, $losLoginUrl, $losGetLockerStationsForPortalUrl, $response, $auth);
$getCompartmentsAvailability->getCompartmentsAvailabilityData($payload['fromDate'] ?? null, $payload['toDate'] ?? null);

echo json_encode($response);
