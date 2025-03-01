<?php
//require("locations.php");

// Cél URL
$url = "https://loswebapi.expressone.hu/Los/AddLockerStation";
$token = "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6Ik9EYzJNakUzTVRJeE9RIn0.eyJyb2xlIjoic3lzdGVtIiwiZW1waWQiOiJQbkJNWmhrQ0JSNXcxQWxBZHRjM2FuamZub25ndHIyOGpqeHRjeDBHNVpzPSIsInVuaWQiOiI4OFJFOEI3Uy9MZlRYUVdscTc3amRBPT0iLCJ1aWQiOiJOWUJNd09FcWIwWnFUZUowWVkyd1JmZHFSVlpURVF3aWs4WkFEQ2VjL1dzPSIsImp0aSI6IjRhMjQ5NjNjLTgzNmEtNDBjOC1iNWYxLWYyMDk2NDA2YmU5NiIsImhpZCI6Ijg4UkU4QjdTL0xmVFhRV2xxNzdqZEE9PSIsImJpZCI6Ijg4UkU4QjdTL0xmVFhRV2xxNzdqZEE9PSIsImZuIjoiRm5KbTJlMDhXMjhDeDEraUduZEdJWFVWRkI1ZE1CYWhkdTZrNDF3QkdvUT0iLCJuYmYiOjE3Mzk0MzYxMTQsImV4cCI6MTczOTUwMjAwMCwiaWF0IjoxNzM5NDM2MTE0LCJpc3MiOiJhcmFza2FyZ28uY29tLnRyIiwiYXVkIjoiYXJhc2thcmdvLmNvbS50ciJ9.OvmhDzszZxR0EQnaEabLu3hzz-1ELHRd9toA9wgW_nFBHvC-Qo3VVR-bfrzW4l4Ef7a4VK1HHDioUR6PVW5HDk-zz3Di3megQVM7LHC6OTYAOrw_UwkvVEi2jTkDSKduldtn5HaVaIFCBVmmSboZVUuMmjJjvpNqwTrJd84YtMO7FVRB23E88si4-Z30pLgM_W9EO-K0WHEAVwa6wrezfZamRgTXjbfUi23qScQRWBwMqia1-oy5rTpW2izdFJBPMS3zA1kXF2el8uYqC-V0lhX_gdQNI5OuSPdC0Dh2aT2_ePxWqAd89mDepxQ-O5sYRkjuH2kSWAS1an4Sv-Lp_w";

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
