<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require_once('../../vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('CCTVDownloader');
$logger->pushHandler(new RotatingFileHandler('logs/CCTVDownloader.log', 5));


class CCTVDownloader
{
    private $conn;
    private $losCreateImageRequestWithInterval;
    private $losCreateImageRequestWithIntervalTest;
    private $losCreateImageRequestToLocker;
    private $losCreateImageRequestToLockerTest;
    private $token;
    private $logger;

    public function __construct($conn, $losCreateImageRequestWithInterval, $losCreateImageRequestWithIntervalTest, $losCreateImageRequestToLocker, $losCreateImageRequestToLockerTest, $logger)
    {
        $this->conn = $conn;
        $this->losCreateImageRequestWithInterval = $losCreateImageRequestWithInterval;
        $this->losCreateImageRequestWithIntervalTest = $losCreateImageRequestWithIntervalTest;
        $this->losCreateImageRequestToLocker = $losCreateImageRequestToLocker;
        $this->losCreateImageRequestToLockerTest = $losCreateImageRequestToLockerTest;
        $this->token = $this->getToken();
        $this->logger = $logger;
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
        }
    }

    public function getStoredData()
    {
        // Adatok lekérése adatbázisból
        $stmt = "SELECT payload FROM los_issue_tickets ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($stmt);
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

    // function downloadCCTVForEventsByTime()
    // {

    //     try {
    //         #1. Adatok betöltése
    //         $allData = $this->getStoredData();

    //         #2. szűrés
    //         $today = date('Y-m-d');
    //         $usernameFilter = ['consignee', 'Exp Hu Courier', 'cour.exohu'];
    //         $issueTypeFilter = [2, 3, 5, 6];
    //         $filteredData = [];

    //         foreach ($allData as $event) {
    //             if (
    //                 $event['createdAt'] >= $today
    //                 && in_array($event['username'], $usernameFilter)
    //                 && in_array($event['issueType'], $issueTypeFilter)
    //             ) {
    //                 $filteredData[] = $event;
    //             }
    //         }

    //         #3. CCTV file letöltése a fileterezett eseményekhez 
    //         $ch = curl_init($this->losCreateImageRequestWithInterval);
    //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //         curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //             'Authorization: Bearer ' . $this->token,
    //             'Content-Type: application/json'
    //         ]);

    //         foreach ($filteredData as &$event) {
    //             $postData = [
    //                 'Uuid' => $event['uuid'],
    //                 'TimeInterval' => [
    //                     'StartDate' => (function ($start) {
    //                         $dt = new DateTime($start);
    //                         $dt->modify('-5 minutes');
    //                         $ms = substr($dt->format('u'), 0, 3);
    //                         return $dt->format('Y-m-d\TH:i:s') . '.' . $ms . 'Z';
    //                     })($event['createdAt']),
    //                     'EndDate' => (function ($start) {
    //                         $dt = new DateTime($start);
    //                         $dt->modify('+25 minutes');
    //                         $ms = substr($dt->format('u'), 0, 3);
    //                         return $dt->format('Y-m-d\TH:i:s') . '.' . $ms . 'Z';
    //                     })($event['createdAt']),
    //                 ]
    //             ];
    //             curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    //             $response = curl_exec($ch);
    //             $responseData = json_decode($response, true);

    //             $this->logger->info('CCTV download', ['event' => $event, 'request' => $postData, 'response' => $responseData]);
    //             echo json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    //         }
    //         curl_close($ch);
    //         return $postData;
    //     } catch (Exception $e) {
    //         $this->logger->error('Error fetching stored data', ['error' => $e->getMessage()]);
    //         return;
    //     }
    // }

    function downloadCCTVForEventsByIntegrationCode()
    {
        // Hasonló logika, de az IntegrationCode alapján szűrve. Minta body: {"LockerStationId":11404,"Uuid":"EXP-11404","IntegrationCode":"662165024996000013601078"}
        try {
            #1. Adatok betöltése
            $allData = $this->getStoredData();

            #2. szűrés
            $today = date('Y-m-d');
            $usernameFilter = ['consignee', 'Exp Hu Courier', 'cour.exohu'];
            $issueTypeFilter = [2, 3, 5, 6];
            $filteredData = [];

            foreach ($allData as $event) {
                if (
                    $event['createdAt'] >= $today
                    && in_array($event['username'], $usernameFilter)
                    && in_array($event['issueType'], $issueTypeFilter)
                ) {
                    $filteredData[] = $event;
                }
            }

            #3. CCTV file letöltése a fileterezett eseményekhez IntegrationCode alapján
            $ch = curl_init($this->losCreateImageRequestToLockerTest);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json'
            ]);
            foreach ($filteredData as &$event) {
                $lockerStationId = preg_replace('/^EXP-/', '', $event['lockerDisplayName']);
                $event['lockerStationId'] = $lockerStationId;
                $postData = [
                    'Uuid' => $event['uuid'],
                    'IntegrationCode' => $event['integrationCode'],
                    'LockerStationId' => $event['lockerStationId']
                ];
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                $response = curl_exec($ch);
                $responseData = json_decode($response, true);

                $this->logger->info('CCTV download by IntegrationCode', ['event' => $event, 'request' => $postData, 'response' => $responseData]);
            }
            curl_close($ch);
            return $postData;
        } catch (\Throwable $th) {
            $this->logger->error('Error fetching CCTV data by IntegrationCode', ['error' => $th->getMessage()]);
            return;
        }
    }
}
$downloader = new CCTVDownloader($conn, $losCreateImageRequestWithInterval, $losCreateImageRequestWithIntervalTest, $losCreateImageRequestToLocker, $losCreateImageRequestToLockerTest, $logger);
$downloader->downloadCCTVForEventsByIntegrationCode();
