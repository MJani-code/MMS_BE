<?php
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
        echo "Hiba történt az alábbi objektum feldolgozása közben:\n";
        print_r($data);
        echo "Hiba: " . curl_error($curl) . "\n";
    } else {
        echo "Válasz: " . $response . "\n";
    }

    // cURL munkamenet lezárása
    curl_close($curl);
}
