<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require('../inc/conn.php');
require_once DOC_ROOT. '/lib/NotificationGenerator.php';

// PDO kapcsolat (conn.php-ban definiált)
$generator = new NotificationGenerator($conn);
$generator->generateAll();
