#!/usr/bin/env php
<?php
/**
 * Kereskedésnevek kinyerése HTML-ből (stdin vagy fájl)
 */

// Ha van parancssori argumentum, az a fájlnév
if ($argc > 1) {
    $htmlFilePath = $argv[1];
    if (!file_exists($htmlFilePath)) {
        die("❌ Hiba: A '$htmlFilePath' fájl nem található!\n\nHasználat:\n  php extract_simple.php <html_fájl>\n  vagy\n  cat file.html | php extract_simple.php\n");
    }
    $htmlContent = file_get_contents($htmlFilePath);
    echo "HTML fájl beolvasása: $htmlFilePath\n";
} else {
    // stdin-ről olvas
    echo "HTML tartalom beolvasása stdin-ről...\n";
    $htmlContent = stream_get_contents(STDIN);
    if (empty($htmlContent)) {
        die("❌ Hiba: Nincs input!\n\nHasználat:\n  php extract_simple.php <html_fájl>\n  vagy\n  cat file.html | php extract_simple.php\n");
    }
}

// DOMDocument használata
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($htmlContent);
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$elements = $xpath->query("//a[contains(@class, 'kereskedeslista_nev')]");

$names = [];
foreach ($elements as $element) {
    $name = trim($element->textContent);
    $href = $element->getAttribute('href');
    $names[] = [
        'név' => $name,
        'url' => $href
    ];
}

echo "Talált kereskedések száma: " . count($names) . "\n\n";

if (empty($names)) {
    die("❌ Nem találtam kereskedéseket!\n");
}

// CSV fájlba írás
$outputCsv = 'kereskedesek_pest_megye.csv';
$fp = fopen($outputCsv, 'w');

// BOM UTF-8-hoz
fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

// Fejléc
fputcsv($fp, ['Kereskedés neve', 'URL'], ',', '"', '');

// Adatok
foreach ($names as $row) {
    fputcsv($fp, [$row['név'], $row['url']], ',', '"', '');
}

fclose($fp);

echo "✅ CSV mentve: $outputCsv\n\n";

// Első 10 kereskedés
echo "Első 10 kereskedés:\n";
echo str_repeat('-', 80) . "\n";
for ($i = 0; $i < min(10, count($names)); $i++) {
    printf("%3d. %s\n", $i + 1, $names[$i]['név']);
    printf("     %s\n", $names[$i]['url']);
}

echo "\n✅ Kész! Nyisd meg Excelben a $outputCsv fájlt!\n";
