<?php
/**
 * Publiek Klantportaal Brug (/mkb-uptime-bot/klant.php?token=mkb_...)
 *
 * Toont geabonneerde MKB-klanten hun persoonlijke live statuspagina op basis van hun unieke token.
 */
$token = $_GET['token'] ?? '';
if (!preg_match('/^mkb_[A-Za-z0-9_\-]+$/', $token)) {
    http_response_code(403);
    echo "Ongeldige of ontbrekende klant-sleutel.";
    exit;
}

$targetUrl = 'http://127.0.0.1:8815/portal/' . rawurlencode($token);
$response = @file_get_contents($targetUrl);
if ($response === false) {
    http_response_code(404);
    echo "Klantportaal niet gevonden of service wordt herstart.";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $response;
