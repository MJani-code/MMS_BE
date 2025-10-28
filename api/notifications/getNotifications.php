<?php
header('Content-Type: application/json');
require('../../inc/conn.php');
require('../../api/user/auth/auth.php');

//debug errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

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

  private function getNotificationTypeCount($notifications, $type)
  {
    return count(array_filter($notifications, fn($n) => $n['type'] === $type));
  }

  public function getUnreadNotifications($userId, $downloadedIds)
  {

    $isAccess = $this->auth->authenticate(11);
    if ($isAccess['status'] !== 200) {
      return $this->response = $isAccess;
    } else {
      //SQL n.created_at > ? feltétel csak akkor, ha a $since nem null
      if (!empty($downloadedIds)) {
        $downloadedIds = array_values(array_map('intval', $downloadedIds));
        $placeholders = implode(',', array_fill(0, count($downloadedIds), '?'));
        $sql = "
        SELECT n.id, n.type, n.message, tt.created_at as createdAt, GROUP_CONCAT(DISTINCT ttd.name ORDER BY tt.type_id ASC SEPARATOR ', ') AS typeNames, CONCAT(tl.city, ' ', tl.address) AS location
        FROM notifications n
        LEFT JOIN task_types tt on tt.task_id = n.task_id
        LEFT JOIN task_type_details ttd on ttd.id = tt.type_id
        LEFT JOIN tasks t on t.id = n.task_id
        LEFT JOIN task_locations tl on tl.id = t.task_locations_id
        WHERE n.user_id = ?
        AND n.read_at IS NULL
        AND tt.deleted = 0
        AND n.id NOT IN ($placeholders)
        GROUP BY n.id
        ORDER BY n.created_at DESC;
        ";
        $stmt = $this->conn->prepare($sql);
        $params = array_merge([$userId], $downloadedIds);
        $stmt->execute($params);
      } else {
        $stmt = $this->conn->prepare("
          SELECT n.id, n.type, n.message, tt.created_at as createdAt, GROUP_CONCAT(DISTINCT ttd.name ORDER BY tt.type_id ASC SEPARATOR ', ') AS typeNames, CONCAT(tl.city, ' ', tl.address) AS location
          FROM notifications n
          LEFT JOIN task_types tt on tt.task_id = n.task_id
          LEFT JOIN task_type_details ttd on ttd.id = tt.type_id
          LEFT JOIN tasks t on t.id = n.task_id
          LEFT JOIN task_locations tl on tl.id = t.task_locations_id
          WHERE n.user_id = ?
          AND n.read_at IS NULL
          AND tt.deleted = 0
          GROUP BY n.id
          ORDER BY n.created_at DESC;
        ");
        $stmt->execute([$userId]);
      }
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $this->response = [
        'status' => 200,
        'rowCount' => $stmt->rowCount(),
        'newTaskCount' => $this->getNotificationTypeCount($rows, 'new_task'),
        'data' => $rows,
        'since' => $downloadedIds
      ];
    }
  }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$notificationService = new NotificationService($conn, $auth, $response);

$userId = $_GET['userId'];
$downloadedIds = isset($_GET['downloadedIds'])
  ? explode(',', $_GET['downloadedIds'])
  : [];

$downloadedIds = array_filter(array_map('intval', $downloadedIds));
$unreadNotifications = $notificationService->getUnreadNotifications($userId, $downloadedIds);
echo json_encode($response);
