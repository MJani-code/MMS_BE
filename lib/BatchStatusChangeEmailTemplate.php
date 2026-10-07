<?php

class BatchStatusChangeEmailTemplate
{
    public static function render($subject, $body, $statusName, array $tasks)
    {
        $escape = static function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $safeSubject = $escape($subject);
        $safeBody = $escape($body);
        $safeStatusName = $escape($statusName ?: 'Nincs megadva');
        $taskRows = '';

        foreach ($tasks as $task) {
            $taskId = $escape($task['taskId'] ?? '');
            $locationName = $escape($task['locationName'] ?: 'Helyszín nincs megadva');
            $taskTypeNames = $escape($task['taskTypeNames'] ?: 'Feladattípus nincs megadva');
            $boxId = $escape($task['boxId'] ?: 'Nincs megadva');
            $tofShopId = $escape($task['tofShopId'] ?: 'Nincs megadva');
            $statusName = $escape($task['statusName'] ?: 'Státusz nincs megadva');
            $lockerInterventionNotes = $escape($task['lockerInterventionNotes'] ?: 'Nincs rögzített beavatkozás');
            $taskRows .= '
                <tr>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;font-weight:bold;white-space:nowrap;">#' . $taskId . '</td>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;">' . $locationName . '</td>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;color:#64748b;">' . $taskTypeNames . '</td>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;color:#64748b;">' . $boxId . '</td>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;color:#64748b;">' . $tofShopId . '</td>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;color:#64748b;">' . $statusName . '</td>
                    <td style="padding:15px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;color:#64748b;">' . $lockerInterventionNotes . '</td>
                </tr>';
        }

        return '
            <!doctype html>
            <html lang="hu">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>' . $safeSubject . '</title>
            </head>
            <body style="margin:0;padding:0;background-color:#fff8f1;font-family:Arial,Helvetica,sans-serif;color:#172033;">
                <div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $safeBody . '</div>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#fff8f1;padding:32px 12px;">
                    <tr>
                        <td align="center">
                            <table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:680px;background-color:#ffffff;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <td style="padding:26px 32px;background-color:#f07b00;color:#ffffff;">
                                        <div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:#fff0df;">MMS • Csoportos értesítés</div>
                                        <div style="margin-top:8px;font-size:24px;font-weight:bold;line-height:1.3;">Tömeges státuszfrissítés</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:32px;">
                                        <p style="margin:0 0 22px;font-size:16px;line-height:1.6;">' . $safeBody . '</p>
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:24px;">
                                            <tr>
                                                <td style="padding:8px 14px;border-radius:20px;background-color:#fff0df;color:#a94e00;font-size:14px;font-weight:bold;">' . count($tasks) . ' feladat frissült</td>
                                                <td style="padding-left:12px;font-size:14px;color:#64748b;">Új státusz: <strong style="color:#a94e00;">' . $safeStatusName . '</strong></td>
                                            </tr>
                                        </table>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #f2dfcc;border-radius:8px;border-collapse:separate;border-spacing:0;overflow:hidden;">
                                            <thead>
                                                <tr style="background-color:#fffaf5;">
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">Feladat</th>
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">Helyszín</th>
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">Feladattípus</th>
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">Box ID</th>
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">TOF Shop ID</th>
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">Státusz</th>
                                                    <th align="left" style="padding:12px 16px;color:#64748b;font-size:12px;text-transform:uppercase;">Beavatkozás jegyzet</th>
                                                </tr>
                                            </thead>
                                            <tbody>' . $taskRows . '</tbody>
                                        </table>
                                        <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#64748b;">Ez egy automatikus értesítés a feladatok adatainak változásáról.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:18px 32px;background-color:#fffaf5;border-top:1px solid #f2dfcc;color:#94a3b8;font-size:12px;text-align:center;">
                                        MMS – Feladatkezelő rendszer
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';
    }
}
