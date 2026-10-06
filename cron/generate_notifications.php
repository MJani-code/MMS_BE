<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require('../inc/conn.php');
require_once DOC_ROOT . '/vendor/autoload.php';
require_once DOC_ROOT. '/lib/NotificationGenerator.php';

// PDO kapcsolat (conn.php-ban definiált), SMTP beállítások (config.php-ban definiálva)
$generator = new NotificationGenerator(
    $conn,
    $smtpHost,
    $smtpPort,
    $smtpEncryption,
    $smtpUsername,
    $smtpPassword,
    $smtpFromEmail,
    $smtpFromName
);
$generator->generateAll();
