<?php
/**
 * wintrip_vps_auth.php
 * --------------------
 * Universele helper voor de gehoste site (philip-wintrip.com) om automatisch in te loggen
 * op het pincode-systeem van de VPS (https://philip-wintrip.nl/login -> /opt/wintrip-auth/app.py).
 *
 * Hoe het pincode-systeem op de VPS werkt:
 * - Nginx controleert via 'auth_request /auth' bij Flask (127.0.0.1:5000/auth) of de
 *   sessie-cookie 'wintrip_session' aanwezig en geldig is.
 * - Zo niet, dan moet er een POST gedaan worden naar https://philip-wintrip.nl/login
 *   met form-data: pin=<PINCODE>&next=/
 *
 * Wat dit bestand doet:
 * 1. Bewaart de 'wintrip_session' cookie in een beveiligd lokaal cookie-bestand zodat niet bij
 *    elke klik opnieuw ingelogd hoeft te worden.
 * 2. Voert elk verzoek naar de VPS uit met die sessie-cookie.
 * 3. Merkt automatisch wanneer de sessie verlopen is (HTTP 401/403 of redirect naar /login),
 *    logt dan op de achtergrond opnieuw in met de pincode en herhaalt het verzoek naadloos!
 */

/**
 * Geeft het pad naar het lokale cookie-bestand terug (en maakt de map veilig aan).
 */
function wintripGetCookieFile(): string {
    $dir = __DIR__ . '/data/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        // Bescherm de map tegen directe browser-toegang op Apache/LiteSpeed
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir . '/wintrip_vps_cookie.txt';
}

/**
 * Logt met de pincode in op https://philip-wintrip.nl/login en slaat de 'wintrip_session' cookie op.
 */
function wintripLoginWithPin(array $config): bool {
    $baseUrl = rtrim($config['vps_base_url'] ?? 'https://philip-wintrip.nl', '/');
    $pin = trim($config['vps_pin'] ?? '');
    if ($pin === '' || $pin === 'VUL_HIER_JE_PINCODE_IN') {
        return false;
    }

    $cookieFile = wintripGetCookieFile();
    $ch = curl_init($baseUrl . '/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'pin'  => $pin,
            'next' => '/',
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Succesvolle login in /opt/wintrip-auth/app.py geeft een 302 redirect + Set-Cookie: wintrip_session=...
    if ($response !== false && ($status === 302 || $status === 200) && stripos($response, 'wintrip_session=') !== false) {
        return true;
    }
    return false;
}

/**
 * Voert een HTTP-verzoek (GET of POST) uit naar de VPS en logt automatisch in met de pincode
 * wanneer de 'wintrip_session' cookie nog niet bestaat of verlopen is.
 *
 * @return array{ok: bool, status: int, body: string, error: string|null}
 */
function wintripVpsRequest(
    array $config,
    string $url,
    string $method = 'GET',
    ?string $body = null,
    array $headers = []
): array {
    $cookieFile = wintripGetCookieFile();

    // Als we nog geen sessie-cookie hebben, log dan eerst één keer in
    if (!is_file($cookieFile) || filesize($cookieFile) === 0) {
        wintripLoginWithPin($config);
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        $reqHeaders = $headers;
        if (!empty($config['vps_access_code'])) {
            $reqHeaders[] = 'X-Access-Code: ' . $config['vps_access_code'];
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);
        $rawBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => '',
                'error'  => 'cURL fout naar VPS: ' . $err,
            ];
        }

        // Controleer of Nginx/Flask om een pincode-login vraagt (401, of 302 naar /login)
        $needsPinLogin = (
            $status === 401
            || ($status === 302 && stripos($redirectUrl, '/login') !== false)
            || stripos($rawBody, 'name="pin"') !== false
        );

        if ($needsPinLogin && $attempt === 1) {
            if (wintripLoginWithPin($config)) {
                continue; // Probeer het verzoek direct opnieuw met de verse wintrip_session cookie!
            }
        }

        return [
            'ok'     => ($status >= 200 && $status < 400 && !$needsPinLogin),
            'status' => $status,
            'body'   => $rawBody,
            'error'  => $needsPinLogin ? 'Kon niet automatisch inloggen met VPS_PIN.' : null,
        ];
    }

    return [
        'ok'     => false,
        'status' => 401,
        'body'   => '',
        'error'  => 'Automatische pincode-login op VPS mislukt.',
    ];
}
