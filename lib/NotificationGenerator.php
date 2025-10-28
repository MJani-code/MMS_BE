<?php
class NotificationGenerator
{
    private $pdo;
    private $now;
    private $tenMinutesAgo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->now = date('Y-m-d H:i:s');
        $this->tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    }

    /**
     * Fő belépési pont – minden értesítés generálása
     */
    public function generateAll()
    {
        // echo "==== Notification generation started at {$this->now} ====\n";
        $this->generateNewTasks();

        // Find all relevant companyId and taskId pairs to check for deletion
        $stmt = $this->pdo->prepare("
            SELECT tr.company_id, tr.task_id
            FROM task_responsibles tr
            WHERE tr.deleted = 1
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $this->deleteIfExists($row['company_id'], $row['task_id']);
        }

        // echo "==== Finished at " . date('Y-m-d H:i:s') . " ====\n\n";
    }

    /**
     * Felhasználói ID-k lekérdezése szerepkör és cég alapján
     */
    private function getUserIdsByRoleIdAndCompanyId($roleId, $companyId)
    {
        $stmt = $this->pdo->prepare("
            SELECT u.id
            FROM users u
            WHERE u.role_id = ? AND u.company_id = ?
        ");
        $stmt->execute([$roleId, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Új feladatok – company_id és task_id szerint
     */
    private function generateNewTasks()
    {
        $stmt = $this->pdo->prepare("
            SELECT t.id as taskId, tr.company_id as companyId
            FROM tasks t
            JOIN task_responsibles tr ON tr.task_id = t.id AND tr.deleted = 0
            WHERE tr.created_at >= ?
        ");
        $stmt->execute([$this->tenMinutesAgo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


        $userIds = [];
        foreach ($rows as $row) {
            // Felhasználói ID-k 3-as rolehoz lekérdezése ha még nem történt meg
            if (empty($userIds)) {
                echo "Felhasználói ID-k lekérdezése: ";
                $userIds = $this->getUserIdsByRoleIdAndCompanyId(3, $row['companyId']);
                echo implode(", ", $userIds);
            }
            foreach ($userIds as $userId) {
                $msg = "Új feladat";
                $this->insertIfNotExists($row['companyId'], $userId, 3, $row['taskId'], 'new_task', $msg);
            }
        }
    }

    /**
     * Beszúrás csak ha még nincs ilyen értesítés
     */
    private function insertIfNotExists($companyId, $userId, $roleId, $taskId, $type, $message)
    {
        $check = $this->pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE type = ? AND task_id = ? AND company_id = ?
        ");
        $check->execute([$type, $taskId, $companyId]);
        $exists = $check->fetchColumn();

        if (!$exists) {
            $insert = $this->pdo->prepare("
                INSERT INTO notifications (company_id, user_id, role_id, task_id, type, message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert->execute([$companyId, $userId, $roleId, $taskId, $type, $message]);
        }
    }

    /**
     * Sor törlése ha a companyId deleted = 1 van a task_responsibles táblába
     */
    private function deleteIfExists($companyId, $taskId)
    {
        $check = $this->pdo->prepare("
            SELECT COUNT(*) FROM task_responsibles tr
            WHERE tr.deleted = 1 AND tr.company_id = ? AND tr.task_id = ?
        ");
        $check->execute([$companyId, $taskId]);
        $exists = $check->fetchColumn();

        if ($exists) {
            $delete = $this->pdo->prepare("
                DELETE FROM notifications
                WHERE company_id = ? AND task_id = ?
            ");
            $delete->execute([$companyId, $taskId]);
        }
    }
}
