<?php
$company = htmlspecialchars($_GET['company'] ?? 'Jouw Bedrijf');
$portalUrl = $_GET['portal'] ?? '';
$orderId = $_GET['order'] ?? '';

if ($portalUrl === '' && $orderId !== '') {
    $doneFile = __DIR__ . '/data/pending_orders/' . basename($orderId) . '.json.done';
    if (is_file($doneFile)) {
        $data = json_decode(file_get_contents($doneFile), true) ?: [];
        $portalUrl = $data['portal_url'] ?? '';
        $company = htmlspecialchars($data['company_name'] ?? $company);
    }
}
?>
<!DOCTYPE html>
<html lang="nl" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bewaking Geactiveerd — MKB Uptime Bot</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full flex items-center justify-center p-6">
    <div class="max-w-lg w-full rounded-2xl border border-emerald-500/40 bg-slate-900/90 p-8 text-center space-y-5 shadow-xl">
        <div class="mx-auto h-14 w-14 rounded-full bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-emerald-400 text-2xl font-bold">
            ✓
        </div>
        <h1 class="text-2xl font-bold text-white">Welkom, <?= $company ?>!</h1>
        <p class="text-sm text-slate-300">
            Je account en website-monitor zijn direct aangemaakt op onze monitoring-VPS. Vanaf dit moment wordt je website elke 60 seconden gecontroleerd op bereikbaarheid, SSL, kernwoorden en je contactformulier.
        </p>

        <?php if ($portalUrl !== ''): ?>
        <div class="pt-3">
            <a href="<?= htmlspecialchars($portalUrl) ?>"
               class="inline-block w-full rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-3 px-6 text-sm transition">
                Open Jouw Persoonlijke Live Statuspagina →
            </a>
            <p class="text-xs text-slate-400 mt-3">
                Bewaar deze link als bladwijzer: <br>
                <span class="font-mono text-emerald-400 break-all"><?= htmlspecialchars($portalUrl) ?></span>
            </p>
        </div>
        <?php else: ?>
        <p class="text-xs text-amber-300">
            Je betaling wordt verwerkt. Ververs deze pagina over enkele seconden om je persoonlijke dashboardlink te openen.
        </p>
        <?php endif; ?>
    </div>
</body>
</html>
