<?php
/**
 * Lichtgewicht reverse-proxy brug naar de lokale FastAPI & APScheduler daemon (127.0.0.1:8815).
 * Hierdoor werkt https://philip-wintrip.nl/mkb-uptime-bot/ direct zonder sudo/Nginx-herstart.
 */
$backendBase = 'http://127.0.0.1:8815';
$route = isset($_GET['r']) && $_GET['r'] !== '' ? $_GET['r'] : '/';
if ($route[0] !== '/') {
    $route = '/' . $route;
}

// Voeg eventuele extra query-parameters (buiten 'r') toe
$queryParams = $_GET;
unset($queryParams['r']);
$queryString = http_build_query($queryParams);
$targetUrl = $backendBase . $route . ($queryString ? ('?' . $queryString) : '');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$headers = [
    'X-Forwarded-Prefix: /mkb-uptime-bot/app.php?r=',
    'X-Forwarded-Proto: https',
];

if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    $headers[] = 'HX-Request: ' . $_SERVER['HTTP_HX_REQUEST'];
}
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}

$rawBody = file_get_contents('php://input');

$opts = [
    'http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $rawBody,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 25,
    ],
];

$context = stream_context_create($opts);
$responseBody = @file_get_contents($targetUrl, false, $context);

if ($responseBody === false) {
    http_response_code(502);
    echo "<h1>MKB Uptime Bot service start op...</h1><p>Controleer of mkb-uptime-bot.service draait op poort 8815.</p>";
    exit;
}

$statusCode = 200;
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $hdr) {
        if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $hdr, $m)) {
            $statusCode = (int)$m[1];
        } elseif (stripos($hdr, 'Location:') === 0) {
            header($hdr);
        } elseif (stripos($hdr, 'Content-Type:') === 0) {
            header($hdr);
        }
    }
}

http_response_code($statusCode);
echo $responseBody;
