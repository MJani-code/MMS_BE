<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Processor\IntrospectionProcessor;

require_once __DIR__ . '/StatusChangeEmailTemplate.php';
require_once __DIR__ . '/BatchStatusChangeEmailTemplate.php';
require_once __DIR__ . '/NewTasksEmailTemplate.php';

// Initialize Monolog logger
$logger = new Logger('NotificationGenerator');
$logger->pushHandler(new RotatingFileHandler(__DIR__ . '/logs/notification_generator.log', 5));
// A naplózást végző osztályt, függvényt és sort az "extra" mezőbe teszi
$logger->pushProcessor(new IntrospectionProcessor(Level::Debug, ['PHPMailer\\']));

class NotificationGenerator
{
    private $pdo;
    private $now;
    private $tenMinutesAgo;
    private $logger;

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

        global $logger;
        $this->logger = $logger;
    }

    /**
     * Fő belépési pont – minden értesítés generálása
     */
    public function generateAll()
    {
        $this->logger->info('Notification generation started', ['startedAt' => $this->now]);

        try {
            $this->deleteNotificationsOfRemovedResponsibles();
            $this->generateNewTasks();
            $this->logger->info('Notification generation completed');
        } catch (\Throwable $e) {
            $this->logger->error('Notification generation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Feliratkozott felhasználók lekérdezése cég és értesítéstípus alapján
     */
    private function getSubscribedUsersByCompanyId($companyId, $subscriptionName = array(), $anyCompanySubscriptionName = null)
    {
        try {
            $subscriptionNames = array_values(array_filter((array) $subscriptionName));
            $params = [];

            // Cégfüggetlen feliratkozók mindig bekerülnek, a cégesek csak a megbízott cégből
            $conditions = [];
            if (!empty($subscriptionNames)) {
                $placeholders = implode(',', array_fill(0, count($subscriptionNames), '?'));
                $conditions[] = "(u.company_id = ? AND ns.notification_name IN ($placeholders))";
                $params[] = $companyId;
                $params = array_merge($params, $subscriptionNames);
            }
            if (!empty($anyCompanySubscriptionName)) {
                $conditions[] = "ns.notification_name = ?";
                $params[] = $anyCompanySubscriptionName;
            }
            if (empty($conditions)) {
                return [];
            }

            $stmt = $this->pdo->prepare("
                SELECT DISTINCT u.id, u.email, u.role_id
                FROM users u
                JOIN notification_subscriptions ns ON ns.user_id = u.id AND ns.is_subscribed = 1
                WHERE u.deleted = 0 AND (" . implode(' OR ', $conditions) . ")
            ");
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->logger->info('Subscribed users fetched', [
                'companyId' => $companyId,
                'subscriptionName' => $subscriptionName,
                'count' => count($result),
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching subscribed users', [
                'companyId' => $companyId,
                'subscriptionName' => $subscriptionName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
                   tli.compartment_number as lockerIssueCompartmentNumber,
                   tr.company_id as responsibleCompanyId
            FROM tasks t
            JOIN task_responsibles tr ON tr.id = (SELECT MAX(trm.id) FROM task_responsibles trm WHERE trm.task_id = t.id AND trm.deleted = 0)
            LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
            LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
            LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
            LEFT JOIN task_lockers_issues tli ON tli.task_id = t.id AND tli.is_solved = 0
            WHERE GREATEST(tr.created_at, COALESCE(tr.updated_at, tr.created_at)) >= ?
            GROUP BY t.id, tr.company_id, tl.name
        ");
        $stmt->execute([$this->tenMinutesAgo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->logger->info('New task notification candidates fetched', [
            'since' => $this->tenMinutesAgo,
            'count' => count($rows),
        ]);

        $usersByCompany = [];
        // Címzettenként gyűjtött új feladatok az összesített emailhez
        $emailQueue = [];
        $companyId = null;
        foreach ($rows as $row) {
            $companyId = $row['responsibleCompanyId'];
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
            $this->logger->info('Processing email queue for new tasks', [
                'emailQueue' => $emailQueue
            ]);
            $this->sendNewTasksEmail($email, array_values($tasks));
        }
        $this->logger->info('New task notifications processed', [
            'notificationCandidates' => count($rows),
            'emailCount' => count($emailQueue),
        ]);
    }

    /**
     * Beszúrás csak ha még nincs ilyen értesítés. Igazzal tér vissza, ha új bejegyzés jött létre
     */
    private function insertIfNotExists($companyId, $userId, $roleId, $taskId, $type, $message)
    {
        try {
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
                $this->logger->info('Notification created', [
                    'companyId' => $companyId,
                    'userId' => $userId,
                    'taskId' => $taskId,
                    'type' => $type,
                ]);
                return true;
            }

            $this->logger->debug('Duplicate notification skipped', [
                'companyId' => $companyId,
                'userId' => $userId,
                'taskId' => $taskId,
                'type' => $type,
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Error creating notification', [
                'companyId' => $companyId,
                'userId' => $userId,
                'taskId' => $taskId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Összesített email küldése az új feladatokról (egy email címzettenként)
     */
    private function sendNewTasksEmail($email, array $tasks)
    {
        $subject = count($tasks) > 1 ? "Új feladatok (" . count($tasks) . ")" : "Új feladat";
        $plainBody = "Az alábbi új feladatok jöttek létre:\n\n";
        foreach ($tasks as $task) {
            $plainBody .= "Feladat azonosító: #" . ($task['taskId'] ?? '')
                . "\nHelyszín: " . ($task['locationName'] ?: 'Helyszín nincs megadva')
                . "\nFeladattípus: " . ($task['taskTypeNames'] ?: 'Feladattípus nincs megadva')
                . "\nSzekrény probléma: " . ($task['lockerIssueDescription'] ?: 'Nincs megadva')
                . "\nRekesz száma: " . ($task['lockerIssueCompartmentNumber'] ?: 'Nincs megadva')
                . "\n\n";
        }
        $htmlBody = NewTasksEmailTemplate::render($subject, $tasks);

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
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $plainBody;
            $mailer->isHTML(true);

            $mailer->send();
            $this->logger->info('New task email sent', [
                'taskCount' => count($tasks),
            ]);
        } catch (PHPMailerException $e) {
            $this->logger->error('Failed to send new task email', [
                'taskCount' => count($tasks),
                'error' => $mailer->ErrorInfo ?: $e->getMessage(),
            ]);
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
                WHERE tr.company_id = n.company_id
                AND tr.id = (SELECT MAX(trm.id) FROM task_responsibles trm WHERE trm.task_id = n.task_id AND trm.deleted = 0)
            )
        ");
        $delete->execute();
        $this->logger->info('Removed responsible notifications deleted', [
            'count' => $delete->rowCount(),
        ]);
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
                    tli.notes AS lockerInterventionNotes,
                    tr.company_id AS responsibleCompanyId
                FROM tasks t
                LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
                LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
                LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
                LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
                LEFT JOIN task_lockers_interventions tli ON tli.task_id = t.id
                LEFT JOIN task_responsibles tr ON tr.id = (SELECT MAX(trm.id) FROM task_responsibles trm WHERE trm.task_id = t.id AND trm.deleted = 0)
                WHERE t.id = ?
                GROUP BY t.id, ts.name, tl.name, tl.city, tl.address, tl.box_id, tl.tof_shop_id, tli.notes, tr.company_id
            ");
            $stmt->execute([$taskId]);
            $taskDetails = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$taskDetails) {
                $this->logger->warning('Task details not found', ['taskId' => $taskId]);
                return false;
            }
            $this->logger->info('Task details fetched', ['taskId' => $taskId, 'taskDetails' => $taskDetails]);
            return $taskDetails;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get task details', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getBatchTaskDetails(array $taskIds)
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        if (empty($taskIds)) {
            $this->logger->warning('Batch task details requested without task IDs');
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $stmt = $this->pdo->prepare("
                SELECT
                    t.id AS taskId,
                    ts.name AS statusName,
                    CONCAT(tl.name, ' - ', tl.city, ', ', tl.address) AS locationName,
                    tl.box_id AS boxId,
                    tl.tof_shop_id as tofShopId,
                    GROUP_CONCAT(DISTINCT ttd.name ORDER BY tt.type_id ASC SEPARATOR ', ') AS taskTypeNames,
                    tli.notes AS lockerInterventionNotes,
                    tr.company_id AS responsibleCompanyId
                FROM tasks t
                LEFT JOIN task_statuses ts ON ts.id = t.status_by_exohu_id
                LEFT JOIN task_locations tl ON tl.id = t.task_locations_id
                LEFT JOIN task_types tt ON tt.task_id = t.id AND tt.deleted = 0
                LEFT JOIN task_type_details ttd ON ttd.id = tt.type_id
                LEFT JOIN task_lockers_interventions tli ON tli.task_id = t.id
                LEFT JOIN task_responsibles tr ON tr.id = (SELECT MAX(trm.id) FROM task_responsibles trm WHERE trm.task_id = t.id AND trm.deleted = 0)
                WHERE t.id IN ($placeholders)
                GROUP BY t.id, ts.name, tl.name, tl.city, tl.address, tl.box_id, tl.tof_shop_id, tli.notes
                ORDER BY t.id
            ");
            $stmt->execute($taskIds);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->logger->info('Batch task details fetched', [
                'requestedCount' => count($taskIds),
                'fetchedCount' => count($tasks),
            ]);
            return $tasks;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch batch task details', [
                'taskIds' => $taskIds,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function sendBatchStatusChangeEmail($subject, $body, $statusName, array $taskIds)
    {
        $tasks = $this->getBatchTaskDetails($taskIds);
        if (empty($tasks)) {
            $this->logger->warning('Cannot send batch status change email: no task details found', [
                'taskIds' => $taskIds,
            ]);
            return false;
        }

        // Címzett => csak a neki járó feladatok (saját cég + cégfüggetlen feliratkozás)
        $tasksByEmail = [];
        foreach ($tasks as $task) {
            $companyId = $task['responsibleCompanyId'] ?? null;
            foreach ($this->getSubscribedUsersByCompanyId($companyId, 'sendBatchStatusChangeEmail', 'getBatchStatusChangeEmailFromAnyCompany') as $user) {
                if (!empty($user['email'])) {
                    $tasksByEmail[$user['email']][$task['taskId']] = $task;
                }
            }
        }
        $this->logger->info('Prepared recipient list for batch status change email', [
            'emails' => array_keys($tasksByEmail),
            'tasksByRecipient' => array_map('array_keys', $tasksByEmail),
        ]);

        if (empty($tasksByEmail)) {
            $this->logger->warning('Cannot send batch status change email: no recipients found', [
                'taskIds' => $taskIds,
            ]);
            return false;
        }

        $allSent = true;
        foreach ($tasksByEmail as $email => $recipientTasks) {
            $recipientTasks = array_values($recipientTasks);
            if (!$this->sendBatchStatusChangeEmailToRecipient($email, $subject, $body, $statusName, $recipientTasks)) {
                $allSent = false;
            }
        }
        return $allSent;
    }

    private function sendBatchStatusChangeEmailToRecipient($email, $subject, $body, $statusName, array $tasks)
    {
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
            $mailer->addAddress($email);

            $mailer->Subject = $emailSubject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $plainBody;
            $mailer->isHTML(true);
            $mailer->send();
            $this->logger->info('Batch status change email sent', [
                'recipient' => $email,
                'taskCount' => count($tasks),
            ]);
            return true;
        } catch (PHPMailerException $e) {
            $this->logger->error('Failed to send batch status change email', [
                'recipient' => $email,
                'taskCount' => count($tasks),
                'error' => $mailer->ErrorInfo ?: $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reszponzív HTML email küldése a státuszváltozásról
     */
    public function sendStatusChangeEmail($subject, $body, $payload)
    {
        $taskId = $payload['id'] ?? null;
        if (!$taskId) {
            $this->logger->warning('Cannot send status change email without a task ID');
            return false;
        }

        $taskDetails = $this->getTaskDetails($taskId);
        $companyId = $taskDetails['responsibleCompanyId'] ?? null;
        if (!$taskDetails) {
            $this->logger->warning('Cannot send status change email: task not found', [
                'companyId' => $companyId,
                'taskId' => $taskId,
            ]);
            return false;
        }

        $emails = [];
        foreach ($this->getSubscribedUsersByCompanyId($companyId, 'sendStatusChangeEmail', 'getStatusChangeEmailFromAnyCompany') as $user) {
            $emails[] = $user['email'];
        }

        $emails = array_values(array_unique(array_filter($emails)));

        $this->logger->info('Prepared recipient list for status change email', [
            'emails' => $emails,
        ]);

        if (empty($emails)) {
            $this->logger->warning('Cannot send status change email: no recipients found', [
                'companyId' => $companyId,
                'taskId' => $taskId,
            ]);
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
            $this->logger->info('Status change email sent', [
                'companyId' => $companyId,
                'taskId' => $taskId,
                'recipientCount' => count($emails),
            ]);
            return true;
        } catch (PHPMailerException $e) {
            $this->logger->error('Failed to send status change email', [
                'companyId' => $companyId,
                'taskId' => $taskId,
                'recipientCount' => count($emails),
                'error' => $mailer->ErrorInfo ?: $e->getMessage(),
            ]);
            return false;
        }
    }
}
