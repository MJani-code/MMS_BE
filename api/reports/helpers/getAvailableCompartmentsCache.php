<?php

function gacCreateResponse($statusCode, $message, $data = null)
{
    return [
        'status' => $statusCode,
        'message' => $message,
        'payload' => $data
    ];
}

function gacGetStorageDir($type)
{
    $baseDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mms_api' . DIRECTORY_SEPARATOR . $type;
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }

    return $baseDir;
}

function gacReadJsonFile($filePath)
{
    if (!is_file($filePath)) {
        return null;
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function gacWriteJsonFile($filePath, array $payload)
{
    $encoded = json_encode($payload);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($filePath, $encoded, LOCK_EX) !== false;
}

function gacRequestHasMatchingEtag($etag)
{
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === '') {
        return false;
    }

    $candidates = array_map('trim', explode(',', $ifNoneMatch));
    foreach ($candidates as $candidate) {
        if ($candidate === '*' || $candidate === $etag) {
            return true;
        }
    }

    return false;
}

function gacEmitResponse(array $responseData, $ttlSeconds, $staleSeconds, $ageSeconds, $cacheStatus)
{
    $json = json_encode($responseData);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(gacCreateResponse(500, 'Response encoding failed'));
        exit;
    }

    $etag = '"' . sha1($json) . '"';
    header('Cache-Control: private, max-age=' . (int)$ttlSeconds . ', stale-while-revalidate=' . (int)$staleSeconds);
    header('ETag: ' . $etag);
    header('X-Cache: ' . $cacheStatus);
    header('Age: ' . max(0, (int)$ageSeconds));

    if (gacRequestHasMatchingEtag($etag)) {
        http_response_code(304);
        exit;
    }

    $statusCode = isset($responseData['status']) ? (int)$responseData['status'] : 200;
    if ($statusCode < 100 || $statusCode > 599) {
        $statusCode = 200;
    }

    http_response_code($statusCode);
    echo $json;
    exit;
}

function gacLoadCachedResponse($cacheFilePath)
{
    $cached = gacReadJsonFile($cacheFilePath);
    if (!$cached || !isset($cached['createdAt'], $cached['response']) || !is_array($cached['response'])) {
        return null;
    }

    $age = time() - (int)$cached['createdAt'];
    if ($age < 0) {
        $age = 0;
    }

    return [
        'age' => $age,
        'response' => $cached['response']
    ];
}

function gacRateLimitFilePath($rateLimitKey)
{
    return gacGetStorageDir('rate_limit') . DIRECTORY_SEPARATOR . $rateLimitKey . '.json';
}

function gacCacheFilePath($cacheKey)
{
    return gacGetStorageDir('cache') . DIRECTORY_SEPARATOR . $cacheKey . '.json';
}

function gacIsRateLimited($rateLimitKey, $windowSeconds, $maxRequests)
{
    $rateFile = gacRateLimitFilePath($rateLimitKey);
    $now = time();
    $rateData = gacReadJsonFile($rateFile);

    if (!$rateData || !isset($rateData['windowStart'], $rateData['count'])) {
        $rateData = [
            'windowStart' => $now,
            'count' => 0
        ];
    }

    if (($now - (int)$rateData['windowStart']) >= $windowSeconds) {
        $rateData['windowStart'] = $now;
        $rateData['count'] = 0;
    }

    $rateData['count'] = (int)$rateData['count'] + 1;
    gacWriteJsonFile($rateFile, $rateData);

    return [
        'limited' => $rateData['count'] > $maxRequests,
        'retryAfter' => max(1, $windowSeconds - ($now - (int)$rateData['windowStart']))
    ];
}
