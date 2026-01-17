<?php
header('Content-Type: application/json');
require(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../../inc/conn.php');
require(__DIR__ . '/../../api/user/auth/auth.php');


use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\Token\BearerTokenAuthorization;

$response = [];

$jsonData = file_get_contents("php://input");
$data = json_decode($jsonData, true);

$url = $data['url'];

class deleteMedia
{
    private $conn;
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $zoneId;
    private $cachePurgeToken;
    private $response;
    private $auth;
    private $baseUrl;
    private $bucketName;

    public function __construct($conn, &$response, $auth, $endpoint, $accessKey, $secretKey, $zoneId, $cachePurgeToken, $baseUrl, $bucketName)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->endpoint = $endpoint;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->zoneId = $zoneId;
        $this->cachePurgeToken = $cachePurgeToken;
        $this->baseUrl = $baseUrl;
        $this->bucketName = $bucketName;
    }

    private function createResponse($status, $message, $data = null)
    {
        return [
            'status' => $status,
            'message' => $message,
            'payload' => $data,
        ];
    }

    public function isUserAllowed()
    {
        return $this->auth->authenticate(13);
    }

    public function isUrlExistInDb($url)
    {
        try {
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM task_location_photos WHERE url = :url");
            $stmt->bindParam(":url", $url);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                return $this->createResponse(200, "URL létezik az adatbázisban.");
            } else {
                return $this->createResponse(404, "URL nem létezik az adatbázisban.");
            }
        } catch (Exception $e) {
            return $this->createResponse(500, "Hiba történt az adatbázis lekérdezés során: " . $e->getMessage());
        }
    }

    function purgeCloudflareCache(string $zoneId, string $apiToken, string $fileUrl)
    {
        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";

        $payload = json_encode([
            'files' => [$fileUrl],
        ]);

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decodedResponse = json_decode($response, true);
        return $decodedResponse;
    }

    function getTaskLocationIdFromDb($url)
    {
        try {
            $stmt = $this->conn->prepare("SELECT task_locations_id FROM task_location_photos WHERE url = :url");
            $stmt->bindParam(":url", $url);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result['task_locations_id'];
            } else {
                return null;
            }
        } catch (Exception $e) {
            return null;
        }
    }

    function removeUrlFromDb($url, $deletedBy)
    {
        try {
            $stmt = $this->conn->prepare("UPDATE task_location_photos SET deleted = 1, deleted_at = NOW(), deleted_by = :deletedBy WHERE url = :url");
            $stmt->bindParam(":url", $url);
            $stmt->bindParam(":deletedBy", $deletedBy);
            $stmt->execute();
            return $this->createResponse(200, "URL sikeresen törölve az adatbázisból.");
        } catch (Exception $e) {
            return $this->createResponse(500, "Hiba történt az adatbázis frissítése során: " . $e->getMessage());
        }
    }

    public function createClient(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region'  => 'auto',
            'endpoint' => $this->endpoint,
            'credentials' => [
                'key'    => $this->accessKey,
                // 'key'    => 'test',
                'secret' => $this->secretKey,
            ],
        ]);
    }

    function deleteFileFromR2($bucketName, $url)
    {
        //User jogosultság ellenőrzése
        $isUserAllowed = $this->isUserAllowed();
        if ($isUserAllowed['status'] !== 200) {
            return $this->response = $isUserAllowed;
        }

        //URL létezés ellenőrzése az adatbázisban
        $isUrlExist = $this->isUrlExistInDb($url);
        if ($isUrlExist['status'] !== 200) {
            return $this->response = $isUrlExist;
        }

        //Felhasználó azonosító lekérése
        $deletedBy = $isUserAllowed['data']->userId;

        //S3 kliens létrehozása
        $client = $this->createClient();

        //Fájl törlése az R2-ből
        try {
            //fileUrl kinyerése az $url változóból
            $fileUrl = str_replace($this->baseUrl, '', $url);

            // Fájl törlése
            $result = $client->deleteObject([
                'Bucket' => $bucketName,
                'Key'    => $fileUrl,
            ]);

            // Cache törlése Cloudflare-ben
            $isCachePurged = $this->purgeCloudflareCache($this->zoneId, $this->cachePurgeToken, $url);
            if (isset($isCachePurged['success']) && $isCachePurged['success'] === false) {
                return $this->response = $this->createResponse(500, "A fájl törlése sikeres volt, de a cache törlése sikertelen: ", $isCachePurged);
            }

            // Fájl törlése az adatbázisból
            $isRemovedFromDb = $this->removeUrlFromDb($url, $deletedBy);
            if ($isRemovedFromDb['status'] !== 200) {
                return $this->response = $isRemovedFromDb;
            }

            return $this->response = $this->createResponse(200, "Fájl törlése sikeres.", ['url' => $url, 'taskLocationsId' => $this->getTaskLocationIdFromDb($url)]);
        } catch (AwsException $e) {
            return $this->response = $this->createResponse(500, "Hiba történt a fájl törlése során: " . $e->getAwsErrorMessage());
        }
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$deleteMedia = new deleteMedia($conn, $response, $auth, $endpoint, $accessKey, $secretKey, $zoneId, $cachePurgeToken, $baseUrl, $bucketName);
$deleteMedia->deleteFileFromR2($bucketName, $url);

echo json_encode($response);
