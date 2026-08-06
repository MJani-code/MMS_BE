<?php

/**
 * Kigyűjti a hibák mennyiségét helyszínenként a locker adatokból
 * 
 * @param array $data - A teljes API válasz vagy csak a resultList tömbje
 * @return array - Indexelt tömb objektumokkal, amelyek tartalmazzák a lockerStationId-t és faultyCompartmentCount-ot
 */
function getErrorCountByLocation($data)
{
    // Ha a teljes API választ kapjuk, kivesszük a resultList-et
    $resultList = isset($data['payload']['items']) ? $data['payload']['items'] : $data;

    $errorsByLocation = [];

    // Végigmegyünk minden helyszínen
    foreach ($resultList as $location) {
        $lockerStationId = $location['lockerStationId'];
        $notAvailableCount = 0;
        $errorsByStatus = [];
        $totalCompartmentCount = 0;

        // Végigmegyünk minden locker-en a helyszínen
        if (isset($location['lockerList']) && is_array($location['lockerList'])) {
            foreach ($location['lockerList'] as $locker) {
                // Végigmegyünk a rekeszeken (compartment)
                if (isset($locker['compartmentList']) && is_array($locker['compartmentList'])) {
                    $totalCompartmentCount += count($locker['compartmentList']);

                    foreach ($locker['compartmentList'] as $compartment) {
                        // Ellenőrizzük a status mezőt
                        if (isset($compartment['status'])) {
                            $status = $compartment['status'];
                            // Hibás statusok: status != 0 és != 1
                            // (0 = None, 1 = Empty - ezek nem hibák)
                            // Minden más status hibának számít
                            if ($status != 0 && $status != 1) {
                                $notAvailableCount++;

                                // Status szerinti számláló
                                if (!isset($errorsByStatus[$status])) {
                                    $errorsByStatus[$status] = 0;
                                }
                                $errorsByStatus[$status]++;
                            }
                        }
                    }
                }
            }
        }

        // Tároljuk az eredményt indexelt tömbben objektumként
        $locationData = [
            'lockerStationId' => $lockerStationId,
            'totalCompartmentCount' => $totalCompartmentCount,
            'totalOfNotAvailable' => $notAvailableCount,
            'Reserved' => 0,
            'Occupied' => 0,
            'OutOfOrder' => 0,
            'PaymentRequired' => 0,
            'DeliveryCancel' => 0,
            'PinCodeSetPending' => 0,
            'DeliveryCancelorExpireSetPending' => 0,
            'Expired' => 0,
            'PinCodeSetPendingforCustomerDropOff' => 0,
            'CustomerCanDropOff' => 0,
            'CustomerDroppedOff' => 0,
            'CompartmentOpened' => 0
        ];

        // Hozzáadjuk a status szerinti bontást külön kulcsokként
        foreach ($errorsByStatus as $status => $count) {
            $statusName = getStatusDescription($status);
            // Eltávolítjuk a speciális karaktereket a kulcsból
            $statusKey = str_replace(' ', '', $statusName);
            $locationData[$statusKey] = $count;
        }

        $errorsByLocation[] = $locationData;
    }

    return $errorsByLocation;
}

/**
 * Kigyujti az empty (status = 1) rekeszek szamat helyszinenkent,
 * valamint az osszes rekesz szamat es a meret szerinti bontast.
 *
 * @param array $data - A teljes API valasz vagy csak a location tomb
 * @return array
 */
