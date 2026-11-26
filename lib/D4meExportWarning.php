<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function addD4meWarningSheet(Spreadsheet $spreadsheet, bool $d4meFailed, array $failedIds, int $missingIdCount): void
{
    if (!$d4meFailed && !$failedIds && !$missingIdCount) {
        return;
    }

    $warningSheet = $spreadsheet->createSheet(0);
    $warningSheet->setTitle('Figyelmeztetés');
    $warningSheet->setCellValue('A1', 'A D4Me adatok hiányosak.');
    $warningSheet->setCellValue('A2', 'Az adatlapon a D4Me képek, koordináták és rekeszszámok hiányozhatnak vagy eltérhetnek.');
    if ($d4meFailed) {
        $warningSheet->setCellValue('A4', 'A D4Me adatok lekérése nem sikerült. Ellenőrizze a szerver naplóját, majd próbálja újra.');
    } else {
        $warningSheet->setCellValue('A4', 'Sikertelen vagy hiányzó D4Me helyszínek száma: ' . count($failedIds));
        $warningSheet->setCellValue('A5', 'Hiányzó box_id értékű sorok száma: ' . $missingIdCount);
        if ($failedIds) {
            $warningSheet->setCellValue('A7', 'Érintett box_id értékek:');
            foreach ($failedIds as $index => $boxId) {
                $warningSheet->setCellValueExplicit('A' . ($index + 8), $boxId, DataType::TYPE_STRING);
            }
        }
    }
    $warningSheet->getColumnDimension('A')->setWidth(100);
    $spreadsheet->setActiveSheetIndex(0);
}
