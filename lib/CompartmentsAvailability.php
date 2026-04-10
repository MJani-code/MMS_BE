<?php
header('Content-Type: application/json');

require('../functions/taskFunctions.php');
require('../vendor/autoload.php');


use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('generateCompartmentsAvailability');
$logger->pushHandler(new RotatingFileHandler('logs/generateCompartmentsAvailability.log', 5));

$response = [];

class CompartmentsAvailability
{
    private $conn;
    private $token;

    public function __construct($conn, $token)
    {
        $this->conn = $conn;
        $this->token = $token;
    }

    // Standard valasz struktura epito helper.
    public function createResponse($statusCode, $message, $data = null)
    {
        return [
            'status' => $statusCode,
            'message' => $message,
            'payload' => $data
        ];
    }

    // Informacios logolas Monologgal, fallback error_log-ra.
    private function logInfo(string $message, array $context = []): void
    {
        global $logger;

        if (isset($logger) && $logger instanceof Logger) {
            $logger->info($message, $context);
            return;
        }

        error_log('[CompartmentsAvailability][INFO] ' . $message . ' ' . json_encode($context));
    }

    // Hibalogolas Monologgal, fallback error_log-ra.
    private function logError(string $message, array $context = []): void
    {
        global $logger;

        if (isset($logger) && $logger instanceof Logger) {
            $logger->error($message, $context);
            return;
        }

        error_log('[CompartmentsAvailability][ERROR] ' . $message . ' ' . json_encode($context));
    }

    // API token ellenorzese az adatbazisban tarolt tokennel.
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


