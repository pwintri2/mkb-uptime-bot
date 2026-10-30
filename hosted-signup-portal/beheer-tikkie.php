<?php
/**
 * beheer-tikkie.php
 * -----------------
 * Beheerpagina (én 1-klik goedkeuringslink) voor jou als eigenaar:
 * - Zorgt dat er NOOIT geleverd wordt voordat een Tikkie daadwerkelijk betaald is.
 * - Zodra je het Tikkie-pushbericht op je telefoon ziet, klik je op "✓ Betaald -> Nu Activeren op VPS".
 * - Op dat moment logt dit script via wintrip_vps_auth.php (automatische pincode-sessie + X-Access-Code)
 *   in op jouw VPS (https://philip-wintrip.nl/mkb-uptime-bot/provision.php), maakt de klant + 1-minuut
 *   monitor aan, en geeft het wachtscherm van de klant vrij!
 * - Bevat ook 1-klik knoppen om bij niet-betaalde vervolgmaanden de monitor op de VPS te pauzeren of te hervatten.
 */

session_start();
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/wintrip_vps_auth.php';

$orderDir = __DIR__ . '/data/orders';
if (!is_dir($orderDir)) {
    mkdir($orderDir, 0700, true);
}

/**
 * Hulpfunctie om een bestelling op de VPS aan te maken en op 'active' te zetten.
 */
function activateOrderOnVps(array $config, string $orderFile, array $order): array {
    if (($order['status'] ?? '') === 'active' && !empty($order['vps_result']['ok'])) {
        return ['ok' => true, 'already_active' => true, 'order' => $order];
    }

    $res = wintripVpsRequest(
        $config,
        $config['vps_provision_url'],
        'POST',
        json_encode($order['vps_payload']),
        ['Content-Type: application/json']
    );

    $decoded = json_decode($res['body'] ?: '{}', true) ?: [];
    if (!$res['ok'] || empty($decoded['ok'])) {
        return [
            'ok'    => false,
            'error' => $decoded['detail'] ?? $decoded['error'] ?? $res['error'] ?? ('VPS HTTP ' . $res['status']),
        ];
    }

    $order['status']            = 'active';
    $order['paid_confirmed_at'] = date('Y-m-d H:i:s');
    $order['next_renewal_date'] = date('Y-m-d', strtotime('+1 month'));
    $order['vps_result']        = $decoded;
    file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT));

    return ['ok' => true, 'order' => $order];
}

// ============================================================================
// 1. Directe 1-klik goedkeuringslink vanuit je Telegram-notificatie (met unieke approve_token)
// ============================================================================
if (($_GET['action'] ?? '') === 'approve' && !empty($_GET['order']) && !empty($_GET['token'])) {
    $orderId = basename($_GET['order']);
    $orderFile = $orderDir . '/' . $orderId . '.json';
    if (is_file($orderFile)) {
        $order = json_decode(file_get_contents($orderFile), true) ?: [];
        if (hash_equals($order['approve_token'] ?? '', $_GET['token'])) {
            $act = activateOrderOnVps($config, $orderFile, $order);
            if ($act['ok']) {
                echo "<!DOCTYPE html><html lang='nl'><body style='background:#020617;color:#34d399;font-family:sans-serif;padding:2rem;text-align:center;'>"
                   . "<h2>✓ Account Geactiveerd op VPS!</h2>"
                   . "<p style='color:#cbd5e1;'>Klant <strong>" . htmlspecialchars($order['vps_payload']['company_name']) . "</strong> wordt nu elke minuut gemonitord en hun wachtscherm is vrijgegeven.</p>"
                   . "<p><a href='beheer-tikkie.php' style='color:#38bdf8;'>Open Tikkie Beheeroverzicht →</a></p></body></html>";
                exit;
            }
            http_response_code(502);
            echo "Fout bij activeren op VPS: " . htmlspecialchars($act['error'] ?? 'Onbekend');
            exit;
        }
    }
    http_response_code(403);
    echo "Ongeldige goedkeuringslink.";
    exit;
}

