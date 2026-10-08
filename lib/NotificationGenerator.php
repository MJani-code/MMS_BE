<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/StatusChangeEmailTemplate.php';
require_once __DIR__ . '/BatchStatusChangeEmailTemplate.php';

class NotificationGenerator
{
    private $pdo;
    private $now;
    private $tenMinutesAgo;

    private $smtpHost;
    private $smtpPort;
    private $smtpEncryption;
    private $smtpUsername;
    private $smtpPassword;
    private $smtpFromEmail;
    private $smtpFromName;

    public function __construct(
        PDO $pdo,
        $smtpHost = '',
        $smtpPort = 587,
        $smtpEncryption = 'tls',
        $smtpUsername = '',
        $smtpPassword = '',
        $smtpFromEmail = '',
        $smtpFromName = 'MMS Értesítések'
    ) {
        $this->pdo = $pdo;
        $this->now = date('Y-m-d H:i:s');
        $this->tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));

        $this->smtpHost = $smtpHost;
        $this->smtpPort = $smtpPort;
        $this->smtpEncryption = $smtpEncryption;
        $this->smtpUsername = $smtpUsername;
        $this->smtpPassword = $smtpPassword;
        $this->smtpFromEmail = $smtpFromEmail;
        $this->smtpFromName = $smtpFromName;
    }

    /**
     * Fő belépési pont – minden értesítés generálása
     */
    public function generateAll()
    {
        // echo "==== Notification generation started at {$this->now} ====\n";


        $this->deleteNotificationsOfRemovedResponsibles();

        $this->generateNewTasks();
        // echo "==== Finished at " . date('Y-m-d H:i:s') . " ====\n\n";
    }

    /**
     * Feliratkozott felhasználók lekérdezése cég és értesítéstípus alapján
     */
    private function getSubscribedUsersByCompanyId($companyId, $subscriptionName)
    {
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.email, u.role_id
            FROM users u
            LEFT JOIN notification_subscriptions ns ON ns.user_id = u.id AND ns.notification_name = ?
            WHERE u.company_id = ? AND u.deleted = 0 AND ns.id IS NOT NULL AND ns.is_subscribed = 1
        ");
        $stmt->execute([$subscriptionName, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Új feladatok – company_id és task_id szerint
     */
    private function generateNewTasks()
    {
        $stmt = $this->pdo->prepare("
            SELECT t.id as taskId, tr.company_id as companyId, tl.name as locationName,
                   GROUP_CONCAT(DISTINCT ttd.name ORDER BY tt.type_id ASC SEPARATOR ', ') as taskTypeNames,
                   tli.description as lockerIssueDescription,
                   tli.compartment_number as lockerIssueCompartmentNumber
            FROM tasks t
            JOIN task_responsibles tr ON tr.task_id = t.id AND tr.deleted = 0
            LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
            LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
            LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
            LEFT JOIN task_lockers_issues tli ON tli.task_id = t.id AND tli.is_solved = 0
            WHERE GREATEST(tr.created_at, COALESCE(tr.updated_at, tr.created_at)) >= ?
            GROUP BY t.id, tr.company_id, tl.name
        ");
        $stmt->execute([$this->tenMinutesAgo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


        $usersByCompany = [];
        // Címzettenként gyűjtött új feladatok az összesített emailhez
        $emailQueue = [];
        foreach ($rows as $row) {
            $companyId = $row['companyId'];
            if (!isset($usersByCompany[$companyId])) {
                $usersByCompany[$companyId] = $this->getSubscribedUsersByCompanyId($companyId, 'sendNewTasksEmail');
            }
            foreach ($usersByCompany[$companyId] as $user) {
                $msg = "Új feladat";
                $isInserted = $this->insertIfNotExists($companyId, $user['id'], $user['role_id'], $row['taskId'], 'new_task', $msg);
                if ($isInserted && !empty($user['email'])) {
                    $emailQueue[$user['email']][$row['taskId']] = [
                        'taskId' => $row['taskId'],
                        'locationName' => $row['locationName'],
                        'taskTypeNames' => $row['taskTypeNames'],
                        'lockerIssueDescription' => $row['lockerIssueDescription'],
                        'lockerIssueCompartmentNumber' => $row['lockerIssueCompartmentNumber'],
                    ];
                }
            }
        }

        foreach ($emailQueue as $email => $tasks) {
            $this->sendNewTasksEmail($email, array_values($tasks));
        }
    }

    /**
     * Beszúrás csak ha még nincs ilyen értesítés. Igazzal tér vissza, ha új bejegyzés jött létre
     */
    private function insertIfNotExists($companyId, $userId, $roleId, $taskId, $type, $message)
    {
        $check = $this->pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE type = ? AND task_id = ? AND company_id = ? AND role_id = ? AND user_id = ?
        ");
        $check->execute([$type, $taskId, $companyId, $roleId, $userId]);
        $exists = $check->fetchColumn();

        if (!$exists) {
            $insert = $this->pdo->prepare("
                INSERT INTO notifications (company_id, user_id, role_id, task_id, type, message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert->execute([$companyId, $userId, $roleId, $taskId, $type, $message]);
            return true;
        }

        return false;
    }

    /**
     * Összesített email küldése az új feladatokról (egy email címzettenként)
     */
    private function sendNewTasksEmail($email, array $tasks)
    {
        $subject = count($tasks) > 1 ? "Új feladatok (" . count($tasks) . ")" : "Új feladat";
        $body = "<p>Szia! <br><br> Az alábbi új feladatok jöttek létre:</p><ul>";
        foreach ($tasks as $task) {
            $item = "Feladat azonosító: #" . (int)$task['taskId'];
            if (!empty($task['taskTypeNames'])) {
                $item .= " – Feladattípus: " . htmlspecialchars($task['taskTypeNames'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (!empty($task['locationName'])) {
                $item .= " – Helyszín: " . htmlspecialchars($task['locationName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (!empty($task['lockerIssueDescription'])) {
                $item .= " – Szekrény probléma: " . htmlspecialchars($task['lockerIssueDescription'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (!empty($task['lockerIssueCompartmentNumber'])) {
                $item .= " – Szekrény rekesz száma: " . htmlspecialchars($task['lockerIssueCompartmentNumber'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $body .= "<li>{$item}</li>";
        }
        $body .= "</ul>";

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->smtpHost;
            $mailer->Port = $this->smtpPort;
            $mailer->SMTPAuth = !empty($this->smtpUsername);
            $mailer->Username = $this->smtpUsername;
            $mailer->Password = $this->smtpPassword;
            $mailer->SMTPSecure = $this->smtpEncryption;
            $mailer->CharSet = 'UTF-8';

            $mailer->setFrom($this->smtpFromEmail, $this->smtpFromName);
            $mailer->addAddress($email);

            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->isHTML(true);

            $mailer->send();
        } catch (PHPMailerException $e) {
            error_log('NotificationGenerator email küldési hiba: ' . $mailer->ErrorInfo);
        }
    }

    /**
     * Törli azokat az új feladat értesítéseket, amelyeknél a cég már nem aktív felelőse a feladatnak
     */
    private function deleteNotificationsOfRemovedResponsibles()
    {
        $delete = $this->pdo->prepare("
            DELETE n FROM notifications n
            WHERE n.type = 'new_task'
            AND NOT EXISTS (
                SELECT 1 FROM task_responsibles tr
                WHERE tr.task_id = n.task_id AND tr.company_id = n.company_id AND tr.deleted = 0
            )
        ");
        $delete->execute();
    }

    public function getTaskDetails($taskId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    t.id AS taskId,
                    ts.name AS statusName,
                    CONCAT(tl.name, ' - ', tl.city, ', ', tl.address) AS locationName,
                    tl.box_id AS boxId,
                    tl.tof_shop_id as tofShopId,
                    GROUP_CONCAT(DISTINCT ttd.name ORDER BY tt.type_id ASC SEPARATOR ', ') as taskTypeNames,
                    tli.notes AS lockerInterventionNotes
                FROM tasks t
                LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
                LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
                LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
                LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
                LEFT JOIN task_lockers_interventions tli ON tli.task_id = t.id
                WHERE t.id = ?
                GROUP BY t.id, ts.name, tl.name, tl.city, tl.address, tl.box_id, tl.tof_shop_id, tli.notes
            ");
            $stmt->execute([$taskId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('Failed to get task details for task ID: ' . $taskId . ' - ' . $e->getMessage());
            return false;
        }
    }

    private function getBatchTaskDetails(array $taskIds)
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        if (empty($taskIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT
                t.id AS taskId,
                ts.name AS statusName,
                CONCAT(tl.name, ' - ', tl.city, ', ', tl.address) AS locationName,
                tl.box_id AS boxId,
                tl.tof_shop_id as tofShopId,
                GROUP_CONCAT(DISTINCT ttd.name ORDER BY tt.type_id ASC SEPARATOR ', ') AS taskTypeNames,
                tli.notes AS lockerInterventionNotes
            FROM tasks t
            LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
            LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
            LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
            LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
            LEFT JOIN task_lockers_interventions tli ON tli.task_id = t.id
            WHERE t.id IN ($placeholders)
            GROUP BY t.id, ts.name, tl.name, tl.city, tl.address, tl.box_id, tl.tof_shop_id, tli.notes
            ORDER BY t.id
        ");
        $stmt->execute($taskIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sendBatchStatusChangeEmail($companyId, $subject, $body, $statusName, array $taskIds)
    {
        $tasks = $this->getBatchTaskDetails($taskIds);
        if (empty($tasks)) {
            error_log('Cannot send batch status change email: no task details found.');
            return false;
        }

        $emails = [];
        foreach ($this->getSubscribedUsersByCompanyId($companyId, 'sendBatchStatusChangeEmail') as $user) {
            if (!empty($user['email'])) {
                $emails[] = $user['email'];
            }
        }
        $emails = array_values(array_unique($emails));
        if (empty($emails)) {
            error_log('Cannot send batch status change email: no recipients found for company ID ' . $companyId);
            return false;
        }

        $emailSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject))
            . ' – ' . count($tasks) . ' feladat';
        $htmlBody = BatchStatusChangeEmailTemplate::render($subject, $body, $statusName, $tasks);
        $plainBody = $body . "\n\nÚj státusz: " . $statusName . "\nÉrintett feladatok:\n";
        foreach ($tasks as $task) {
            $plainBody .= '#' . $task['taskId'] . ' – '
                . ($task['locationName'] ?: 'Helyszín nincs megadva') . ' – '
                . ($task['taskTypeNames'] ?: 'Feladattípus nincs megadva') . "\n"
                . ($task['boxId'] ? ' - Box ID: ' . $task['boxId'] : '') . ' – '
                . ($task['tofShopId'] ? 'TOF Shop ID: ' . $task['tofShopId'] : '') . ' – '
                . ($task['statusName'] ?: 'Státusz nincs megadva') . "\n"
                . ($task['lockerInterventionNotes'] ?: 'Nincs rögzített beavatkozás') . "\n";
        }

        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $this->smtpHost;
            $mailer->Port = $this->smtpPort;
            $mailer->SMTPAuth = !empty($this->smtpUsername);
            $mailer->Username = $this->smtpUsername;
            $mailer->Password = $this->smtpPassword;
            $mailer->SMTPSecure = $this->smtpEncryption;
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($this->smtpFromEmail, $this->smtpFromName);

            foreach ($emails as $email) {
                $mailer->addAddress($email);
            }

            $mailer->Subject = $emailSubject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $plainBody;
            $mailer->isHTML(true);
            $mailer->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('NotificationGenerator batch email küldési hiba: ' . $mailer->ErrorInfo);
            return false;
        }
    }

    /**
     * Reszponzív HTML email küldése a státuszváltozásról
     */
    public function sendStatusChangeEmail($companyId, $subject, $body, $payload)
    {
        $taskId = $payload['id'] ?? null;
        if (!$taskId) {
            error_log('Cannot send status change email without a task ID.');
            return false;
        }

        $taskDetails = $this->getTaskDetails($taskId);
        if (!$taskDetails) {
            error_log('Cannot send status change email: task not found for ID ' . $taskId);
            return false;
        }

        $emails = [];
        foreach ($this->getSubscribedUsersByCompanyId($companyId, 'sendStatusChangeEmail') as $user) {
            $emails[] = $user['email'];
        }

        $emails = array_values(array_unique(array_filter($emails)));
        if (empty($emails)) {
            error_log('Cannot send status change email: no recipients found for company ID ' . $companyId);
            return false;
        }

        $emailSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $emailSubject .= ' – ' . $taskDetails['statusName'] . ' (#' . $taskDetails['taskId'] . ')';

        $plainBody = $body . "\n\nFeladat azonosítója: #" . $taskDetails['taskId']
            . "\nHelyszín: " . ($taskDetails['locationName'] ?: 'Nincs megadva')
            . "\nFeladat típusa(i): " . ($taskDetails['taskTypeNames'] ?: 'Nincs megadva')
            . "\nBoxId: " . ($taskDetails['boxId'] ?: 'Nincs megadva')
            . "\nTof Shop ID: " . ($taskDetails['tofShopId'] ?: 'Nincs megadva')
            . "\nÚj státusz: " . ($taskDetails['statusName'] ?: 'Nincs megadva')
            . "\nLocker Intervention Notes: " . ($taskDetails['lockerInterventionNotes'] ?: 'Nincs megadva');
        $htmlBody = StatusChangeEmailTemplate::render($subject, $body, $taskDetails);

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->smtpHost;
            $mailer->Port = $this->smtpPort;
            $mailer->SMTPAuth = !empty($this->smtpUsername);
            $mailer->Username = $this->smtpUsername;
            $mailer->Password = $this->smtpPassword;
            $mailer->SMTPSecure = $this->smtpEncryption;
            $mailer->CharSet = 'UTF-8';

            $mailer->setFrom($this->smtpFromEmail, $this->smtpFromName);
            foreach ($emails as $email) {
                $mailer->addAddress($email);
            }

            $mailer->Subject = $emailSubject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $plainBody;
            $mailer->isHTML(true);

            $mailer->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('NotificationGenerator email küldési hiba: ' . $mailer->ErrorInfo);
            return false;
        }
    }
}
