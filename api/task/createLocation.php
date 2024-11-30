<?php
//require("locations.php");

// Cél URL
$url = "https://loswebapi.expressone.hu/Los/AddLockerStation";
$token = "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6Ik9EYzJNakUzTVRJeE9RIn0.eyJyb2xlIjoic3lzdGVtIiwiZW1waWQiOiJBb1N3ZEZYU0FESlkxRVJvNCthQ0FBPT0iLCJ1bmlkIjoiODhSRThCN1MvTGZUWFFXbHE3N2pkQT09IiwidWlkIjoiRkNBRVYwakpzd0liNktoS2NkRHdOMjZEaFNpb2liMFNlSFdwRUpiNmlhWT0iLCJqdGkiOiJkY2EwNTgzZC0yZjVmLTRhY2ItOTRjNC1lNWZhZDBhZDUyODMiLCJoaWQiOiI4OFJFOEI3Uy9MZlRYUVdscTc3amRBPT0iLCJiaWQiOiI4OFJFOEI3Uy9MZlRYUVdscTc3amRBPT0iLCJmbiI6ImVSRXNrTVRHU0UvNGxJWDljaXo5QXc9PSIsIm5iZiI6MTczMjg2NDg0MiwiZXhwIjoxNzMyOTM1NjAwLCJpYXQiOjE3MzI4NjQ4NDIsImlzcyI6ImFyYXNrYXJnby5jb20udHIiLCJhdWQiOiJhcmFza2FyZ28uY29tLnRyIn0.a8TEwRa_RG5Olq98UkRZ4HZO1WbFtAnS8CgKwWQrya-GIlJOXNqutL_Na1pD0ZXs1pQx7yHnppcMnkrc2oXsDyel_cCmsBAnjscj7aSyjgrZLvgXwFnC3SZgdEMidlV_BYO7_Ghl1goQIGNFJJ0z5N1ZPvUkxqOiXEcwpwIgRn8bWLTXctOurRBDuRX-Yskdlnxuqn_EHpvn9obBsl1mxvQy5DoX1oq1G0Cv-RrjrHOk_Rl-Krkd_Kb6MJLjDxdItlC-7H0rSRfAgXcM2wl4kIsXVGPBLGKnnq5STotJw6tgBS2u-38t2x6jx9okqPwAOK4HM0BP_4CqMXQcWV_6Ow";

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
