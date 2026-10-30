<?php
/**
 * Configuratie voor de gehoste aanmeld- & Tikkie-abonnementspagina (op philip-wintrip.com of eigen domein).
 */

return [
    // 1. Basis-URL van jouw VPS en het MKB Uptime Bot endpoint
    'vps_base_url'      => getenv('VPS_BASE_URL') ?: 'https://philip-wintrip.nl',
    'vps_provision_url' => getenv('VPS_PROVISION_URL') ?: 'https://philip-wintrip.nl/mkb-uptime-bot/provision.php',

    // 2. Pincode voor automatische achtergrondsessie op philip-wintrip.nl (/login)
    'vps_pin'           => getenv('VPS_PIN') ?: 'VUL_HIER_JE_PINCODE_IN',

    // 3. Geheime toegangscode voor de MKB Uptime Bot provisioning op de VPS
    'vps_access_code'   => getenv('VPS_ACCESS_CODE') ?: 'MKB-VPS-2026-SECRET',

    // 4. Beheer-wachtwoord voor beheer-tikkie.php (waarmee jij na een Tikkie-betaling met 1 klik activeert)
    'admin_Approve_key' => getenv('TIKKIE_ADMIN_KEY') ?: 'WINTRIP-TIKKIE-BEHEER-2026',

    // 5. Optioneel: Telegram melding naar jou zodra een klant op de Tikkie-betaalpagina staat
    // (Zodat je op je telefoon direct op de 1-klik "Betaald -> Nu activeren" link kunt tikken!)
    'admin_telegram_bot_token' => getenv('ADMIN_TELEGRAM_BOT_TOKEN') ?: '',
    'admin_telegram_chat_id'   => getenv('ADMIN_TELEGRAM_CHAT_ID') ?: '',

    // 6. Jouw eigen Tikkie-betaallinks uit de Tikkie-app (per pakket)
    // Plak hier de Tikkie-links die je in de Tikkie-app hebt aangemaakt voor € 3, € 5 en € 7.
    'plans' => [
        'basis' => [
            'name'        => 'Basis Uptime & SSL',
            'amount'      => '3,00',
            'amount_num'  => 3,
            'description' => 'MKB Uptime Bot - Basis Pakket (€ 3 / mnd)',
            'tikkie_url'  => getenv('TIKKIE_URL_BASIS') ?: 'https://tikkie.me/pay/VUL_HIER_JE_3_EURO_TIKKIE_IN',
        ],
        'pro' => [
            'name'        => 'Pro Formulier & Kernwoorden',
            'amount'      => '5,00',
            'amount_num'  => 5,
            'description' => 'MKB Uptime Bot - Pro Formulier Pakket (€ 5 / mnd)',
            'tikkie_url'  => getenv('TIKKIE_URL_PRO') ?: 'https://tikkie.me/pay/VUL_HIER_JE_5_EURO_TIKKIE_IN',
        ],
        'compleet' => [
            'name'        => 'Compleet + WhatsApp Alerts',
            'amount'      => '7,00',
            'amount_num'  => 7,
            'description' => 'MKB Uptime Bot - Compleet + WhatsApp Pakket (€ 7 / mnd)',
            'tikkie_url'  => getenv('TIKKIE_URL_COMPLEET') ?: 'https://tikkie.me/pay/VUL_HIER_JE_7_EURO_TIKKIE_IN',
        ],
    ],
];
