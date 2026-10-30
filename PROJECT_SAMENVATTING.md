# Volledige Gespreks- & Projectsamenvatting: MKB Uptime & Formulier Bot

Dit document vat het volledige traject samen: van het initiële idee en technische bouwplan tot de realisatie, de VPS-uitrol op `philip-wintrip.nl` en het zelfbedienings-aanmeldportaal voor `philip-wintrip.com`.

---

## 1. Het Concept & Verdienmodel
- **Probleem:** Lokale MKB-ondernemers merken vaak pas dagen of weken later dat hun contactformulier kapot is na een plugin-update, hun SSL-certificaat is verlopen of hun website een witte pagina toont.
- **Oplossing:** Een lichtgewicht monitoring-dienst die elke minuut MKB-websites controleert op bereikbaarheid, laadtijd, SSL-geldigheid, aanwezigheid van verplichte kernwoorden én het daadwerkelijk functioneren van het contactformulier.
- **Verdienmodel (Micro-abonnementen):**
  - **Basis (€ 3 / domein / maand):** Elke minuut HTTP-, laadtijd-, SSL- en kernwoordencheck + meldingen via E-mail en Telegram.
  - **Pro Formulier (€ 5 / domein / maand):** Inclusief elke minuut HTML/DOM-formuliercheck én elke 15 minuten een interactieve headless browser-check (Playwright).
  - **Compleet + WhatsApp (€ 7 / domein / maand):** Inclusief directe storings- en herstelmeldingen via WhatsApp en een persoonlijk live statusdashboard.

---

## 2. Gekozen Technische Architectuur (`app/`)
- **Backend & Dashboard:** Python met **FastAPI**, **Jinja2**, **HTMX** en **Tailwind CSS**.
- **Opslag:** **SQLite in WAL-modus** (`PRAGMA journal_mode=WAL;`) via async SQLAlchemy (`aiosqlite`), zodat gelijktijdige minuut-checks het dashboard nooit blokkeren.
- **Achtergrond-worker (`app/services/scheduler.py`):**
  - **1-Minuut Loop (`httpx` + `BeautifulSoup`):** Razendsnelle controle op HTTP-status, laadtijd in ms, kernwoorden en de aanwezigheid van `<form>`, zichtbare invoervelden en een actieve verzendknop.
  - **15-Minuten Loop (`ssl` + `Playwright`):** Controleert de X.509 SSL-verloopdatum op poort `443` én opent de pagina in een echte headless Chromium-browser om JavaScript-fouten en onzichtbare/geblokkeerde formulieren te detecteren (zonder spam te versturen door `POST`-mutaties af te vangen).
- **Anti-Flapping Engine (`app/services/incident_engine.py`):**
  - Voert bij een eerste fout na 2 seconden direct een retry uit en opent pas bij een instelbare drempel (`CONFIRMATION_THRESHOLD=2`) een incident, zodat ondernemers geen valse alarmen krijgen.
  - Stuurt automatisch een `🟢 WEER ONLINE`-bericht zodra de storing voorbij is.
- **Notificaties (`app/services/notifier.py`):**
  - Ondersteunt **Telegram Bot API**, **E-mail (Resend HTTP API & SMTP)** en **WhatsApp (Twilio API & Cloud Webhook)**.

---

## 3. Uitrol op de VPS (`87.106.137.223` / `philip-wintrip.nl`)
- **Locatie op VPS:** `/var/www/philip-wintrip.nl/html/mkb-uptime-bot`
- **Python Omgeving:** `/var/www/philip-wintrip.nl/html/mkb-uptime-bot/.venv` (alle 7 geautomatiseerde `pytest`-tests draaien groen op zowel de Mac als de VPS).
- **Systemd User Service:**
  - Geïnstalleerd en actief als `~/.config/systemd/user/mkb-uptime-bot.service` op `http://127.0.0.1:8815`.
  - Beheercommando's op de VPS:
    ```bash
    systemctl --user status mkb-uptime-bot.service
    systemctl --user restart mkb-uptime-bot.service
    ```
- **PHP Reverse-Proxy Bruggen op de VPS:**
  - `index.html` & `app.php`: Maken het beheerdashboard direct bereikbaar op `https://philip-wintrip.nl/mkb-uptime-bot/`.
  - `provision.php`: Ontvangt nieuwe klantaanmeldingen en incasso-updates vanaf de gehoste aanmeldpagina (`philip-wintrip.com`), beveiligd met `X-Access-Code` (`PROVISION_ACCESS_CODE`).
  - `klant.php`: Serveert het persoonlijke statusdashboard per klant op basis van hun unieke token (`mkb_...`).

---

## 4. Zelfbedienings Aanmeldportaal & Automatische Pincode-Login (`hosted-signup-portal/`)
Voor plaatsing op de gehoste site (`philip-wintrip.com` of een apart domein) is de map `hosted-signup-portal/` gebouwd:

1. **`index.php` (Publieke Verkoop- & Signup-pagina):**
   - Klanten kiezen hun pakket (€ 3 / € 5 / € 7), vullen hun gegevens, website-URL, kernwoorden, formulier-selector en gewenste notificatiekanalen in, vinken het SEPA-mandaat aan en klikken op de groene knop **"Account direct activeren"**.
2. **`config.php` (Configuratie):**
   - Bevat de VPS-URL (`https://philip-wintrip.nl/mkb-uptime-bot/provision.php`), de VPS-pincode (`vps_pin`), het geheime toegangsnummer (`vps_access_code`) en de `mollie_api_key`.
3. **`wintrip_vps_auth.php` (Automatische Pincode-Login voor `philip-wintrip.nl`):**
   - Op de VPS draait `/opt/wintrip-auth/app.py` (poort `5000` met SQLite database `/opt/wintrip-auth/users.db`) die via Nginx `auth_request /auth` controleert of de sessie-cookie `wintrip_session` aanwezig is.
   - `wintrip_vps_auth.php` logt op de achtergrond automatisch in via `POST https://philip-wintrip.nl/login` (`pin=<vps_pin>&next=/`), bewaart de `wintrip_session` cookie in `data/sessions/wintrip_vps_cookie.txt` en logt automatisch opnieuw in zodra een sessie verloopt.
4. **`subscribe.php` & `webhook.php` (Mollie iDEAL + Maandelijkse SEPA-incasso):**
   - **Met Mollie API-sleutel:** Maakt een Mollie-klant aan en start de 1e betaling via iDEAL (`sequenceType: "first"`). Zodra die betaald is, start `webhook.php` automatisch het maandelijkse SEPA-incasso abonnement (`interval: "1 month"`) én activeert het het account op de VPS. Bij een mislukte vervolgincasso wordt de monitor op de VPS automatisch gepauzeerd.
   - **Zonder Mollie API-sleutel (Directe Activatiemodus):** Maakt het account en de monitor direct aan op de VPS zodat het systeem ook zonder betaalprovider direct te gebruiken en te testen is.
5. **`status.php` & `bedankt.php` (Klantportaal zonder Pincode):**
   - Toont geabonneerde klanten hun persoonlijke live statuspagina rechtstreeks op het gehoste domein (`status.php?token=mkb_...`), waarbij de gehoste site op de achtergrond met de VPS communiceert. Klanten hoeven daardoor nooit de VPS-pincode te kennen.

---

## 5. Belangrijke Links
- **GitHub Repository:** [https://github.com/pwintri2/mkb-uptime-bot](https://github.com/pwintri2/mkb-uptime-bot)
- **Live Beheerdashboard op VPS:** [https://philip-wintrip.nl/mkb-uptime-bot/](https://philip-wintrip.nl/mkb-uptime-bot/)
