<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/emailSender.php';

/*
 * Egyszeru teszt endpoint az EmailSender osztalyhoz.
 * A HTML email tartalom kulon fajlbol toltodik be.
 */

//$to = $_GET['to'] ?? 'martonjanos1990@gmail.com';
$to = $_GET['to'] ?? 'martonj@expressone.hu';
$subject = 'OFD test mail with new design idea';

$templatePath = __DIR__ . '/test-template.html';
$dummyHtml = file_get_contents($templatePath);


if ($dummyHtml === false) {
  http_response_code(500);
  echo json_encode([
    'status' => 500,
    'message' => 'Nem sikerult beolvasni a HTML sablont.',
    'templatePath' => $templatePath,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$textBody = "Ez egy teszt email plain text verzioja";

$emailSender = new EmailSender();

$result = $emailSender->send($to, $subject, $dummyHtml, [
  'textBody' => $textBody,
  'cc' => ['martonjanos1990@gmail.com']
]);

echo json_encode([
  'request' => [
    'to' => $to,
    'subject' => $subject,
  ],
  'result' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
