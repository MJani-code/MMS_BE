<?php

class EmailSender
{
    private string $fromEmail;
    private string $fromName;
    private ?string $replyTo;

    public function __construct(?string $fromEmail = null, ?string $fromName = null, ?string $replyTo = null)
    {
        $this->fromEmail = $fromEmail ?: (getenv('MAIL_FROM_ADDRESS') ?: 'noreply@martolin.hu');
        $this->fromName = $fromName ?: (getenv('MAIL_FROM_NAME') ?: 'Notification (Test)');
        $this->replyTo = $replyTo ?: (getenv('MAIL_REPLY_TO') ?: null);
    }

    public function send(string $to, string $subject, string $htmlBody, array $options = []): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => 400,
                'message' => 'Invalid recipient email address.',
            ];
        }

        $textBody = $options['textBody'] ?? strip_tags($htmlBody);
        $cc = $options['cc'] ?? [];
        $bcc = $options['bcc'] ?? [];
        $extraHeaders = $options['headers'] ?? [];
        $attachments = $options['attachments'] ?? [];

        if (!is_array($attachments)) {
            return [
                'status' => 400,
                'message' => 'Attachments must be an array.',
            ];
        }

        $preparedAttachments = $this->prepareAttachments($attachments);
        if ($preparedAttachments['status'] !== 200) {
            return $preparedAttachments;
        }

        if (!empty($preparedAttachments['data'])) {
            $mixedBoundary = 'mixed_' . md5((string) microtime(true));
            $altBoundary = 'alt_' . md5((string) microtime(true) . '_alt');

            $headers = $this->buildHeaders(
                $cc,
                $bcc,
                $extraHeaders,
                'multipart/mixed; boundary="' . $mixedBoundary . '"'
            );
            $body = $this->buildMultipartMixedBody(
                $textBody,
                $htmlBody,
                $mixedBoundary,
                $altBoundary,
                $preparedAttachments['data']
            );
        } else {
            $altBoundary = 'alt_' . md5((string) microtime(true));
            $headers = $this->buildHeaders(
                $cc,
                $bcc,
                $extraHeaders,
                'multipart/alternative; boundary="' . $altBoundary . '"'
            );
            $body = $this->buildMultipartAlternativeBody($textBody, $htmlBody, $altBoundary);
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $sent = mail($to, $encodedSubject, $body, $headers['string']);

        if (!$sent) {
            return [
                'status' => 500,
                'message' => 'Email sending failed.',
            ];
        }

        return [
            'status' => 200,
            'message' => 'Email sent successfully.',
        ];
    }

    public function sendBulk(array $recipients, string $subject, string $htmlBody, array $options = []): array
    {
        $results = [];

        foreach ($recipients as $recipient) {
            $results[$recipient] = $this->send($recipient, $subject, $htmlBody, $options);
        }

        return [
            'status' => 200,
            'message' => 'Bulk send completed.',
            'results' => $results,
        ];
    }

    private function buildHeaders(
        array $cc = [],
        array $bcc = [],
        array $extraHeaders = [],
        string $contentType = 'multipart/alternative; boundary="default"'
    ): array {
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $this->formatAddress($this->fromEmail, $this->fromName),
            'Content-Type: ' . $contentType,
        ];

        if ($this->replyTo && filter_var($this->replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        if (!empty($cc)) {
            $validCc = $this->filterValidEmails($cc);
            if (!empty($validCc)) {
                $headers[] = 'Cc: ' . implode(', ', $validCc);
            }
        }

        if (!empty($bcc)) {
            $validBcc = $this->filterValidEmails($bcc);
            if (!empty($validBcc)) {
                $headers[] = 'Bcc: ' . implode(', ', $validBcc);
            }
        }

        foreach ($extraHeaders as $header) {
            if (is_string($header) && trim($header) !== '') {
                $headers[] = trim($header);
            }
        }

        return [
            'string' => implode("\r\n", $headers) . "\r\n",
        ];
    }

    private function buildMultipartAlternativeBody(string $textBody, string $htmlBody, string $boundary): string
    {
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    private function buildMultipartMixedBody(
        string $textBody,
        string $htmlBody,
        string $mixedBoundary,
        string $altBoundary,
        array $attachments
    ): string {
        $body = "This is a multi-part message in MIME format.\r\n\r\n";
        $body .= "--{$mixedBoundary}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";

        $body .= $this->buildMultipartAlternativeBody($textBody, $htmlBody, $altBoundary);
        $body .= "\r\n";

        foreach ($attachments as $attachment) {
            $safeName = $this->escapeHeaderValue($attachment['name']);
            $body .= "--{$mixedBoundary}\r\n";
            $body .= "Content-Type: {$attachment['mime']}; name=\"{$safeName}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n";
            $body .= $attachment['content'] . "\r\n";
        }

        $body .= "--{$mixedBoundary}--\r\n";

        return $body;
    }

    private function prepareAttachments(array $attachments): array
    {
        $prepared = [];

        foreach ($attachments as $attachment) {
            $path = null;
            $name = null;
            $mime = null;

            if (is_string($attachment)) {
                $path = $attachment;
            } elseif (is_array($attachment)) {
                $path = $attachment['path'] ?? null;
                $name = $attachment['name'] ?? null;
                $mime = $attachment['mime'] ?? null;
            }

            if (!$path || !is_string($path)) {
                return [
                    'status' => 400,
                    'message' => 'Each attachment must have a valid path.',
                ];
            }

            if (!is_file($path) || !is_readable($path)) {
                return [
                    'status' => 400,
                    'message' => 'Attachment not found or not readable: ' . $path,
                ];
            }

            $rawContent = file_get_contents($path);
            if ($rawContent === false) {
                return [
                    'status' => 500,
                    'message' => 'Failed to read attachment: ' . $path,
                ];
            }

            $detectedMime = $mime ?: $this->detectMimeType($path);
            $fileName = $this->sanitizeFileName($name ?: basename($path));

            $prepared[] = [
                'name' => $fileName,
                'mime' => $detectedMime,
                'content' => chunk_split(base64_encode($rawContent)),
            ];
        }

        return [
            'status' => 200,
            'data' => $prepared,
        ];
    }

    private function detectMimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && trim($mime) !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        $fileName = str_replace(["\r", "\n"], '', $fileName);

        return $fileName !== '' ? $fileName : 'attachment.bin';
    }

    private function escapeHeaderValue(string $value): string
    {
        return str_replace('"', '', $value);
    }

    private function filterValidEmails(array $emails): array
    {
        $validEmails = [];

        foreach ($emails as $email) {
            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validEmails[] = $email;
            }
        }

        return $validEmails;
    }

    private function formatAddress(string $email, string $name): string
    {
        $encodedName = '=?UTF-8?B?' . base64_encode($name) . '?=';
        return $encodedName . ' <' . $email . '>';
    }
}
