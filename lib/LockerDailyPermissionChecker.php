<?php
require('../functions/taskFunctions.php');

class LockerDailyPermissionChecker
{
    private $conn;
    private $getAllActivePointsUrl;
    private $user;
    private $password;
    private $token;

    public function __construct($conn, $getAllActivePointsUrl, $user, $password, $token)
    {
        $this->conn = $conn;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
        $this->token = $token;
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
            return $errorInfo;
        }

        // Explicitly use $this->token to access the token
        if ($tokenFromDb !== $this->token) {
            return false;
        } else {
            return true;
        }
    }

    public function getLockerAvailabilityData()
    {
        // access validation
        if (!$this->isUserAuthorized()) {
            // return ['error' => 'Unauthorized'];
            echo 'Unauthorized';
            return;
        }

        $data = getExoboxPoints($this->getAllActivePointsUrl, $this->user, $this->password, null);
        $lockerData = $data['payload'] ?? [];

        // Store the data in the database
        $this->storeData($lockerData);

        return $lockerData;
    }

    public function storeData($data)
    {
        $stmt = $this->conn->prepare("INSERT INTO locker_daily_permission (tof_shop_id, day, is_enabled, collected_at, source) VALUES (?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), collected_at = NOW(), source = VALUES(source)");
        foreach ($data as $locker) {
            $stmt->execute([$locker['id'], date('Y-m-d'), $locker['status'] ? 1 : 0, 'LockerDailyPermissionChecker']);
        }
    }
}