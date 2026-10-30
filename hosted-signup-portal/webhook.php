<?php
/**
 * Mollie Webhook op philip-wintrip.com (of eigen domein):
 * - Zodra de 1e betaling ('first') op 'paid' springt:
 *   1. Start bij Mollie het maandelijkse automatische SEPA-incasso abonnement (interval: '1 month').
 *   2. Logt via wintrip_vps_auth.php automatisch met de pincode + het toegangsnummer in op jouw VPS
 *      en maakt het MKB-account + de 1-minuut monitor aan!
 * - Als een latere maandelijkse afschrijving ('recurring') mislukt of wordt gestorneerd:
 *   pauzeert automatisch het account op de VPS via provision.php?action=subscription-status.
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/wintrip_vps_auth.php';

$apiKey = $config['mollie_api_key'];
$paymentId = $_POST['id'] ?? '';

if ($apiKey === '' || $paymentId === '') {
    http_response_code(200);
    echo "OK";
    exit;
}

// Haal de betaalstatus op bij Mollie
$ch = curl_init('https://api.mollie.com/v2/payments/' . rawurlencode($paymentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT        => 15,
]);
$payment = json_decode(curl_exec($ch) ?: '{}', true) ?: [];
curl_close($ch);

$status       = $payment['status'] ?? '';
$sequenceType = $payment['sequenceType'] ?? '';
$customerId   = $payment['customerId'] ?? '';
$orderId      = $payment['metadata']['order_id'] ?? '';
$planTier     = $payment['metadata']['plan_tier'] ?? 'pro';
$planInfo     = $config['plans'][$planTier] ?? $config['plans']['pro'];

if ($sequenceType === 'first' && $status === 'paid' && $customerId !== '' && $orderId !== '') {
    $orderFile = __DIR__ . '/data/pending_orders/' . basename($orderId) . '.json';
    if (is_file($orderFile)) {
        $payload = json_decode(file_get_contents($orderFile), true) ?: [];

        // 1. Activeer de maandelijkse automatische afschrijving bij Mollie Subscriptions
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

        $chSub = curl_init('https://api.mollie.com/v2/customers/' . rawurlencode($customerId) . '/subscriptions');
        curl_setopt_array($chSub, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'amount'      => [
                    'currency' => 'EUR',
                    'value'    => $planInfo['amount'],
                ],
                'interval'    => '1 month',
                'description' => $planInfo['description'],
                'webhookUrl'  => $baseUrl . '/webhook.php',
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $subscription = json_decode(curl_exec($chSub) ?: '{}', true) ?: [];
        curl_close($chSub);

        $payload['mollie_customer_id']     = $customerId;
        $payload['mollie_subscription_id'] = $subscription['id'] ?? null;
        $payload['payment_status']         = 'active';

        // 2. Roep de VPS aan met automatische pincode-login + toegangsnummer
        $res = wintripVpsRequest(
            $config,
            $config['vps_provision_url'],
            'POST',
            json_encode($payload),
            ['Content-Type: application/json']
        );
        $vpsRes = json_decode($res['body'] ?: '{}', true) ?: [];
        if (!empty($vpsRes['subscriber_token'])) {
            $vpsRes['portal_url'] = 'status.php?token=' . urlencode($vpsRes['subscriber_token']);
        }

        // Bewaar resultaat zodat bedankt.php de persoonlijke dashboardlink kan tonen
        file_put_contents($orderFile . '.done', json_encode($vpsRes));
        @unlink($orderFile);
    }
} elseif ($sequenceType === 'recurring' && $customerId !== '') {
    // Verwerk maandelijkse SEPA-incasso resultaat (active vs past_due/paused)
    $isPaid = ($status === 'paid');
    $statusPayload = [
        'access_code'        => $config['vps_access_code'],
        'mollie_customer_id' => $customerId,
        'payment_status'     => $isPaid ? 'active' : 'past_due',
        'is_active'          => $isPaid,
    ];

    wintripVpsRequest(
        $config,
        $config['vps_provision_url'] . '?action=subscription-status',
        'POST',
        json_encode($statusPayload),
        ['Content-Type: application/json']
    );
}

http_response_code(200);
echo "OK";
