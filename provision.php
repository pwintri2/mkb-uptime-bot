<?php
/**
 * VPS Provisioning & Billing Webhook Ontvanger (/mkb-uptime-bot/provision.php)
 *
 * Wordt aangeroepen vanaf de gehoste aanmeldpagina op philip-wintrip.com (of eigen domein).
 * Omdat Nginx op philip-wintrip.nl .php-bestanden direct via php8.1-fpm uitvoert,
 * kan philip-wintrip.com hiermee server-to-server communiceren met behulp van het
 * geheime toegangsnummer (X-Access-Code).
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Gebruik HTTP POST.']);
    exit;
}

$action = $_GET['action'] ?? 'provision';
$endpoint = ($action === 'subscription-status')
    ? 'http://127.0.0.1:8815/api/subscription-status'
    : 'http://127.0.0.1:8815/api/provision';

$rawBody = file_get_contents('php://input');
$accessCode = $_SERVER['HTTP_X_ACCESS_CODE'] ?? '';

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
];
if ($accessCode !== '') {
    $headers[] = 'X-Access-Code: ' . $accessCode;
}

$opts = [
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $rawBody,
        'ignore_errors' => true,
        'timeout' => 20,
    ],
];

$context = stream_context_create($opts);
$response = @file_get_contents($endpoint, false, $context);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Lokale FastAPI worker op poort 8815 niet bereikbaar.']);
    exit;
}

$statusCode = 200;
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $hdr) {
        if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $hdr, $m)) {
            $statusCode = (int)$m[1];
        }
    }
}

http_response_code($statusCode);
echo $response;