function getAvailableCompartmentCountByLocation($data)
{
    $availableByLocation = [];
    $losSizeMap = [
        1 => 'small',
        2 => 'medium',
        3 => 'big'
    ];
    $d4meSizeMap = [
        10 => 'small',
        20 => 'medium',
        30 => 'big',
        40 => 'extraLarge'
    ];

    $createSizeBucket = function () {
        return [
            'small' => 0,
            'medium' => 0,
            'big' => 0,
            'extraLarge' => 0
        ];
    };

    $mergeByLocation = function ($lockerStationId, $totalCompartmentCount, $emptyCompartmentCount, $totalBySize, $emptyBySize) use (&$availableByLocation, $createSizeBucket) {
        $locationKey = $lockerStationId === null ? '__null__' : (string)$lockerStationId;

        if (!isset($availableByLocation[$locationKey])) {
            $availableByLocation[$locationKey] = [
                'lockerStationId' => $lockerStationId,
                'totalCompartmentCount' => 0,
                'totalOfEmpty' => 0,
                // 'totalBySize' => $createSizeBucket(),
                'emptyBySize' => $createSizeBucket()
            ];
        }

        $availableByLocation[$locationKey]['totalCompartmentCount'] += $totalCompartmentCount;
        $availableByLocation[$locationKey]['totalOfEmpty'] += $emptyCompartmentCount;

        foreach ($emptyBySize as $sizeKey => $count) {
            if (!isset($availableByLocation[$locationKey]['emptyBySize'][$sizeKey])) {
                $availableByLocation[$locationKey]['emptyBySize'][$sizeKey] = 0;
            }
            $availableByLocation[$locationKey]['emptyBySize'][$sizeKey] += $count;
        }
    };

    $losResultList = isset($data['payload']['items']) && is_array($data['payload']['items'])
        ? $data['payload']['items']
        : (is_array($data) ? $data : []);

    foreach ($losResultList as $location) {
        if (!is_array($location) || !isset($location['lockerList']) || !is_array($location['lockerList'])) {
            continue;
        }

        $lockerStationId = $location['lockerStationId'] ?? null;
        $totalCompartmentCount = 0;
        $emptyCompartmentCount = 0;
        $totalBySize = $createSizeBucket();
        $emptyBySize = $createSizeBucket();

        foreach ($location['lockerList'] as $locker) {
            if (!isset($locker['compartmentList']) || !is_array($locker['compartmentList'])) {
                continue;
            }

            $totalCompartmentCount += count($locker['compartmentList']);

            foreach ($locker['compartmentList'] as $compartment) {
                $size = $compartment['size'] ?? null;
                $sizeKey = isset($losSizeMap[$size]) ? $losSizeMap[$size] : null;

                if ($sizeKey !== null) {
                    $totalBySize[$sizeKey]++;
                }

                if (isset($compartment['status']) && (int)$compartment['status'] === 1) {
                    $emptyCompartmentCount++;
                    if ($sizeKey !== null) {
                        $emptyBySize[$sizeKey]++;
                    }
                }
            }
        }

        $mergeByLocation($lockerStationId, $totalCompartmentCount, $emptyCompartmentCount, $totalBySize, $emptyBySize);
    }

    $d4meResultList = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
    if (empty($d4meResultList) && is_array($data) && isset($data[0]) && is_array($data[0]) && isset($data[0]['multiSpaceDoors'])) {
        $d4meResultList = $data;
    }

    foreach ($d4meResultList as $box) {
        if (!is_array($box) || !isset($box['multiSpaceDoors']) || !is_array($box['multiSpaceDoors'])) {
            continue;
        }

        $lockerStationId = $box['locationId'] ?? null;
        $totalCompartmentCount = 0;
        $emptyCompartmentCount = 0;
        $totalBySize = $createSizeBucket();
        $emptyBySize = $createSizeBucket();

        foreach ($box['multiSpaceDoors'] as $door) {
            if (!is_array($door)) {
                continue;
            }

            $totalCompartmentCount++;

            $size = $door['boxSizeType'] ?? null;
            $sizeKey = isset($d4meSizeMap[$size]) ? $d4meSizeMap[$size] : null;
            if ($sizeKey !== null) {
                $totalBySize[$sizeKey]++;
            }

            if (isset($door['doorAvailability']) && (int)$door['doorAvailability'] === 1) {
                $emptyCompartmentCount++;
                if ($sizeKey !== null) {
                    $emptyBySize[$sizeKey]++;
                }
            }
        }

        $mergeByLocation($lockerStationId, $totalCompartmentCount, $emptyCompartmentCount, $totalBySize, $emptyBySize);
    }

    return array_values($availableByLocation);
}

