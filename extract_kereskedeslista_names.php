<?php
/**
 * Kereskedésnevek kinyerése HTML-ből és mentés Excel (CSV) fájlba
 * PHP verzió - nem igényel külső könyvtárakat
 */

// Konfiguráció
$htmlFilePath = 'kereskedesek.html'; // IDE írd a mentett HTML fájl nevét!
$outputCsvPath = 'kereskedesek_lista.csv';
$outputXlsxPath = 'kereskedesek_lista.xlsx';

echo "HTML fájl beolvasása: $htmlFilePath\n";

// HTML beolvasása
if (!file_exists($htmlFilePath)) {
    die("❌ Hiba: A '$htmlFilePath' fájl nem található!\n");
}

$htmlContent = file_get_contents($htmlFilePath);

// DOMDocument használata a parsáláshoz
$dom = new DOMDocument();
libxml_use_internal_errors(true); // Hibák elnyomása
$dom->loadHTML($htmlContent);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

// Kereskedés nevek kigyűjtése class alapján
$elements = $xpath->query("//a[contains(@class, 'kereskedeslista_nev')]");

$names = [];
foreach ($elements as $element) {
    $name = trim($element->textContent);
    $href = $element->getAttribute('href');
    $names[] = [
        'Kereskedés neve' => $name,
        'URL' => $href
    ];
}

echo "Talált kereskedések száma: " . count($names) . "\n";

if (empty($names)) {
    die("❌ Nem találtam kereskedéseket!\n");
}

// CSV fájlba írás (Excel is meg tudja nyitni)
$fp = fopen($outputCsvPath, 'w');

// BOM hozzáadása UTF-8 felismeréshez Excelben
fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

// Fejléc
fputcsv($fp, ['Kereskedés neve', 'URL']);

// Adatok
foreach ($names as $row) {
    fputcsv($fp, $row);
}

fclose($fp);

echo "✅ CSV mentve: $outputCsvPath\n";

// Ha van composer PhpSpreadsheet telepítve, XLSX létrehozása
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kereskedések');
        
        // Fejléc
        $sheet->setCellValue('A1', 'Kereskedés neve');
        $sheet->setCellValue('B1', 'URL');
        
        // Adatok
        $row = 2;
        foreach ($names as $data) {
            $sheet->setCellValue('A' . $row, $data['Kereskedés neve']);
            $sheet->setCellValue('B' . $row, $data['URL']);
            $row++;
        }
        
        // Oszlopszélesség automatikus
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($outputXlsxPath);
        
        echo "✅ XLSX mentve: $outputXlsxPath\n";
    }
}

// Első 5 kereskedés megjelenítése
echo "\nElső 5 kereskedés:\n";
echo str_repeat('-', 80) . "\n";
for ($i = 0; $i < min(5, count($names)); $i++) {
    echo ($i + 1) . ". " . $names[$i]['Kereskedés neve'] . "\n";
    echo "   URL: " . $names[$i]['URL'] . "\n";
}

echo "\n✅ Kész!\n";
