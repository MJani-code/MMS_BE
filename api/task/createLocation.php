<?php
require('../../functions/taskFunctions.php');

//require("locations.php");

// Cél URL
$url = "https://loswebapi.expressone.hu/Los/AddLockerStation";
$token = "";

// Iteráció a tömbön
foreach ($locations as $data) {
    // cURL munkamenet inicializálása
    $curl = curl_init();

    // cURL opciók beállítása
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
    ]);

    // A kérés elküldése
    //$response = curl_exec($curl);

    // Hibakezelés
    if (curl_errno($curl)) {
        error_log(localizeErrorMessage('errors.unexpected'));
        print_r($data);
        error_log(curl_error($curl));
    } else {
        error_log($response);
    }

    // cURL munkamenet lezárása
    curl_close($curl);
}
