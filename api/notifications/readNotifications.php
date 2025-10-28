<?php
header('Content-Type: application/json');
require('../../inc/conn.php');
require('../../api/user/auth/auth.php');

//debug ini
error_reporting(E_ALL);
ini_set('display_errors', 1);

$jsonData = file_get_contents("php://input");
$notificationId = json_decode($jsonData, true);

class NotificationService
{
  private $conn;
  private $auth;
  private $response;

  public function __construct($conn, $auth, &$response)
  {
    $this->conn = $conn;
    $this->auth = $auth;
    $this->response = &$response;
  }


  public function readNotifications($notificationId)
  {
    
    $isAccess = $this->auth->authenticate(11);
    if ($isAccess['status'] !== 200) {
      return $this->response = $isAccess;
    } else {
        // Prepare the SQL statement with placeholders
        $sql = "UPDATE notifications SET read_at = NOW() WHERE id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$notificationId]);

        $this->response = [
            'status' => 200,
            'message' => 'Notifications marked as read successfully.'
        ];
    }
  }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$notificationService = new NotificationService($conn, $auth, $response);

$unreadNotifications = $notificationService->readNotifications($notificationId);
echo json_encode($response);
