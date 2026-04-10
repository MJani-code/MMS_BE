<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

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
$saveResult = $generator->saveMergedDataToDatabase($merged['payload'] ?? []);
echo json_encode([
    'saveResult' => $saveResult,
    'mergedData' => $merged,
]);
