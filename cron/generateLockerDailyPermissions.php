<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require('../inc/conn.php');
require('../lib/LockerDailyPermissionChecker.php');
require(__DIR__ . '/../../vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('generateLockerDailyPermissions');
$logger->pushHandler(new RotatingFileHandler('logs/generateLockerDailyPermissions.log', 5));

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1] ?? '';

if (empty($token)) {
    $token = $_GET['token'] ?? null;
}

$checker = new LockerDailyPermissionChecker($conn, $getAllActivePointsUrl, $user, $password, $token);
$result = $checker->getLockerAvailabilityData();

$logger->info('Locker availability data retrieved', ['result' => $result]);
