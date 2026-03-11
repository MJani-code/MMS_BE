<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require('../inc/conn.php');
require('../lib/LockerDailyPermissionChecker.php');

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? '';

if (empty($token)) {
    $token = $_GET['token'] ?? null;
}

$checker = new LockerDailyPermissionChecker($conn, $getAllActivePointsUrl, $user, $password, $token);
$result = $checker->getLockerAvailabilityData();
