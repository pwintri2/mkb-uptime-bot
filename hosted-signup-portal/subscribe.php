<?php
/**
 * subscribe.php
 * -------------
 * Verwerkt het aanmeldformulier met Tikkie-betaling:
 * - Levert NIETS voordat er betaald is!
 * - Slaat de aanmelding op met status 'waiting_for_tikkie' en een uniek betaalkenmerk (bijv. MKB-4821).
 * - Stuurt jou (indien ingesteld) direct een Telegram-bericht met een 1-klik knop:
 *   "✓ Tikkie Betaald -> Nu Activeren op VPS".
 * - Stuurt de klant naar tikkie-betalen.php waar ze via jouw Tikkie-link met iDEAL betalen.
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

$companyName  = trim($_POST['company_name'] ?? '');
$contactEmail = trim($_POST['contact_email'] ?? '');
$url          = trim($_POST['url'] ?? '');

if ($companyName === '' || $contactEmail === '' || $url === '') {
    http_response_code(400);
    echo "Vul alle verplichte velden (bedrijfsnaam, e-mail en URL) in.";
    exit;
}

$orderId       = 'ord_' . bin2hex(random_bytes(6));
$referenceCode = 'MKB-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$approveToken  = bin2hex(random_bytes(16));

$orderData = [
    'order_id'            => $orderId,
    'reference_code'      => $referenceCode,
    'approve_token'       => $approveToken,
    'status'              => 'waiting_for_tikkie', // Wordt pas 'active' nadat jij Tikkie-betaling bevestigt!
    'created_at'          => date('Y-m-d H:i:s'),
    'paid_Confirmed_at'   => null,
    'next_renewal_date'   => date('Y-m-d', strtotime('+1 month')),
    'plan_tier'           => $planTier,
    'plan_name'           => $planInfo['name'],
    'amount_eur'          => $planInfo['amount'],
    'tikkie_url'          => $planInfo['tikkie_url'],
    'customer_clicked_paid' => false,
    'vps_payload'         => [
        'access_code'         => $config['vps_access_code'],
        'company_name'        => $companyName,
        'contact_email'       => $contactEmail,
        'plan_tier'           => $planTier,
        'monitor_name'        => trim($_POST['monitor_name'] ?? '') ?: $companyName,
        'url'                 => $url,
        'expected_keywords'   => trim($_POST['expected_keywords'] ?? ''),
        'check_form_selector' => trim($_POST['check_form_selector'] ?? 'form'),
        'telegram_chat_id'    => trim($_POST['telegram_chat_id'] ?? ''),
        'whatsapp_number'     => trim($_POST['whatsapp_number'] ?? ''),
        'payment_status'      => 'active',
        'run_initial_check'   => true,
    ],
    'vps_result'          => null,
];

$orderDir = __DIR__ . '/data/orders';
if (!is_dir($orderDir)) {
    mkdir($orderDir, 0700, true);
    @file_put_contents($orderDir . '/.htaccess', "Deny from all\n");
}
file_put_contents($orderDir . '/' . $orderId . '.json', json_encode($orderData, JSON_PRETTY_PRINT));

// Stuur direct een 1-klik goedkeuringslink naar jouw eigen Telegram (indien geconfigureerd)
if (!empty($config['admin_telegram_bot_token']) && !empty($config['admin_telegram_chat_id'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $oneClickApproveUrl = $baseUrl . '/beheer-tikkie.php?action=approve&order=' . urlencode($orderId) . '&token=' . urlencode($approveToken);

    $msg = "💳 NIEUWE TIKKIE AANMELDING ({$referenceCode})\n"
         . "Bedrijf: {$companyName}\n"
         . "E-mail: {$contactEmail}\n"
         . "Pakket: {$planInfo['name']} (€ {$planInfo['amount']} / mnd)\n"
         . "URL: {$url}\n\n"
         . "⏳ Status: Wacht op Tikkie-betaling.\n"
         . "✅ Zodra de Tikkie binnen is, klik hier om het account op de VPS te activeren:\n"
         . $oneClickApproveUrl;

    $ch = curl_init("https://api.telegram.org/bot{$config['admin_telegram_bot_token']}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'                  => $config['admin_telegram_chat_id'],
            'text'                     => $msg,
            'disable_web_page_preview' => true,
        ]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

header('Location: tikkie-betalen.php?order=' . urlencode($orderId));
exit;
