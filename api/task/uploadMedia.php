<?php
header('Content-Type: application/json');
require(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../../inc/conn.php');
require(__DIR__ . '/../../api/user/auth/auth.php');

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\Token\BearerTokenAuthorization;

$response = [];

$file = $_FILES['file'];
$locationId = $_POST['locationId'];

class uploadMedia
{
    private $conn;
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $response;
    private $auth;
    private $baseUrl;
    private $bucketName;
    private $folderName;

    public function __construct($conn, &$response, $auth, $endpoint, $accessKey, $secretKey, $baseUrl, $bucketName, $folderName)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->endpoint = $endpoint;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->baseUrl = $baseUrl;
        $this->bucketName = $bucketName;
        $this->folderName = $folderName;
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

    public function createClient(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region'  => 'auto',
            'endpoint' => $this->endpoint,
            'credentials' => [
                'key'    => $this->accessKey,
                'secret' => $this->secretKey,
            ],
        ]);
    }

    public function storePublicUrlInDb($url, $locationId, $filename, $createdBy)
    {
        try {
            $stmt = $this->conn->prepare("INSERT INTO task_location_photos (task_locations_id, url, filename, created_by) VALUES (:task_locations_id, :url, :filename, :created_by)");
            $stmt->bindParam(":task_locations_id", $locationId);
            $stmt->bindParam(":url", $url);
            $stmt->bindParam(":filename", $filename);
            $stmt->bindParam(":created_by", $createdBy);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function uploadFileToR2($file, $locationId)
    {
        //User jogosultság ellenőrzése
        $isUserAllowed = $this->isUserAllowed();
        if ($isUserAllowed['status'] !== 200) {
            return $this->response = $isUserAllowed;
        }

        // Felhasználó azonosító lekérése
        $createdBy = $isUserAllowed['data']->userId;

        // S3 kliens létrehozása
        $client = $this->createClient();

        // Fájl adatok
        $filename = time() . '_' . basename($file['name']);
        $mime = mime_content_type($file['tmp_name']);
        $folder = $this->folderName . $locationId . '/';

        // Feltöltés
        try {
            // Public URL létrehozása            
            $publicUrl = $this->baseUrl . $folder . $filename;

            // Public URL mentése az adatbázisba
            if ($this->storePublicUrlInDb($publicUrl, $locationId, $filename, $createdBy) !== true) {
                return $this->response = $this->createResponse(500, "Hiba történt az URL mentése során.", $this->storePublicUrlInDb($publicUrl, $locationId, $filename, $createdBy));
            } else {
                // Sikeres mentés
                $result = $client->putObject([
                    'Bucket' => $this->bucketName,
                    'Key'    => $folder . $filename,
                    'Body'   => fopen($file['tmp_name'], 'rb'),
                    'ContentType' => $mime,
                ]);
                return $this->response = $this->createResponse(200, "Fájl feltöltése sikeres.", ['photoUpload' => true, 'locationId' => intval($locationId), 'url' => $publicUrl]);
            }
        } catch (AwsException $e) {
            return $this->response = $this->createResponse(500, "Hiba történt a fájl feltöltése során: " . $e->getAwsErrorCode());
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);
$uploadMedia = new uploadMedia($conn, $response, $auth, $endpoint, $accessKey, $secretKey, $baseUrl, $bucketName, $folderName);
$uploadMedia->uploadFileToR2($file, $locationId);

echo json_encode($response);
