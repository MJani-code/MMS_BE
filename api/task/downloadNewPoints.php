<?php
$traceStarted = hrtime(true);
$traceLast = $traceStarted;
$traceId = uniqid('downloadNewPoints-', true);
$trace = static function ($event, $context = []) use ($traceStarted, &$traceLast, $traceId) {
    $now = hrtime(true);
    error_log(json_encode([
        'trace' => $traceId,
        'event' => $event,
        'total_ms' => round(($now - $traceStarted) / 1e6, 1),
        'since_previous_ms' => round(($now - $traceLast) / 1e6, 1),
        'context' => $context,
    ]));
    $traceLast = $now;
};
register_shutdown_function(static function () use ($trace) {
    $trace('shutdown', ['peak_memory_bytes' => memory_get_peak_usage(true)]);
});
$trace('request_started');

require_once __DIR__ . '/../../inc/conn.php';
$trace('database_connected');
require_once __DIR__ . '/../../functions/taskFunctions.php';
require_once __DIR__ . '/../../api/user/auth/auth.php';
$trace('dependencies_loaded');


$response = [];
// input data feldolgozása
$input = file_get_contents('php://input');
$payload = json_decode($input, true);
$data = $payload;

class downloadNewPoints
{
    private $conn;
    private $response;
    private $auth;
    private $getAllActivePointsUrl;
    private $user;
    private $password;

    public function __construct($conn, &$response, $auth, $getAllActivePointsUrl, $user, $password)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->auth = $auth;
        $this->getAllActivePointsUrl = $getAllActivePointsUrl;
        $this->user = $user;
        $this->password = $password;
    }


    public function downloadNewPointsFunction($conn, $data, $trace)
    {

        $userId = null;
        $trace('authentication_started');
        $isAccess = $this->auth->authenticate(25);
        $trace('authentication_finished', ['status' => $isAccess['status'] ?? null]);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;            
        }
        $result = downloadNewPoints($this->conn, $data, $this->getAllActivePointsUrl, $this->user, $this->password, $this->auth, $trace);
        $this->response = $result;
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$downloadNewPoints = new downloadNewPoints($conn, $response, $auth, $getAllActivePointsUrl, $user, $password);
$downloadNewPoints->downloadNewPointsFunction($conn, $data, $trace);
echo json_encode($response);

