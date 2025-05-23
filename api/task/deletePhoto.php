<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$data = json_decode($jsonData, true);

$url = $data['url'];
$dbTable = 'task_location_photos';
$DOC_ROOT = DOC_ROOT;

class DeleteImage
{
    private $conn;
    private $response;
    private $auth;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    //TODO: user validation here

    public function deleteImageFunction($conn, $url, $DOC_ROOT)
    {
        $userId = null;
        $isAccess = $this->auth->authenticate(13);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }
        $result = deleteImage($conn, $url, $DOC_ROOT);
        $this->response = $result;
    }
}
$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$deleteImage = new DeleteImage($conn, $response, $auth);
$deleteImage->deleteImageFunction($conn, $url, $DOC_ROOT);
echo json_encode($response);
