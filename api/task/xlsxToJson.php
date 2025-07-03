<?php
//utf-8 karakterkódolás beállítása
header('Content-Type: application/json; charset=utf-8');
require('../../vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

error_reporting(E_ALL);
ini_set('display_errors', 1);

//mappában található xlsx file hozzáadása

$inputFileName = 'Location_Hunting_PERMIT_IN_PROCESS_1750769523.xlsx';
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inputFileName);

//utf-8 karakterkódolás beállítása
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial');
$spreadsheet->getDefaultStyle()->getFont()->setSize(10);


// A fájl feldolgozása itt
// JSON formátumra konvertálás ahol a header a kulcsok
$data = [];
$timeTable = [
    [
        "workingDay" => "Monday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ],
    [
        "workingDay" => "Tuesday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ],
    [
        "workingDay" => "Wednesday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ],
    [
        "workingDay" => "Thursday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ],
    [
        "workingDay" => "Friday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ],
    [
        "workingDay" => "Saturday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ],
    [
        "workingDay" => "Sunday",
        "startTime" => "00:00",
        "endTime" => "23:59"
    ]
];
foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
    $sheetData = $worksheet->toArray();
    $header = array_shift($sheetData);
    foreach ($sheetData as $row) {
        $data[] = array_combine($header, $row);
        //hozzáadni egy fix kulcs értékpárt
        $data[count($data) - 1]['CountryCode'] = 'HU';
        $data[count($data) - 1]['DropOffCapability'] = 'true';
        $data[count($data) - 1]['Latitude'] = '47.525803789831734';
        $data[count($data) - 1]['Longitude'] = '19.084';
        $data[count($data) - 1]['TimeTable'] = $timeTable;
    }
}
echo json_encode($data);
// JSON fájlba írása
// $outputFileName = 'output.json';
// file_put_contents($outputFileName, json_encode($data, JSON_PRETTY_PRINT));
