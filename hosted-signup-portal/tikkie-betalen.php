<?php
/**
 * tikkie-betalen.php
 * ------------------
 * Betaal- en wachtpagina voor de klant:
 * 1. Toont de Tikkie-betaalknop (zodat de klant met zijn eigen bank via iDEAL in Tikkie betaalt).
 * 2. Laat de klant op "Ik heb betaald via Tikkie" klikken.
 * 3. Zolang de betaling nog niet door jou is bevestigd (status !== 'active'), wordt er NIETS geleverd
 *    en controleert deze pagina elke 8 seconden automatisch of het account is vrijgegeven.
 * 4. Zodra jij op "Betaald -> Nu activeren" klikt, stuurt deze pagina de klant direct door naar zijn dashboard!
 */

$config = require __DIR__ . '/config.php';

$orderId = basename($_GET['order'] ?? '');
$orderFile = __DIR__ . '/data/orders/' . $orderId . '.json';

if ($orderId === '' || !is_file($orderFile)) {
    http_response_code(404);
    echo "Bestelling niet gevonden.";
    exit;
}

$order = json_decode(file_get_contents($orderFile), true) ?: [];

// JSON AJAX poll voor automatische doorsturing zodra betaling is goedgekeurd
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $isActivated = ($order['status'] ?? '') === 'active' && !empty($order['vps_result']['subscriber_token']);
    $redirectUrl = null;
    if ($isActivated) {
        $token = $order['vps_result']['subscriber_token'];
        $localPortal = 'status.php?token=' . urlencode($token);
        $redirectUrl = 'bedankt.php?token=' . urlencode($token)
            . '&company=' . urlencode($order['vps_payload']['company_name'] ?? '')
            . '&portal=' . urlencode($localPortal);
    }
    echo json_encode([
        'status'       => $order['status'] ?? 'waiting_for_tikkie',
        'is_activated' => $isActivated,
        'redirect_url' => $redirectUrl,
    ]);
    exit;
}

