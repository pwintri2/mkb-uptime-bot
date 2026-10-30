<?php
$config = require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="nl" class="h-full bg-slate-950 text-slate-100">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-18546508397"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'AW-18546508397');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MKB Website & Contactformulier Bewaking — Start Direct</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full font-sans antialiased">
    <!-- Topbar -->
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <button type="button" onclick="showStep1()" class="flex items-center gap-3 text-left">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/15 border border-emerald-500/40 text-emerald-400 font-bold">
                    MKB
                </span>
                <div>
                    <span class="text-base font-semibold text-white">MKB Uptime & FormulierWacht</span>
                    <span class="hidden sm:inline text-xs text-slate-400 ml-2">· Elke minuut controle op je website, SSL & contactformulier</span>
                </div>
            </button>
            <span id="step-indicator" class="text-xs text-slate-400 font-medium">
                Stap 1 van 2: Kies je pakket
            </span>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
        <!-- ================================================================== -->
        <!-- BEELD 1: Hero & Pakketkeuze (Standaard zichtbaar)                  -->
        <!-- ================================================================== -->
        <div id="step-1-packages" class="space-y-14">
            <section class="text-center max-w-3xl mx-auto space-y-5">
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    Voorkom gemiste leads & offertes door een kapot contactformulier
                </span>
                <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-white">
                    Merk binnen 60 seconden als je website of contactformulier hapert.
                </h1>
                <p class="text-base sm:text-lg text-slate-400">
                    Wij controleren elke minuut of je site snel laadt, je SSL-certificaat geldig is, je belangrijkste teksten op de pagina staan én of klanten je contactformulier echt kunnen invullen. Kies hieronder je pakket om te starten:
                </p>
            </section>

            <!-- Pakketten (€3 / €5 / €7) -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 flex flex-col justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Basis</h3>
                        <p class="text-xs text-slate-400 mt-1">Voor eenvoudige visitekaart-websites</p>
                        <p class="text-3xl font-extrabold text-white mt-4">€ 3<span class="text-sm font-normal text-slate-400"> / domein / mnd</span></p>
                        <ul class="mt-6 space-y-2.5 text-xs text-slate-300">
                            <li>✓ Elke minuut HTTP & laadtijd controle</li>
                            <li>✓ SSL-certificaat verloopdatum bewaking</li>
                            <li>✓ Kernwoorden-check (tegen witte pagina's)</li>
                            <li>✓ Directe melding via E-mail & Telegram</li>
                        </ul>
                    </div>
                    <button type="button" onclick="selectPlan('basis')" class="mt-6 w-full py-3 rounded-xl border border-slate-700 hover:border-emerald-500 hover:bg-slate-800 text-xs font-semibold text-white transition">
                        Kies Basis (€ 3/mnd) →
                    </button>
                </div>

                <div class="rounded-2xl border-2 border-emerald-500 bg-slate-900/90 p-6 flex flex-col justify-between relative shadow-lg shadow-emerald-500/5">
                    <span class="absolute -top-3 right-5 px-3 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500 text-slate-950">
                        MEEST GEKOZEN
                    </span>
                    <div>
                        <h3 class="text-lg font-bold text-white">Pro Formulier</h3>
                        <p class="text-xs text-slate-400 mt-1">Voor MKB'ers die afhankelijk zijn van aanvragen</p>
                        <p class="text-3xl font-extrabold text-emerald-400 mt-4">€ 5<span class="text-sm font-normal text-slate-400"> / domein / mnd</span></p>
                        <ul class="mt-6 space-y-2.5 text-xs text-slate-200">
                            <li>✓ Alles uit Basis</li>
                            <li>✓ <strong>Elke minuut HTML-formulier controle</strong></li>
                            <li>✓ <strong>Elke 15 min echte browser-check (Playwright)</strong></li>
                            <li>✓ Waarschuwing bij trage laadtijden</li>
                        </ul>
                    </div>
                    <button type="button" onclick="selectPlan('pro')" class="mt-6 w-full py-3 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-xs font-bold text-slate-950 transition">
                        Kies Pro Formulier (€ 5/mnd) →
                    </button>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 flex flex-col justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Compleet + WhatsApp</h3>
                        <p class="text-xs text-slate-400 mt-1">Maximale zekerheid voor webshops & spoeddiensten</p>
                        <p class="text-3xl font-extrabold text-white mt-4">€ 7<span class="text-sm font-normal text-slate-400"> / domein / mnd</span></p>
                        <ul class="mt-6 space-y-2.5 text-xs text-slate-300">
                            <li>✓ Alles uit Pro Formulier</li>
                            <li>✓ <strong>Directe storingsmelding via WhatsApp</strong></li>
                            <li>✓ Melding zodra de site weer online is</li>
                            <li>✓ Persoonlijk live status-dashboard</li>
                        </ul>
                    </div>
                    <button type="button" onclick="selectPlan('compleet')" class="mt-6 w-full py-3 rounded-xl border border-slate-700 hover:border-emerald-500 hover:bg-slate-800 text-xs font-semibold text-white transition">
                        Kies Compleet (€ 7/mnd) →
                    </button>
                </div>
            </section>
        </div>

        <!-- ================================================================== -->
        <!-- BEELD 2: Apart Invulscherm (Pas zichtbaar na pakketkeuze)          -->
        <!-- ================================================================== -->
        <section id="step-2-form" class="hidden max-w-2xl mx-auto">
            <div class="mb-5 flex items-center justify-between">
                <button type="button" onclick="showStep1()" class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-400 hover:text-emerald-300 transition">
                    ← Ander pakket kiezen
                </button>
                <span id="selected-plan-badge" class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                    Gekozen pakket: Pro Formulier (€ 5,00 / mnd)
                </span>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 sm:p-8 shadow-2xl">
                <div class="border-b border-slate-800 pb-4 mb-6">
                    <h2 class="text-xl font-bold text-white">Vul je websitegegevens in</h2>
                    <p class="text-xs text-slate-400 mt-1">
                        Na het invullen en de betaling via Tikkie (iDEAL) wordt je website direct elke minuut bewaakt.
                    </p>
                </div>

                <form action="subscribe.php" method="post" class="space-y-5 text-sm">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Bedrijfsnaam *</label>
                            <input type="text" id="company_name_input" name="company_name" required placeholder="Bijv. Schildersbedrijf De Boer"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">E-mailadres (voor alerts & factuur) *</label>
                            <input type="email" name="contact_email" required placeholder="info@schildersbedrijfdeboer.nl"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Gekozen abonnement *</label>
                            <select id="plan_tier" name="plan_tier" onchange="updatePlanBadge(this.value)" class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                                <option value="basis">Basis — € 3,00 per maand</option>
                                <option value="pro" selected>Pro Formulier — € 5,00 per maand</option>
                                <option value="compleet">Compleet + WhatsApp — € 7,00 per maand</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Naam van de pagina</label>
                            <input type="text" name="monitor_name" required placeholder="Bijv. Offerte- & Contactpagina"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Te bewaken Website- of Contactpagina URL *</label>
                        <input type="url" name="url" required placeholder="https://jouwmkbwebsite.nl/contact"
                            class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Verplichte kernwoorden (optioneel)</label>
                            <input type="text" name="expected_keywords" placeholder="Offerte, Openingstijden, Contact"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500">Komma-gescheiden woorden die altijd zichtbaar moeten zijn.</span>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Formulier selector (standaard: form)</label>
                            <input type="text" name="check_form_selector" value="form" placeholder="form"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500">Laat op 'form' staan om automatisch het contactformulier te bewaken.</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-800">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">WhatsApp-nummer (voor Compleet pakket)</label>
                            <input type="text" name="whatsapp_number" placeholder="+31612345678"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Telegram Chat ID (optioneel)</label>
                            <input type="text" name="telegram_chat_id" placeholder="Bijv. 123456789"
                                class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3.5 py-2.5 text-white focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="rounded-xl bg-slate-950/90 border border-slate-800 p-4 text-xs text-slate-300 space-y-2">
                        <label class="flex items-start gap-2.5 cursor-pointer">
                            <input type="checkbox" name="sepa_mandate" required class="mt-0.5 rounded border-slate-700 text-emerald-500 focus:ring-emerald-500">
                            <span>
                                Ik ga akkoord met de maandelijkse abonnementskosten via Tikkie (iDEAL). Het monitoring-account wordt direct geactiveerd zodra de betaling is voldaan. Maandelijks opzegbaar.
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="w-full rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-3.5 text-sm transition shadow-lg shadow-emerald-500/10">
                        Account direct activeren
                    </button>
                </form>
            </div>
        </section>
    </main>

    <script>
        const planLabels = {
            basis: 'Gekozen pakket: Basis (€ 3,00 / mnd)',
            pro: 'Gekozen pakket: Pro Formulier (€ 5,00 / mnd)',
            compleet: 'Gekozen pakket: Compleet + WhatsApp (€ 7,00 / mnd)'
        };

        function updatePlanBadge(tier) {
            const badge = document.getElementById('selected-plan-badge');
            if (badge && planLabels[tier]) {
                badge.textContent = planLabels[tier];
            }
        }

        function selectPlan(tier) {
            const select = document.getElementById('plan_tier');
            if (select) {
                select.value = tier;
            }
            updatePlanBadge(tier);

            document.getElementById('step-1-packages').classList.add('hidden');
            document.getElementById('step-2-form').classList.remove('hidden');
            document.getElementById('step-indicator').textContent = 'Stap 2 van 2: Gegevens invullen & activeren';
            window.scrollTo({ top: 0, behavior: 'smooth' });

            const firstInput = document.getElementById('company_name_input');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 150);
            }
        }

        function showStep1() {
            document.getElementById('step-2-form').classList.add('hidden');
            document.getElementById('step-1-packages').classList.remove('hidden');
            document.getElementById('step-indicator').textContent = 'Stap 1 van 2: Kies je pakket';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>
