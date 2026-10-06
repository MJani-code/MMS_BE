<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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
     * Felhasználók (ID + email) lekérdezése szerepkör és cég alapján
     */
    private function getUsersByRoleIdAndCompanyId($roleId, $companyId)
    {
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.email
            FROM users u
            WHERE u.role_id = ? AND u.company_id = ? AND u.deleted = 0
        ");
        $stmt->execute([$roleId, $companyId]);
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


        $roleIds = [1, 2, 3];
        $usersByCompanyAndRole = [];
        // Címzettenként gyűjtött új feladatok az összesített emailhez
        $emailQueue = [];
        foreach ($rows as $row) {
            foreach ($roleIds as $roleId) {
                $cacheKey = $row['companyId'] . '_' . $roleId;
                // Felhasználók (ID + email) lekérdezése cégenként és rolehoz ha még nem történt meg
                if (!isset($usersByCompanyAndRole[$cacheKey])) {
                    $usersByCompanyAndRole[$cacheKey] = $this->getUsersByRoleIdAndCompanyId($roleId, $row['companyId']);
                }
                foreach ($usersByCompanyAndRole[$cacheKey] as $user) {
                    $msg = "Új feladat";
                    $isInserted = $this->insertIfNotExists($row['companyId'], $user['id'], $roleId, $row['taskId'], 'new_task', $msg);
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
        echo json_encode([$exists]);

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
}
