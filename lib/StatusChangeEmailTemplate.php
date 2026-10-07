<?php

class StatusChangeEmailTemplate
{
    public static function render($subject, $body, array $taskDetails)
    {
        $escape = static function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $safeTaskId = $escape($taskDetails['taskId'] ?? '');
        $safeStatusName = $escape($taskDetails['statusName'] ?: 'Nincs megadva');
        $safeLocationName = $escape($taskDetails['locationName'] ?: 'Nincs megadva');
        $safeTaskTypeNames = $escape($taskDetails['taskTypeNames'] ?: 'Nincs megadva');
        $safeBoxId = $escape($taskDetails['boxId'] ?: 'Nincs megadva');
        $safeTofShopId = $escape($taskDetails['tofShopId'] ?: 'Nincs megadva');
        $safeLockerInterventionNotes = $escape($taskDetails['lockerInterventionNotes'] ?: 'Nincs megadva');
        $safeBody = $escape($body);
        $safeSubject = $escape($subject);

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
                            <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <td style="padding:24px 32px;background-color:#f07b00;color:#ffffff;">
                                        <div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:#fff0df;">MMS • Értesítés</div>
                                        <div style="margin-top:8px;font-size:24px;font-weight:bold;line-height:1.3;">Státuszváltozás</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:32px;">
                                        <p style="margin:0 0 20px;font-size:16px;line-height:1.6;">' . $safeBody . '</p>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #f2dfcc;border-radius:8px;">
                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #f2dfcc;color:#64748b;font-size:13px;">Feladat azonosítója</td>
                                                <td align="right" style="padding:14px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;font-weight:bold;">#' . $safeTaskId . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #f2dfcc;color:#64748b;font-size:13px;">Helyszín</td>
                                                <td align="right" style="padding:14px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;">' . $safeLocationName . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #f2dfcc;color:#64748b;font-size:13px;">Feladat típusa(i)</td>
                                                <td align="right" style="padding:14px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;">' . $safeTaskTypeNames . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #f2dfcc;color:#64748b;font-size:13px;">BoxId</td>
                                                <td align="right" style="padding:14px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;">' . $safeBoxId . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #f2dfcc;color:#64748b;font-size:13px;">Tof Shop ID</td>
                                                <td align="right" style="padding:14px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;">' . $safeTofShopId . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 16px;color:#64748b;font-size:13px;">Új státusz</td>
                                                <td align="right" style="padding:14px 16px;font-size:14px;">
                                                    <span style="display:inline-block;padding:6px 11px;border-radius:20px;background-color:#fff0df;color:#a94e00;font-weight:bold;">' . $safeStatusName . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 16px;border-bottom:1px solid #f2dfcc;color:#64748b;font-size:13px;">Locker beavatkozás jegyzetei</td>
                                                <td align="right" style="padding:14px 16px;border-bottom:1px solid #f2dfcc;font-size:14px;">' . $safeLockerInterventionNotes . '</td>
                                            </tr>
                                        </table>
                                        <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#64748b;">Ez egy automatikus értesítés a feladat adatainak változásáról.</p>
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
