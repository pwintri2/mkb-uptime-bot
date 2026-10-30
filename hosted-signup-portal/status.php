<?php
/**
 * status.php (op philip-wintrip.com of eigen domein)
 * --------------------------------------------------
 * Toont aan de geabonneerde MKB-klant zijn persoonlijke live statusdashboard,
 * zónder dat de klant zelf op philip-wintrip.nl een pincode hoeft in te voeren!
 * De gehoste site haalt de pagina op de achtergrond op via wintrip_vps_auth.php.
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/wintrip_vps_auth.php';

$token = $_GET['token'] ?? '';
if (!preg_match('/^mkb_[A-Za-z0-9_\-]+$/', $token)) {
    http_response_code(403);
    echo "Ongeldige of ontbrekende klant-sleutel.";
    exit;
}

$baseUrl = rtrim($config['vps_base_url'] ?? 'https://philip-wintrip.nl', '/');
$targetUrl = $baseUrl . '/mkb-uptime-bot/klant.php?token=' . rawurlencode($token);

$res = wintripVpsRequest($config, $targetUrl, 'GET');
if (!$res['ok']) {
    http_response_code(502);
    echo "Kon statuspagina tijdelijk niet ophalen van monitoring-server.";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $res['body'];