// ============================================================================
// 2. Beheer-login controle voor het overzichtsscherm
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_key'])) {
    if (hash_equals($config['admin_approve_key'] ?? $config['admin_Approve_key'] ?? '', trim($_POST['admin_key']))) {
        $_SESSION['tikkie_admin_ok'] = true;
    }
}

if (empty($_SESSION['tikkie_admin_ok'])) {
    ?>
    <!DOCTYPE html>
    <html lang="nl" class="h-full bg-slate-950 text-slate-100">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Tikkie Beheer Login</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-full flex items-center justify-center p-6">
        <form method="post" class="max-w-sm w-full rounded-2xl border border-slate-800 bg-slate-900 p-6 space-y-4">
            <h1 class="text-lg font-bold text-white">Tikkie Betalingen Beheer</h1>
            <p class="text-xs text-slate-400">Voer je beheer-sleutel uit <code>config.php</code> in om Tikkie-aanmeldingen vrij te geven.</p>
            <input type="password" name="admin_key" required placeholder="Beheer-sleutel"
                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-white">
            <button type="submit" class="w-full rounded-lg bg-emerald-500 text-slate-950 font-bold py-2 text-sm">Inloggen</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// 3. Verwerk Beheer-acties (Activeren, Maand verlengen, Pauzeren / Hervatten)
// ============================================================================
$flashMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['order_id']) && !empty($_POST['admin_action'])) {
    $orderId = basename($_POST['order_id']);
    $orderFile = $orderDir . '/' . $orderId . '.json';
    if (is_file($orderFile)) {
        $order = json_decode(file_get_contents($orderFile), true) ?: [];
        $action = $_POST['admin_action'];

        if ($action === 'approve') {
            $res = activateOrderOnVps($config, $orderFile, $order);
            $flashMsg = $res['ok']
                ? "✓ Account '{$order['vps_payload']['company_name']}' is aangemaakt op de VPS en vrijgegeven!"
                : "✗ Fout bij VPS activatie: " . ($res['error'] ?? '');
        } elseif ($action === 'renew_month') {
            $order['next_renewal_date'] = date('Y-m-d', strtotime('+1 month'));
            $order['status'] = 'active';
            file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT));

            if (!empty($order['vps_result']['subscriber_token'])) {
                wintripVpsRequest(
                    $config,
                    $config['vps_provision_url'] . '?action=subscription-status',
                    'POST',
                    json_encode([
                        'access_code'      => $config['vps_access_code'],
                        'subscriber_token' => $order['vps_result']['subscriber_token'],
                        'payment_status'   => 'active',
                        'is_active'        => true,
                    ]),
                    ['Content-Type: application/json']
                );
            }
            $flashMsg = "✓ Nieuwe maand Tikkie geregistreerd en monitor actief gezet tot {$order['next_renewal_date']}.";
        } elseif ($action === 'pause') {
            $order['status'] = 'paused_unpaid';
            file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT));

            if (!empty($order['vps_result']['subscriber_token'])) {
                wintripVpsRequest(
                    $config,
                    $config['vps_provision_url'] . '?action=subscription-status',
                    'POST',
                    json_encode([
                        'access_code'      => $config['vps_access_code'],
                        'subscriber_token' => $order['vps_result']['subscriber_token'],
                        'payment_status'   => 'past_due',
                        'is_active'        => false,
                    ]),
                    ['Content-Type: application/json']
                );
            }
            $flashMsg = "⏸ Monitor voor '{$order['vps_payload']['company_name']}' is gepauzeerd op de VPS.";
        }
    }
}

