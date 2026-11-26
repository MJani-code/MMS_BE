<?php

class D4meLocationsClient
{
    private const CONCURRENCY = 5;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 20;

    public static function createHandle($url, $token, $searchTerm)
    {
        if ($searchTerm !== '') {
            $url .= '&SearchTerm=' . urlencode($searchTerm) . '&SearchFields[]=id';
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => [
                'Content-Type:application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ]);
        return $handle;
    }

    public function getLocations($url, $token, array $boxIds, $log, $trace = null)
    {
        $uniqueIds = [];
        $missingIdCount = 0;
        foreach ($boxIds as $boxId) {
            $id = trim((string)$boxId);
            if ($id === '') {
                $missingIdCount++;
                continue;
            }
            $uniqueIds['id:' . $id] = $id;
        }
        $uniqueIds = array_values($uniqueIds);

        $multi = curl_multi_init();
        $active = [];
        $results = [];
        $failed = [];
        $next = 0;

        try {
            while ($next < count($uniqueIds) || $active) {
                while ($next < count($uniqueIds) && count($active) < self::CONCURRENCY) {
                    $id = $uniqueIds[$next];
                    $handle = self::createHandle($url, $token, $id);
                    curl_setopt_array($handle, [
                        CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                        CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                    ]);
                    $multiStatus = curl_multi_add_handle($multi, $handle);
                    if ($multiStatus !== CURLM_OK) {
                        curl_close($handle);
                        throw new RuntimeException('D4Me request could not be scheduled: ' . curl_multi_strerror($multiStatus));
                    }
                    $active[spl_object_id($handle)] = ['handle' => $handle, 'index' => $next, 'id' => $id];
                    $next++;
                    if ($trace) {
                        $trace('d4me_request_started', ['number' => $next]);
                    }
                }

                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
                if ($multiStatus !== CURLM_OK) {
                    throw new RuntimeException('D4Me requests failed: ' . curl_multi_strerror($multiStatus));
                }

                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $request = $active[spl_object_id($handle)];
                    $httpStatus = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $timings = [
                        'dns_ms' => round(curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME) * 1000, 1),
                        'connect_ms' => round(curl_getinfo($handle, CURLINFO_CONNECT_TIME) * 1000, 1),
                        'tls_ms' => round(curl_getinfo($handle, CURLINFO_APPCONNECT_TIME) * 1000, 1),
                        'ttfb_ms' => round(curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME) * 1000, 1),
                        'total_ms' => round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000, 1),
                    ];
                    $error = null;
                    $data = null;
                    if ($info['result'] !== CURLE_OK) {
                        $error = 'cURL error ' . $info['result'] . ': ' . curl_error($handle);
                    } elseif ($httpStatus < 200 || $httpStatus >= 300) {
                        $error = 'HTTP status ' . $httpStatus;
                    } else {
                        $data = json_decode(curl_multi_getcontent($handle), true);
                        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data']) || !is_array($data['data'])) {
                            $error = 'Invalid D4Me response';
                        } else {
                            $found = false;
                            foreach ($data['data'] as $point) {
                                if (is_array($point) && isset($point['id']) && (string)$point['id'] === $request['id']) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $error = 'Requested location missing from D4Me response';
                            }
                        }
                    }

                    if ($error !== null) {
                        $failed[$request['index']] = $request['id'];
                        $log->warning('D4Me location request failed', ['reason' => $error, 'http_status' => $httpStatus]);
                    }
                    if (isset($data['data']) && is_array($data['data'])) {
                        $results[$request['index']] = $data['data'];
                    }
                    if ($trace) {
                        $trace('d4me_request_finished', [
                            'number' => $request['index'] + 1,
                            'http_status' => $httpStatus,
                            'curl_code' => $info['result'],
                            'success' => $error === null,
                            'timings' => $timings,
                        ]);
                    }
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($active[spl_object_id($handle)]);
                }

                if ($active && ($next >= count($uniqueIds) || count($active) >= self::CONCURRENCY)
                    && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            }
        } finally {
            foreach ($active as $request) {
                curl_multi_remove_handle($multi, $request['handle']);
                curl_close($request['handle']);
            }
            curl_multi_close($multi);
        }

        ksort($results);
        ksort($failed);
        $points = [];
        foreach ($results as $items) {
            $points = array_merge($points, $items);
        }
        return [
            'data' => $points,
            'failed_box_ids' => array_values($failed),
            'missing_id_count' => $missingIdCount,
        ];
    }
}
