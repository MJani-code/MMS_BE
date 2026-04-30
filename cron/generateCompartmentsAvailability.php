<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');  // Memórialimit növelése a nagy adatfeldolgozáshoz


require('../inc/conn.php');
require_once DOC_ROOT . '/lib/CompartmentsAvailability.php';

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? '';

if (empty($token)) {
    $token = $_GET['token'] ?? null;
}

//input mezők kiolvasása
$input = json_decode(file_get_contents('php://input'), true);
$fromDate = $input['fromDate'] ?? null;
$toDate = $input['toDate'] ?? null;

// PDO kapcsolat (conn.php-ban definiált)
$generator = new CompartmentsAvailability($conn, $token);
//$generator->getLockerAvailabilityData();
$merged = $generator->getMergedData($fromDate, $toDate);

// Chunking: feldolgozás kisebb darabonként memóriakíméléshez
$mergedData = $merged['payload'] ?? [];
$chunkSize = 100;  // 100 sor per tranzakció
$chunks = array_chunk($mergedData, $chunkSize);
$allResults = [];

foreach ($chunks as $chunkIndex => $chunk) {
    $chunkResult = $generator->saveMergedDataToDatabase($chunk);
    $allResults[] = [
        'chunk' => $chunkIndex + 1,
        'result' => $chunkResult
    ];
    unset($chunk);  // Memória felszabadítása
    gc_collect_cycles();  // Garbage collector futtatása
}

$saveResult = [
    'status' => 200,
    'message' => 'Összes chunk feldolgozva',
    'totalChunks' => count($chunks),
    'details' => $allResults
];

echo json_encode([
    'saveResult' => $saveResult,
    'mergedData' => $merged,
]);