$orders = [];
foreach (glob($orderDir . '/ord_*.json') ?: [] as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (is_array($data)) {
        $orders[] = $data;
    }
}
usort($orders, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
?>
<!DOCTYPE html>
<html lang="nl" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tikkie Betalingen & VPS Activatie Beheer</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full p-6 max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between border-b border-slate-800 pb-4">
        <div>
            <h1 class="text-xl font-bold text-white">Tikkie Betalingen & VPS Activatie</h1>
            <p class="text-xs text-slate-400">Geen levering vóór betaling: klik pas op "Nu Activeren" zodra de Tikkie in je app betaald is.</p>
        </div>
        <a href="index.php" class="text-xs text-emerald-400 hover:underline">Naar Publieke Signup Pagina →</a>
    </div>

    <?php if ($flashMsg): ?>
    <div class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-xs text-emerald-300 font-medium">
        <?= htmlspecialchars($flashMsg) ?>
    </div>
    <?php endif; ?>

    <div class="rounded-xl border border-slate-800 bg-slate-900/70 overflow-hidden">
        <table class="w-full text-left text-xs">
            <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                <tr>
                    <th class="py-3 px-4">Kenmerk</th>
                    <th class="py-3 px-4">Klant & Website</th>
                    <th class="py-3 px-4">Pakket</th>
                    <th class="py-3 px-4">Betaalstatus</th>
                    <th class="py-3 px-4">Volgende Tikkie</th>
                    <th class="py-3 px-4 text-right">Actie (1-Klik VPS Koppeling)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="py-3.5 px-4 font-mono text-slate-300">
                        <?= htmlspecialchars($o['reference_code'] ?? '') ?>
                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($o['created_at'] ?? '') ?></div>
                    </td>
                    <td class="py-3.5 px-4">
                        <div class="font-semibold text-white"><?= htmlspecialchars($o['vps_payload']['company_name'] ?? '') ?></div>
                        <div class="text-slate-400"><?= htmlspecialchars($o['vps_payload']['contact_email'] ?? '') ?> · <?= htmlspecialchars($o['vps_payload']['url'] ?? '') ?></div>
                    </td>
                    <td class="py-3.5 px-4">
                        <?= htmlspecialchars($o['plan_name'] ?? '') ?> (€ <?= htmlspecialchars($o['amount_eur'] ?? '') ?>)
                    </td>
                    <td class="py-3.5 px-4">
                        <?php if (($o['status'] ?? '') === 'waiting_for_tikkie'): ?>
                            <span class="px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30 font-semibold">
                                Wacht op Tikkie <?= !empty($o['customer_clicked_paid']) ? '(Klant meldt betaald!)' : '' ?>
                            </span>
                        <?php elseif (($o['status'] ?? '') === 'active'): ?>
                            <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-semibold">
                                ✓ Actief op VPS
                            </span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30 font-semibold">
                                Gepauzeerd
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3.5 px-4 font-mono text-slate-300">
                        <?= htmlspecialchars($o['next_renewal_date'] ?? '-') ?>
                    </td>
                    <td class="py-3.5 px-4 text-right space-x-1">
                        <?php if (($o['status'] ?? '') === 'waiting_for_tikkie'): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['order_id']) ?>">
                            <input type="hidden" name="admin_action" value="approve">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold">
                                ✓ Tikkie Betaald → Nu Activeren op VPS
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['order_id']) ?>">
                            <input type="hidden" name="admin_action" value="renew_month">
                            <button type="submit" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-emerald-300 border border-slate-700">
                                +1 Maand Betaald
                            </button>
                        </form>
                        <form method="post" class="inline">
                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($o['order_id']) ?>">
                            <input type="hidden" name="admin_action" value="pause">
                            <button type="submit" class="px-2.5 py-1 rounded bg-rose-500/15 hover:bg-rose-500/25 text-rose-300 border border-rose-500/30">
                                Pauzeer op VPS
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="6" class="py-8 text-center text-slate-400">Nog geen Tikkie-aanmeldingen binnengekomen.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