    // Locker napi elerhetosegi adatok lekerdezese datum intervallumra.
    public function getLockerAvailabilityData(?string $fromDate = null, ?string $toDate = null)
    {
        try {
            $fromDate = $fromDate ?: date('Y-m-d');
            $toDate = $toDate ?: $fromDate;

            $stmt = $this->conn->prepare("SELECT
                     ldp.tof_shop_id as tofShopId,
                     ldp.day as day,
                     ldp.is_enabled as isEnabled,
                     CONCAT('EXP-',tl.box_id) as lockerDisplayName,
                     t.compartment_count as compartmentCount
            FROM locker_daily_permission ldp
            LEFT JOIN (
                SELECT tof_shop_id, MIN(id) AS location_id
                FROM task_locations
                WHERE deleted = 0
                GROUP BY tof_shop_id
            ) tl_map ON tl_map.tof_shop_id = ldp.tof_shop_id
            LEFT JOIN task_locations tl ON tl.id = tl_map.location_id
            LEFT JOIN (
                SELECT task_locations_id, MIN(id) AS locker_id, compartment_count, tof_shop_id
                FROM task_lockers
                WHERE deleted = 0
                GROUP BY tof_shop_id
            ) t ON t.tof_shop_id = tl.tof_shop_id
            WHERE ldp.day BETWEEN :fromDate AND :toDate");
            $stmt->execute([
                ':fromDate' => $fromDate,
                ':toDate' => $toDate
            ]);
            $permissionsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $permissionsData;
        } catch (\Throwable $th) {
            return $this->createResponse(500, 'Hiba történt a locker elérhetőségi adatok lekérése során: ' . $th->getMessage(), null);
        }
    }

    // Nyers hibajegy payload beolvasasa a los_tickets tablbol.
    private function getFaultyTicketsPayload(): array
    {
        $stmt = $this->conn->prepare("SELECT
                     payload
            FROM los_tickets lt");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rawPayload = (string)($result[0]['payload'] ?? '');
        $decoded = null;

        $candidates = [
            $rawPayload,
            stripslashes($rawPayload),
            trim($rawPayload, '"'),
            stripslashes(trim($rawPayload, '"')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $candidateDecoded = json_decode($candidate, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            if (is_string($candidateDecoded)) {
                $secondPass = json_decode($candidateDecoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $candidateDecoded = $secondPass;
                }
            }

            $decoded = $candidateDecoded;
            break;
        }

        if (!is_array($decoded)) {
            $this->logError('getFaultyTicketsPayload:decode_failed', [
                'payloadLength' => strlen($rawPayload),
                'preview' => substr($rawPayload, 0, 200)
            ]);
            return [];
        }

        $tickets = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : (is_array($decoded) ? $decoded : []);
        $this->logInfo('getFaultyTicketsPayload:done', [
            'rowsFromDb' => $result,
            'ticket' => $decoded
        ]);

        return $tickets;
    }

    // Adott napra kiszamolt hibas rekesz percek visszaadasa.
    public function getFaultyCompartmentsData(?string $reportDate = null)
    {
        try {
            $effectiveReportDate = $reportDate ?: date('Y-m-d');
            $this->logInfo('getFaultyCompartmentsData:start', [
                'reportDate' => $effectiveReportDate
            ]);

            $tickets = $this->getFaultyTicketsPayload();
            $result = $this->getFaultyCompartmentUnavailabilityMinutes($tickets, $effectiveReportDate);

            $this->logInfo('getFaultyCompartmentsData:done', [
                'reportDate' => $effectiveReportDate,
                'rows' => count($result)
            ]);

            return $result;
        } catch (\Throwable $th) {
            $this->logError('getFaultyCompartmentsData:exception', [
                'message' => $th->getMessage(),
                'reportDate' => $reportDate
            ]);
            return $this->createResponse(500, 'Hiba történt az adatok lekérése során: ' . $th->getMessage(), null);
        }
    }

    // Ticketekbol napi szinten kiszamolja rekeszenkent a hibaban toltott perceket.
    public function getFaultyCompartmentUnavailabilityMinutes(array $tickets, string $reportDate): array
    {
        $this->logInfo('getFaultyCompartmentUnavailabilityMinutes:start', [
            'reportDate' => $reportDate,
            'ticketCount' => count($tickets)
        ]);

        $result = [];
        $reportDayStart = new DateTimeImmutable($reportDate . ' 00:00:00', new DateTimeZone('UTC'));
        $reportDayEnd = $reportDayStart->modify('+1 day');
        $reportDay = $reportDayStart->format('Y-m-d');

        foreach ($tickets as $ticket) {
            $lockerDisplayName = trim((string)($ticket['lockerDisplayName'] ?? ''));
            $compartmentNumber = trim((string)($ticket['compartmentNumber'] ?? ''));
            $uuid = (string)($ticket['uuid'] ?? '');
            $issueType = $ticket['issueType'] ?? null;
            $createdAtRaw = (string)($ticket['createdAt'] ?? '');
            $solutionDateRaw = (string)($ticket['solutionDate'] ?? '');
            $modifiedAtRaw = isset($ticket['modifiedAt']) ? (string)$ticket['modifiedAt'] : '';

            if ($lockerDisplayName === '' || $compartmentNumber === '' || $createdAtRaw === '') {
                continue;
            }

            $createdAt = $this->parseUtcDate($createdAtRaw);
            if (!$createdAt) {
                continue;
            }

            $solutionDate = null;
            if ($solutionDateRaw !== '' && $solutionDateRaw !== '0001-01-01T00:00:00Z') {
                $solutionDate = $this->parseUtcDate($solutionDateRaw);
            }

            if ($modifiedAtRaw !== '' && $modifiedAtRaw !== '0001-01-01T00:00:00Z') {
                $modifiedAt = $this->parseUtcDate($modifiedAtRaw);
                $solutionDate = $solutionDate ? max($solutionDate, $modifiedAt) : $modifiedAt;
            }

            if (!$solutionDate) {
                $solutionDate = $reportDayEnd;
            }

            $effectiveStart = $createdAt > $reportDayStart ? $createdAt : $reportDayStart;
            $effectiveEnd = $solutionDate < $reportDayEnd ? $solutionDate : $reportDayEnd;
            $durationMinutes = max(0, (int)floor(($effectiveEnd->getTimestamp() - $effectiveStart->getTimestamp()) / 60));

            if ($durationMinutes === 0) {
                continue;
            }

            $key = $lockerDisplayName . '|' . $reportDay;
            $compartmentKey = $compartmentNumber;

            if (!isset($result[$key])) {
                $result[$key] = [
                    'uuid' => $uuid,
                    'lockerDisplayName' => $lockerDisplayName,
                    'createdDay' => $reportDay,
                    'minutesPerCompartment' => []
                ];
            } elseif (($result[$key]['uuid'] ?? '') === '' && $uuid !== '') {
                $result[$key]['uuid'] = $uuid;
            }

            if (!isset($result[$key]['minutesPerCompartment'][$compartmentKey])) {
                $result[$key]['minutesPerCompartment'][$compartmentKey] = [
                    'faultTime' => 0,
                    'issueType' => $issueType
                ];
            } elseif ($result[$key]['minutesPerCompartment'][$compartmentKey]['issueType'] === null && $issueType !== null) {
                $result[$key]['minutesPerCompartment'][$compartmentKey]['issueType'] = $issueType;
            }

            $result[$key]['minutesPerCompartment'][$compartmentKey]['faultTime'] += $durationMinutes;
        }

        foreach ($result as &$lockerRow) {
            $formattedFaults = [];
            foreach ($lockerRow['minutesPerCompartment'] as $compartmentNumber => $compartmentData) {
                $minutes = intval($compartmentData['faultTime'] ?? 0);
                $clampedMinutes = min(1440, max(0, $minutes));
                $formattedFaults[] = [
                    'compartmentNumber' => (string)$compartmentNumber,
                    'issueType' => $compartmentData['issueType'] ?? null,
                    'faultTime' => $clampedMinutes,
                    'operatingTime' => 1440
                ];
            }
            $lockerRow['minutesPerCompartment'] = $formattedFaults;
        }
        unset($lockerRow);

        $output = array_values($result);
        $this->logInfo('getFaultyCompartmentUnavailabilityMinutes:done', [
            'reportDate' => $reportDate,
            'effectiveStart' => $effectiveStart->format('Y-m-d H:i:s'),
            'effectiveEnd' => $effectiveEnd->format('Y-m-d H:i:s'),
            '$durationMinutes' => $durationMinutes,
            'lockerRows' => $output
        ]);

        return $output;
    }

    // Datum parse helper UTC idozonaban.
    private function parseUtcDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            $this->logError('parseUtcDate:failed', [
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // Teljes napi matrixot epitet: minden napra es minden lockerre legyen sor.
    private function buildAvailabilityMatrix(array $availabilityData, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): array
    {
        $this->logInfo('buildAvailabilityMatrix:start', [
            'inputRows' => count($availabilityData),
            'fromDate' => $periodStart->format('Y-m-d'),
            'toDate' => $periodEnd->format('Y-m-d')
        ]);

        $byLockerDay = [];
        $templates = [];

        foreach ($availabilityData as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lockerDisplayName = trim((string)($row['lockerDisplayName'] ?? ''));
            $tofShopId = trim((string)($row['tofShopId'] ?? ''));
            $lockerKey = $lockerDisplayName !== '' ? $lockerDisplayName : ('TOF-' . $tofShopId);

            if ($lockerKey === '') {
                continue;
            }

            $day = (string)($row['day'] ?? '');
            if ($day !== '') {
                $byLockerDay[$lockerKey . '|' . $day] = $row;
            }

            if (!isset($templates[$lockerKey])) {
                $templates[$lockerKey] = $row;
                continue;
            }

            $existingCount = intval($templates[$lockerKey]['compartmentCount'] ?? 0);
            $currentCount = intval($row['compartmentCount'] ?? 0);
            if ($currentCount > $existingCount) {
                $templates[$lockerKey] = $row;
            }
        }

        if (empty($templates)) {
            return [];
        }

        $matrix = [];
        $cursor = $periodStart;
        while ($cursor <= $periodEnd) {
            $day = $cursor->format('Y-m-d');
            foreach ($templates as $lockerKey => $template) {
                $candidate = $byLockerDay[$lockerKey . '|' . $day] ?? $template;
                if (!is_array($candidate)) {
                    continue;
                }

                $row = $candidate;
                $row['day'] = $day;
                $matrix[] = $row;
            }
            $cursor = $cursor->modify('+1 day');
        }

        $this->logInfo('buildAvailabilityMatrix:done', [
            'templateLockers' => count($templates),
            'matrixRows' => count($matrix)
        ]);

        return $matrix;
    }

    // Folyamat osszefogo: availability + hibak merge-je egy vegso napi rekesz riportta.
    public function getMergedData(?string $fromDate = null, ?string $toDate = null)
    {
        $fromDate = $fromDate ?: date('Y-m-d');
        $toDate = $toDate ?: $fromDate;

        $this->logInfo('getMergedData:start', [
            'fromDate' => $fromDate,
            'toDate' => $toDate
        ]);

        try {
            $periodStart = new DateTimeImmutable($fromDate, new DateTimeZone('UTC'));
            $periodEnd = new DateTimeImmutable($toDate, new DateTimeZone('UTC'));
        } catch (\Throwable $th) {
            $this->logError('getMergedData:invalid_date', [
                'fromDate' => $fromDate,
                'toDate' => $toDate,
                'message' => $th->getMessage()
            ]);
            return $this->createResponse(400, 'Hibás dátum formátum. Várt formátum: YYYY-MM-DD.', null);
        }

        if ($periodEnd < $periodStart) {
            $this->logError('getMergedData:invalid_range', [
                'fromDate' => $fromDate,
                'toDate' => $toDate
            ]);
            return $this->createResponse(400, 'A toDate nem lehet korábbi, mint a fromDate.', null);
        }

        $availabilityData = $this->getLockerAvailabilityData($fromDate, $toDate);

        $tickets = [];
        try {
            $tickets = $this->getFaultyTicketsPayload();
        } catch (\Throwable $th) {
            $this->logError('getMergedData:getFaultyTicketsPayload_exception', [
                'message' => $th->getMessage()
            ]);
            return $this->createResponse(500, 'Hiba történt a hibás rekeszek adatainak lekérése során: ' . $th->getMessage(), $this->getFaultyTicketsPayload());
        }

        if ($availabilityData === false) {
            $this->logError('getMergedData:availability_failed');
            return $this->createResponse(500, 'Hiba történt a locker elérhetőségi adatok lekérése során.',);
        }

        $this->logInfo('getMergedData:availability_rows', [
            'rows' => count($availabilityData)
        ]);

        $availabilityData = $this->buildAvailabilityMatrix($availabilityData, $periodStart, $periodEnd);

        $this->logInfo('getMergedData:availability_matrix_rows', [
            'rows' => count($availabilityData)
        ]);

        $faultyByLockerAndDay = [];
        $cursor = $periodStart;
        while ($cursor <= $periodEnd) {
            $day = $cursor->format('Y-m-d');
            $dailyFaults = $this->getFaultyCompartmentUnavailabilityMinutes($tickets, $day);
            $this->logInfo('getMergedData:daily_faults', [
                'day' => $day,
                'rows' => count($dailyFaults)
            ]);
            foreach ($dailyFaults as $faulty) {
                $key = ($faulty['lockerDisplayName'] ?? '') . '|' . ($faulty['createdDay'] ?? '');
                $faultyByLockerAndDay[$key] = $faulty;
            }
            $cursor = $cursor->modify('+1 day');
        }

        $mergedResult = [];

        foreach ($availabilityData as $locker) {
            $lockerDisplayName = $locker['lockerDisplayName'] ?? '';
            $day = $locker['day'] ?? '';
            $locker['uuid'] = null;

            $matchKey = $lockerDisplayName . '|' . $day;
            $matchingFaulty = $faultyByLockerAndDay[$matchKey] ?? null;

            if (!empty($matchingFaulty)) {
                $locker['uuid'] = $matchingFaulty['uuid'] ?? null;
            }

            $existingCompartments = $matchingFaulty['minutesPerCompartment'] ?? [];
            $compartmentsByNumber = [];
            foreach ($existingCompartments as $compartment) {
                $number = trim((string)($compartment['compartmentNumber'] ?? ''));
                if ($number === '') {
                    continue;
                }

                $compartmentsByNumber[$number] = [
                    'compartmentNumber' => $number,
                    'issueType' => $compartment['issueType'] ?? null,
                    'faultTime' => max(0, min(1440, intval($compartment['faultTime'] ?? 0))),
                    'operatingTime' => max(0, min(1440, intval($compartment['operatingTime'] ?? 0)))
                ];
            }

            for ($i = 1; $i <= intval($locker['compartmentCount'] ?? 0); $i++) {
                $number = (string)$i;

                if (intval($locker['isEnabled'] ?? 0) === 0) {
                    $compartmentsByNumber[$number] = [
                        'compartmentNumber' => $number,
                        'issueType' => $compartmentsByNumber[$number]['issueType'] ?? null,
                        'faultTime' => 1440,
                        'operatingTime' => 1440
                    ];
                    continue;
                }

                if (!isset($compartmentsByNumber[$number])) {
                    $compartmentsByNumber[$number] = [
                        'compartmentNumber' => $number,
                        'issueType' => null,
                        'faultTime' => 0,
                        'operatingTime' => 1440
                    ];
                }
            }

            ksort($compartmentsByNumber, SORT_NATURAL);
            $locker['minutesPerCompartment'] = array_values($compartmentsByNumber);

            $locker['reportCreatedDay'] = $day;
            $mergedResult[] = $locker;
        }

        $this->logInfo('getMergedData:done', [
            'mergedRows' => count($mergedResult)
        ]);

        return $this->createResponse(200, 'Adatok sikeresen lekérve és összevonva.', array_values($mergedResult));
    }

    // Vegso merge adat mentese adatbazisba fejléc + rekesz sorokkal, tranzakcioban.
    public function saveMergedDataToDatabase(array $mergedRows): array
    {
        if (empty($mergedRows)) {
            $this->logInfo('saveMergedDataToDatabase:empty_input');
            return $this->createResponse(200, 'Nincs mentendo adat.', ['saved' => 0]);
        }

        try {
            $this->logInfo('saveMergedDataToDatabase:start', [
                'rows' => count($mergedRows)
            ]);

            $this->conn->beginTransaction();

            $upsertReportStmt = $this->conn->prepare("INSERT INTO locker_daily_reports (
                    tof_shop_id,
                    locker_display_name,
                    report_day,
                    report_created_day,
                    is_enabled,
                    compartment_count,
                    uuid
                ) VALUES (
                    :tof_shop_id,
                    :locker_display_name,
                    :report_day,
                    :report_created_day,
                    :is_enabled,
                    :compartment_count,
                    :uuid
                )
                ON DUPLICATE KEY UPDATE
                    locker_display_name = VALUES(locker_display_name),
                    is_enabled = VALUES(is_enabled),
                    compartment_count = VALUES(compartment_count),
                    uuid = VALUES(uuid),
                    id = LAST_INSERT_ID(id)");

            $upsertCompartmentStmt = $this->conn->prepare("INSERT INTO locker_daily_report_compartments (
                    report_id,
                    compartment_number,
                    issue_type,
                    fault_minutes,
                    operating_minutes
                ) VALUES (
                    :report_id,
                    :compartment_number,
                    :issue_type,
                    :fault_minutes,
                    :operating_minutes
                )
                ON DUPLICATE KEY UPDATE
                    fault_minutes = VALUES(fault_minutes),
                    operating_minutes = VALUES(operating_minutes),
                    issue_type = VALUES(issue_type)");

            $savedReports = 0;
            $savedCompartments = 0;
            $skippedReports = 0;

            foreach ($mergedRows as $row) {
                $tofShopId = intval($row['tofShopId'] ?? 0);
                $lockerDisplayName = trim((string)($row['lockerDisplayName'] ?? ''));
                $reportDay = (string)($row['day'] ?? '');
                $reportCreatedDay = (string)($row['reportCreatedDay'] ?? $reportDay);
                $uuid = (string)($row['uuid'] ?? '');

                if ($lockerDisplayName === '' && $tofShopId > 0) {
                    $lockerDisplayName = 'TOF-' . $tofShopId;
                }

                if ($reportDay === '' && $reportCreatedDay !== '') {
                    $reportDay = $reportCreatedDay;
                }

                if ($reportCreatedDay === '' && $reportDay !== '') {
                    $reportCreatedDay = $reportDay;
                }

                if ($tofShopId <= 0 || $reportDay === '' || $reportCreatedDay === '') {
                    $skippedReports++;
                    $this->logInfo('saveMergedDataToDatabase:skip_row', [
                        'tofShopId' => $tofShopId,
                        'lockerDisplayName' => $lockerDisplayName,
                        'reportDay' => $reportDay,
                        'reportCreatedDay' => $reportCreatedDay
                    ]);
                    continue;
                }

                $upsertReportStmt->execute([
                    ':tof_shop_id' => $tofShopId,
                    ':locker_display_name' => $lockerDisplayName,
                    ':report_day' => $reportDay,
                    ':report_created_day' => $reportCreatedDay,
                    ':is_enabled' => intval($row['isEnabled'] ?? 0),
                    ':compartment_count' => intval($row['compartmentCount'] ?? 0),
                    ':uuid' => $uuid
                ]);

                $reportId = intval($this->conn->lastInsertId());
                if ($reportId <= 0) {
                    continue;
                }

                $savedReports++;

                $compartmentNumbers = [];
                foreach (($row['minutesPerCompartment'] ?? []) as $compartment) {
                    $compartmentNumber = trim((string)($compartment['compartmentNumber'] ?? ''));
                    if ($compartmentNumber === '') {
                        continue;
                    }

                    $upsertCompartmentStmt->execute([
                        ':report_id' => $reportId,
                        ':compartment_number' => $compartmentNumber,
                        ':issue_type' => $compartment['issueType'] ?? null,
                        ':fault_minutes' => max(0, min(1440, intval($compartment['faultTime'] ?? 0))),
                        ':operating_minutes' => max(0, min(1440, intval($compartment['operatingTime'] ?? 0)))
                    ]);

                    $savedCompartments++;
                    $compartmentNumbers[] = $compartmentNumber;
                }

                if (!empty($compartmentNumbers)) {
                    $placeholders = implode(',', array_fill(0, count($compartmentNumbers), '?'));
                    $sql = "DELETE FROM locker_daily_report_compartments
                        WHERE report_id = ?
                          AND compartment_number NOT IN (" . $placeholders . ")";
                    $deleteStmt = $this->conn->prepare($sql);
                    $deleteStmt->execute(array_merge([$reportId], $compartmentNumbers));
                }
            }

            $this->conn->commit();

            $this->logInfo('saveMergedDataToDatabase:done', [
                'savedReports' => $savedReports,
                'savedCompartments' => $savedCompartments,
                'skippedReports' => $skippedReports
            ]);

            return $this->createResponse(200, 'Adatok sikeresen mentve.', [
                'savedReports' => $savedReports,
                'savedCompartments' => $savedCompartments,
                'skippedReports' => $skippedReports
            ]);
        } catch (\Throwable $th) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            $this->logError('saveMergedDataToDatabase:exception', [
                'message' => $th->getMessage()
            ]);

            return $this->createResponse(500, 'Hiba tortent a mentes kozben: ' . $th->getMessage(), null);
        }
    }
}
