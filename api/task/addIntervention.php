<?php
header('Content-Type: application/json');

require('../../inc/conn.php');
require('../../functions/taskFunctions.php');
require('../../api/user/auth/auth.php');

$response = [];

$jsonData = file_get_contents("php://input");
$payload = json_decode($jsonData, true);

class addIntervention
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
            'payload' => $data
        ];
    }

    private function validatePayload($payload, $locale)
    {
        if (!is_array($payload)) {
            return localizeErrorMessage('errors.required_fields_missing', $locale);
        }

        if (!array_key_exists('taskId', $payload) || $payload['taskId'] === null || $payload['taskId'] === '') {
            return localizeErrorMessage('errors.data_update_missing_field', $locale, ['field' => 'taskId']);
        }

        if (!isset($payload['interventions']) || !is_array($payload['interventions']) || count($payload['interventions']) === 0) {
            return localizeErrorMessage('errors.data_update_missing_field', $locale, ['field' => 'interventions']);
        }

        foreach ($payload['interventions'] as $intervention) {
            if (!is_array($intervention)) {
                return localizeErrorMessage('errors.data_update_missing_field', $locale, ['field' => 'interventions']);
            }

            if (!array_key_exists('issues', $intervention) || !is_array($intervention['issues']) || count($intervention['issues']) === 0) {
                return localizeErrorMessage('errors.data_update_missing_field', $locale, ['field' => 'issues']);
            }

            if (!array_key_exists('interventionId', $intervention) || $intervention['interventionId'] === null || $intervention['interventionId'] === '') {
                return localizeErrorMessage('errors.data_update_missing_field', $locale, ['field' => 'interventionId']);
            }

            if (!array_key_exists('uuid', $intervention) || trim((string)$intervention['uuid']) === '') {
                return localizeErrorMessage('errors.data_update_missing_field', $locale, ['field' => 'uuid']);
            }
        }

        return null;
    }

    public function addInterventionFunction($payload)
    {
        $userId = null;
        $locale = is_array($payload) ? ($payload['locale'] ?? 'hu') : 'hu';

        $validationError = $this->validatePayload($payload, $locale);
        if ($validationError !== null) {
            return $this->response = $this->createResponse(400, $validationError);
        }

        $isAccess = $this->auth->authenticate(26);
        if ($isAccess['status'] !== 200) {
            return $this->response = $isAccess;
        } else {
            $userId = $isAccess['data']->userId;
        }

        $result = addIntervention($this->conn, $payload['taskId'], $payload['interventions'], $userId, $locale);
        if ($result['status'] !== 200) {
            return $this->response = $this->createResponse($result['status'], $result['message']);
        } else {
            return $this->response = $this->createResponse(200, localizeSuccessMessage('success.intervention_added_success', $locale), $result['payload']);
        }
    }
}

$tokenRow = $_SERVER['HTTP_AUTHORIZATION'];
preg_match('/Bearer\s(\S+)/', $tokenRow, $matches);
$token = $matches[1];

$auth = new Auth($conn, $token, $secretkey);

$addIntervention = new addIntervention($conn, $response, $auth);
$addIntervention->addInterventionFunction($payload);

echo json_encode($response);
