<?php
/**
 * Configuratie voor de gehoste aanmeld- & abonnementspagina (op philip-wintrip.com of eigen domein).
 */

return [
    // 1. Verbinding met jouw VPS (philip-wintrip.nl)
    'vps_provision_url' => getenv('VPS_PROVISION_URL') ?: 'https://philip-wintrip.nl/mkb-uptime-bot/provision.php',

    // Het geheime toegangsnummer waarmee deze site inlogt op jouw VPS (moet overeenkomen met PROVISION_ACCESS_CODE op de VPS)
    'vps_access_code'   => getenv('VPS_ACCESS_CODE') ?: 'MKB-VPS-2026-SECRET',

    // 2. Mollie API-sleutel voor iDEAL (1e betaling) + automatische maandelijkse SEPA-incasso
    // Vul hier je 'test_...' of 'live_...' key in. Laat leeg ('') om direct zonder betaling te testen.
    'mollie_api_key'    => getenv('MOLLIE_API_KEY') ?: '',

    // 3. Pakketprijzen (per domein per maand in EUR)
    'plans' => [
        'basis' => [
            'name'        => 'Basis Uptime & SSL',
            'amount'      => '3.00',
            'description' => 'MKB Uptime Bot - Basis Pakket (Maandelijks)',
        ],
        'pro' => [
            'name'        => 'Pro Formulier & Kernwoorden',
            'amount'      => '5.00',
            'description' => 'MKB Uptime Bot - Pro Formulier Pakket (Maandelijks)',
        ],
        'compleet' => [
            'name'        => 'Compleet + WhatsApp Alerts',
            'amount'      => '7.00',
            'description' => 'MKB Uptime Bot - Compleet + WhatsApp Pakket (Maandelijks)',
        ],
    ],
];
