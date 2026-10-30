<?php
/**
 * Verwerkt het aanmeldformulier op philip-wintrip.com (of eigen domein):
 * 1. Als Mollie API-sleutel is ingevuld: maakt een Mollie Customer + 1e iDEAL-betaling (sequenceType=first) aan
 *    voor automatische maandelijkse SEPA-afschrijving.
 * 2. Als Mollie API-sleutel nog leeg is (directe activatie / testmodus):
 *    logt direct met het geheime toegangsnummer (X-Access-Code) in op jouw VPS
 *    (https://philip-wintrip.nl/mkb-uptime-bot/provision.php) en maakt het account + 1-minuut monitor aan!
 */

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$planTier = strtolower(trim($_POST['plan_tier'] ?? 'pro'));
if (!isset($config['plans'][$planTier])) {
    $planTier = 'pro';
}
$planInfo = $config['plans'][$planTier];

$payload = [
    'access_code'         => $config['vps_access_code'],
    'company_name'        => trim($_POST['company_name'] ?? ''),
    'contact_email'       => trim($_POST['contact_email'] ?? ''),
    'plan_tier'           => $planTier,
    'monitor_name'        => trim($_POST['monitor_name'] ?? ''),
    'url'                 => trim($_POST['url'] ?? ''),
    'expected_keywords'   => trim($_POST['expected_keywords'] ?? ''),
    'check_form_selector' => trim($_POST['check_form_selector'] ?? 'form'),
    'telegram_chat_id'    => trim($_POST['telegram_chat_id'] ?? ''),
    'whatsapp_number'     => trim($_POST['whatsapp_number'] ?? ''),
    'payment_status'      => 'active',
    'run_initial_check'   => true,
];

if ($payload['company_name'] === '' || $payload['contact_email'] === '' || $payload['url'] === '') {
    http_response_code(400);
    echo "Vul alle verplichte velden (bedrijfsnaam, e-mail en URL) in.";
    exit;
}

/**
 * Hulpfunctie om met het toegangsnummer in te loggen op de VPS en het account aan te maken.
 */
function provisionOnVps(array $config, array $payload): array {
    $ch = curl_init($config['vps_provision_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Access-Code: ' . $config['vps_access_code'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Verbindingsfout naar VPS: ' . $err];
    }
    $decoded = json_decode($raw, true);
    if ($status >= 400 || !is_array($decoded) || empty($decoded['ok'])) {
        return [
            'ok' => false,
            'error' => $decoded['detail'] ?? $decoded['error'] ?? ('VPS HTTP ' . $status),
        ];
    }
    return $decoded;
}

/**
 * Hulpfunctie voor Mollie REST API calls.
 */
function mollieRequest(string $apiKey, string $endpoint, array $data): array {
    $ch = curl_init('https://api.mollie.com/v2/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw ?: '{}', true) ?: [];
}

// ============================================================================
// OPTIE A: Mollie iDEAL + Automatische Maandelijkse SEPA-incasso is geconfigureerd
// ============================================================================
if (!empty($config['mollie_api_key'])) {
    $apiKey = $config['mollie_api_key'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    // 1. Maak Mollie Customer aan
    $customer = mollieRequest($apiKey, '/customers', [
        'name'  => $payload['company_name'],
        'email' => $payload['contact_email'],
    ]);
    if (empty($customer['id'])) {
        http_response_code(502);
        echo "Kon geen Mollie-klant aanmaken. Controleer je MOLLIE_API_KEY.";
        exit;
    }

    $payload['mollie_customer_id'] = $customer['id'];
    $orderId = 'ord_' . bin2hex(random_bytes(8));

    $orderDir = __DIR__ . '/data/pending_orders';
    if (!is_dir($orderDir)) {
        mkdir($orderDir, 0750, true);
    }
    file_put_contents($orderDir . '/' . $orderId . '.json', json_encode($payload));

    // 2. Start 1e betaling (sequenceType = first) om het SEPA-mandaat voor maandelijkse afschrijving vast te leggen
    $payment = mollieRequest($apiKey, '/payments', [
        'amount'       => [
            'currency' => 'EUR',
            'value'    => $planInfo['amount'],
        ],
        'customerId'   => $customer['id'],
        'sequenceType' => 'first',
        'description'  => $planInfo['description'] . ' - Eerste betaling & SEPA-machtiging',
        'redirectUrl'  => $baseUrl . '/bedankt.php?order=' . urlencode($orderId),
        'webhookUrl'   => $baseUrl . '/webhook.php',
        'metadata'     => [
            'order_id'  => $orderId,
            'plan_tier' => $planTier,
        ],
    ]);

    $checkoutUrl = $payment['_links']['checkout']['href'] ?? '';
    if ($checkoutUrl !== '') {
        header('Location: ' . $checkoutUrl);
        exit;
    }

    http_response_code(502);
    echo "Kon iDEAL-betaallink niet starten bij Mollie.";
    exit;
}

// ============================================================================
// OPTIE B: Directe VPS-activatie met het toegangsnummer (wanneer Mollie nog leeg is)
// ============================================================================
$result = provisionOnVps($config, $payload);
if (empty($result['ok'])) {
    http_response_code(502);
    echo "Fout bij aanmaken van account op de VPS: " . htmlspecialchars($result['error'] ?? 'Onbekend');
    exit;
}

header(
    'Location: bedankt.php?token=' . urlencode($result['subscriber_token'])
    . '&company=' . urlencode($result['company_name'])
    . '&portal=' . urlencode($result['portal_url'])
);
exit;