// Klant klikt op "Ik heb betaald via Tikkie"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid_by_customer'])) {
    $order['customer_clicked_paid'] = true;
    $order['customer_clicked_at'] = date('Y-m-d H:i:s');
    file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT));

    // Stuur eventueel extra seintje naar jou via Telegram dat de klant aangeeft betaald te hebben
    if (!empty($config['admin_telegram_bot_token']) && !empty($config['admin_telegram_chat_id'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $oneClickApproveUrl = $baseUrl . '/beheer-tikkie.php?action=approve&order=' . urlencode($orderId) . '&token=' . urlencode($order['approve_token']);

        $msg = "🔔 Klant '{$order['vps_payload']['company_name']}' ({$order['reference_code']}) geeft aan zojuist € {$order['amount_eur']} via Tikkie betaald te hebben!\n\n"
             . "👉 Klik hier om direct op de VPS te activeren:\n" . $oneClickApproveUrl;

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
}

// Als de bestelling al geactiveerd is, stuur direct door naar bedankt.php
if (($order['status'] ?? '') === 'active' && !empty($order['vps_result']['subscriber_token'])) {
    $token = $order['vps_result']['subscriber_token'];
    $localPortal = 'status.php?token=' . urlencode($token);
    header(
        'Location: bedankt.php?token=' . urlencode($token)
        . '&company=' . urlencode($order['vps_payload']['company_name'] ?? '')
        . '&portal=' . urlencode($localPortal)
    );
    exit;
}

$company       = htmlspecialchars($order['vps_payload']['company_name'] ?? '');
$url           = htmlspecialchars($order['vps_payload']['url'] ?? '');
$planName      = htmlspecialchars($order['plan_name'] ?? 'Pro Formulier');
$amount        = htmlspecialchars($order['amount_eur'] ?? '5,00');
$referenceCode = htmlspecialchars($order['reference_code'] ?? '');
$tikkieUrl     = $order['tikkie_url'] ?? '#';
$clickedPaid   = !empty($order['customer_clicked_paid']);
?>
<!DOCTYPE html>
<html lang="nl" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rond je Tikkie iDEAL-betaling af — MKB Uptime Bot</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4 sm:p-6">
    <div class="max-w-lg w-full rounded-2xl border border-slate-800 bg-slate-900/90 p-6 sm:p-8 space-y-6 shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <div>
                <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Laatste stap vóór activatie</span>
                <h1 class="text-xl font-bold text-white mt-0.5">Betaal veilig met iDEAL via Tikkie</h1>
            </div>
            <span class="px-3 py-1 rounded-lg bg-slate-950 border border-slate-800 font-mono text-xs text-slate-300">
                <?= $referenceCode ?>
            </span>
        </div>

        <div class="rounded-xl bg-slate-950/90 border border-slate-800 p-4 space-y-2 text-xs">
            <div class="flex justify-between">
                <span class="text-slate-400">Bedrijf:</span>
                <span class="text-white font-medium"><?= $company ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">Te bewaken URL:</span>
                <span class="text-slate-200 truncate max-w-[240px]"><?= $url ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">Gekozen pakket:</span>
                <span class="text-white font-medium"><?= $planName ?></span>
            </div>
            <div class="flex justify-between pt-2 border-t border-slate-800 text-sm">
                <span class="text-slate-300 font-semibold">Te voldoen (1e maand):</span>
                <span class="text-emerald-400 font-bold">€ <?= $amount ?></span>
            </div>
        </div>

        <div class="space-y-3">
            <p class="text-xs text-slate-300 leading-relaxed">
                <strong>Stap 1:</strong> Klik op de onderstaande knop om € <?= $amount ?> te voldoen via <strong>Tikkie (iDEAL)</strong>. Vermeld indien mogelijk je bedrijfsnaam of code <span class="font-mono text-emerald-400"><?= $referenceCode ?></span>.
            </p>
            <a href="<?= htmlspecialchars($tikkieUrl) ?>" target="_blank" rel="noopener"
               class="flex items-center justify-center gap-2 w-full rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3.5 px-5 text-sm transition shadow-lg shadow-indigo-600/20">
                <span>Betaal € <?= $amount ?> met iDEAL via Tikkie ↗</span>
            </a>
        </div>

        <div class="pt-3 border-t border-slate-800 space-y-3">
            <?php if (!$clickedPaid): ?>
            <p class="text-xs text-slate-400">
                <strong>Stap 2:</strong> Heb je de Tikkie-betaling afgerond in je bank-app? Klik dan hieronder zodat wij je monitoring direct vrijgeven:
            </p>
            <form method="post">
                <input type="hidden" name="mark_paid_by_customer" value="1">
                <button type="submit"
                    class="w-full rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-3 text-sm transition">
                    Ik heb betaald via Tikkie — Activeer mijn account
                </button>
            </form>
            <?php else: ?>
            <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-200 space-y-2">
                <div class="flex items-center gap-2 font-semibold text-amber-300">
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-400 animate-ping"></span>
                    <span>Wachten op vrijgave na Tikkie-ontvangst...</span>
                </div>
                <p class="text-slate-300 leading-relaxed">
                    Je aanmelding (<span class="font-mono"><?= $referenceCode ?></span>) staat klaar. Zodra je Tikkie-betaling is bevestigd, wordt je monitoring-account automatisch aangemaakt en stuurt deze pagina je <strong>vanzelf</strong> door naar je persoonlijke dashboard.
                </p>
            </div>
            <?php endif; ?>
        </div>

        <p class="text-[11px] text-center text-slate-500">
            Deze pagina controleert elke 8 seconden automatisch of je betaling is bevestigd.
        </p>
    </div>

    <script>
        // Poll elke 8 seconden of de Tikkie-betaling is goedgekeurd; stuur direct door zodra actief!
        setInterval(async () => {
            try {
                const res = await fetch('tikkie-betalen.php?order=<?= urlencode($orderId) ?>&check_status=1', { cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                if (data.is_activated && data.redirect_url) {
                    window.location.href = data.redirect_url;
                }
            } catch (e) {}
        }, 8000);
    </script>
</body>
</html>
