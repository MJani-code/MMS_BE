<?php
$domain = $_SERVER['HTTP_HOST'] ?? 'https://localhost:5000';
$test = 0;

if (strpos($domain, 'localhost') !== false) {
     $test = 1;
}

if ($test) {
     // DB- DEV

     define('host', "127.0.0.1");
     define('user', "root");
     define('pwd', "");
     define('db', "maintenance_management");

     //ToDo ROOT - DEV
     define('DOC_ROOT', $_SERVER['DOCUMENT_ROOT'].'/MMS/MMS_BE');
     define('API', $_SERVER['DOCUMENT_ROOT'].'/MMS/MMS_BE/api');
     define('FUNC', $_SERVER['DOCUMENT_ROOT'].'/MMS/MMS_BE/functions');

} else {
     // define('host','mysql.nethely.hu');
     // define('user','build_mate');
     // define('pwd','eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9');
     // define('db','build_mate');

     // //ToDo DOC- ROOT - LIVE
     // define('DOC_URL', $domain.'/build_mate_be');
     // define('DOC_PATH', $domain.'/build_mate_be');

}
