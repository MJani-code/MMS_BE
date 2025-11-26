<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');


$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class getItems
{
    private $conn;
    private $response;
    private $auth;
    private $token;

    public function __construct($conn, &$response, $auth)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
    }

    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'payload' => [
                'issues' => $data['issues'] ?? null,
                'spareParts' => $data['spareParts'] ?? null,
                'interventionList' => $data['interventionList'] ?? null,
                'interventions' => $data['interventions'] ?? null
            ]
        ];
    }

    public function getIssues($payload)
    {
        // Authenticate user
        $userId = null;
        $isAccess = $this->auth->authenticate(26);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        $issues = [];
        $spareParts = [];

        // Fetch issues from database
        try {
            $stmt = $this->conn->prepare(
                "SELECT tli.id, lit.name
                FROM task_lockers_issues tli
                LEFT JOIN locker_issue_types lit ON lit.id = tli.issue_type
                WHERE uuid = :uuid AND tli.is_solved = 0
                "
            );
            // uuid string
            $stmt->bindValue(':uuid', isset($payload['uuid']) ? $payload['uuid'] : null, PDO::PARAM_STR);
            $stmt->execute();
            $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error (issues): " . $e->getMessage());
        }

        // Fetch spare parts from database
        try {
            $stmt = $this->conn->prepare(
                "SELECT
                    s.id as stockId,
                    p.id as partId,
                    p.part_number AS partNumber,
                    CONCAT(p.name, ' ', s.quantity, 'db', ' (', w.name, ')') AS name,
                    w.id AS warehouseId,
                    s.supplier_id as supplierId,
                    ifnull(ps.price, 0) AS unitPrice,
                    ps.currency AS currency
                FROM stock s
                LEFT JOIN parts p ON p.id = s.part_id
                LEFT JOIN warehouses w ON w.id = s.warehouse_id
                LEFT JOIN part_supplier ps ON ps.part_id = p.id AND ps.supplier_id = s.supplier_id
                GROUP BY s.id, p.id, w.id, s.supplier_id, p.part_number, p.name, s.quantity
                ORDER BY p.part_number ASC
                "
            );
            $stmt->execute();
            $spareParts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error (spareParts): " . $e->getMessage());
        }

        //Fetch interventionList from database
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, name
                 FROM interventions"
            );
            $stmt->execute();
            $interventionList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error (interventions): " . $e->getMessage());
        }

        //Fetch issues, interventions and booked spare parts
        try {
            // 1) fetch interventions for uuid
            $stmt = $this->conn->prepare(
                "SELECT tli.id, i.name, tli.performed_by as performedBy, tli.created_at as createdAt, tli.notes
                 FROM task_lockers_interventions tli
                 LEFT JOIN interventions i ON i.id = tli.intervention_id
                 WHERE uuid = :uuid
                 ORDER BY tli.created_at DESC"
            );
            $stmt->bindValue(':uuid', isset($payload['uuid']) ? $payload['uuid'] : null, PDO::PARAM_STR);
            $stmt->execute();
            $interventions = $stmt->fetchAll(PDO::FETCH_ASSOC);


            // Prepare maps
            $issuesByIntervention = [];
            $partsByIntervention = [];

            if (!empty($interventions)) {
                // collect intervention ids
                $ids = array_column($interventions, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // 2) fetch issues linked to interventions (table: intervention_issues, has intervention_id)
                $sqlIssues = "SELECT it.id, it.intervention_id as interventionId, it.issue_id as issueId, lit.name as issueTypeName, tli.description as issueDescription
                              FROM intervention_issues it
                              LEFT JOIN task_lockers_issues tli ON tli.id = it.issue_id
                              LEFT JOIN locker_issue_types lit ON lit.id = tli.issue_type
                              LEFT JOIN interventions i ON i.id = it.intervention_id
                              WHERE intervention_id IN ($placeholders)";
                $stmt = $this->conn->prepare($sqlIssues);
                $stmt->execute($ids);
                $allIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allIssues as $r) {
                    $issuesByIntervention[$r['interventionId']][] = $r;
                }


                // 3) fetch used spare parts linked to interventions
                // assumed table task_lockers_intervention_parts (intervention_id, part_id, quantity) and parts table 'parts'
                $sqlParts = "SELECT tlip.intervention_id as interventionId, p.part_number as partNumber, p.name, sm.supplier_id as supplierId, tlip.quantity
                             FROM task_locker_intervention_parts tlip
                             LEFT JOIN parts p ON p.id = tlip.part_id
                             LEFT JOIN interventions i ON i.id = tlip.intervention_id
                             LEFT JOIN stock_movements sm ON sm.task_locker_intervention_parts_id = tlip.id
                             WHERE tlip.intervention_id IN ($placeholders)";
                $stmt = $this->conn->prepare($sqlParts);
                $stmt->execute($ids);
                $allParts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allParts as $r) {
                    $partsByIntervention[$r['interventionId']][] = $r;
                }

                // attach issues and parts to interventions
                foreach ($interventions as &$intv) {
                    $iid = $intv['id'];
                    $intv['issues'] = $issuesByIntervention[$iid] ?? [];
                    $intv['parts'] = $partsByIntervention[$iid] ?? [];
                }
                unset($intv);
            } else {
                $interventions = [];
            }
        } catch (PDOException $e) {
            return $this->response = $this->createResponse(500, "Database error (interventions): " . $e->getMessage());
        }

        // Return response (issues = task-level issues, spareParts = catalog, interventions = per-intervention data)
        if (!empty($issues) || !empty($spareParts) || !empty($interventions) || !empty($interventionList)) {
            return $this->response = $this->createResponse(200, "Data fetched", [
                'issues' => $issues,
                'spareParts' => $spareParts,
                'interventionList' => $interventionList,
                'interventions' => $interventions
            ]);
        } else {
            return $this->response = $this->createResponse(404, "No data found", [
                'issues' => $issues,
                'spareParts' => $spareParts,
                'interventionList' => $interventionList,
                'interventions' => $interventions
            ]);
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$itemsForRepair = new getItems($conn, $response, $auth, $token);
$itemsForRepair->getIssues($payload);

echo json_encode($response);