/**
 * Részletes hibaelemzés helyszínenként
 * Visszaadja a hibák részletes lebontását status kód szerint is
 * 
 * @param array $data - A teljes API válasz vagy csak a resultList tömbje
 * @return array - Részletes információk helyszínenként
 */
function getDetailedErrorsByLocation($data)
{
    $resultList = isset($data['payload']['resultList']) ? $data['payload']['resultList'] : $data;

    $detailedErrors = [];

    foreach ($resultList as $location) {
        $lockerStationId = $location['lockerStationId'];
        $locationName = $location['lockerDisplayName'] ?? $location['lockerName'] ?? 'N/A';
        $address = $location['address'] ?? 'N/A';

        $errorsByStatus = [];
        $notAvailableCount = 0;

        if (isset($location['lockerList']) && is_array($location['lockerList'])) {
            foreach ($location['lockerList'] as $locker) {
                if (isset($locker['compartmentList']) && is_array($locker['compartmentList'])) {
                    foreach ($locker['compartmentList'] as $compartment) {
                        if (isset($compartment['status'])) {
                            $status = $compartment['status'];
                            // Hibásnak számító statusok
                            if ($status) {
                                if (!isset($errorsByStatus[$status])) {
                                    $errorsByStatus[$status] = 0;
                                }
                                $errorsByStatus[$status]++;
                                $notAvailableCount++;
                            }
                        }
                    }
                }
            }
        }

        $detailedErrors[$lockerStationId] = [
            'lockerStationId' => $lockerStationId,
            'locationName' => $locationName,
            'address' => $address,
            'totalOfNotAvailable' => $notAvailableCount,
            'errorsByStatus' => $errorsByStatus
        ];
    }

    return $detailedErrors;
}

/**
 * Status kódok jelentése (referencia)
 * 
 * 0  = Szabad (Available)
 * 1  = Normál foglalt (Occupied normally)
 * 12 = Foglalt (Reserved/Occupied with issues)
 * További status kódok igény szerint
 */
function getStatusDescription($statusCode)
{
    $statusDescriptions = [
        0 => 'None',
        1 => 'Empty',
        2 => 'Reserved',
        3 => 'Occupied',
        4 => 'OutOfOrder',
        5 => 'Payment Required',
        6 => 'Delivery Cancel',
        7 => 'Pin Code Set Pending',
        9 => 'Delivery Cancel or Expire Set Pending',
        10 => 'Expired',
        11 => 'Pin Code Set Pending for Customer Drop Off',
        12 => 'Customer Can Drop Off',
        13 => 'Customer Dropped Off',
        14 => 'Compartment Opened'
        // További status kódok hozzáadhatók igény szerint
    ];

    return $statusDescriptions[$statusCode] ?? "Ismeretlen status ($statusCode)";
}

// Példa használat:
/*
// JSON file beolvasása
$jsonData = file_get_contents('untitled:Untitled-1');
$data = json_decode($jsonData, true);

// Egyszerű hiba összesítés
$errors = getErrorCountByLocation($data);
foreach ($errors as $locationId => $errorCount) {
    echo "Helyszín $locationId: $errorCount hiba\n";
}

// Részletes elemzés
$detailedErrors = getDetailedErrorsByLocation($data);
foreach ($detailedErrors as $locationId => $info) {
    echo "\nHelyszín: {$info['locationName']} (ID: {$info['lockerStationId']})\n";
    echo "Cím: {$info['address']}\n";
    echo "Összesen hibák: {$info['totalErrors']}\n";
    if ($info['totalErrors'] > 0) {
        echo "Hibák status szerint:\n";
        foreach ($info['errorsByStatus'] as $status => $count) {
            echo "  - Status $status: $count db\n";
        }
    }
}
*/
