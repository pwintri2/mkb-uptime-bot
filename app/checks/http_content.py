import time
from bs4 import BeautifulSoup
import httpx

from app.schemas import CheckOutcome

DEFAULT_HEADERS = {
    "User-Agent": (
        "MKB-UptimeBot/1.0 (+https://jouwmkbmonitor.nl; "
        "Website & Contactformulier Bewaking)"
    ),
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "nl-NL,nl;q=0.9,en;q=0.8",
}


def inspect_html_form(html: str, form_selector: str | None) -> tuple[bool, str | None]:
    """Controleert of het verwachte formulier aanwezig, voorzien van invoervelden en verzendbaar is."""
    if not form_selector or not form_selector.strip():
        return True, None

    soup = BeautifulSoup(html, "html.parser")
    selector = form_selector.strip()
    forms = soup.select(selector)
    if not forms:
        return (
            False,
            f"Formulier niet gevonden in HTML voor selector '{selector}'.",
        )

    target_form = forms[0]
    if target_form.name != "form":
        nested = target_form.find("form")
        if nested is not None:
            target_form = nested

    # Controleer of er bruikbare invoervelden aanwezig zijn
    inputs = target_form.find_all(["input", "textarea", "select"])
    visible_inputs = [
        inp
        for inp in inputs
        if (inp.get("type") or "text").lower() not in {"hidden", "submit", "button", "reset"}
    ]
    if not visible_inputs:
        return (
            False,
            f"Formulier '{selector}' bevat geen zichtbare invoervelden (input/textarea/select).",
        )

    # Controleer of er een actieve verzendknop aanwezig is
    submit_candidates = target_form.select(
        'button[type="submit"], button:not([type]), input[type="submit"]'
    )
    if not submit_candidates:
        return (
            False,
            f"Formulier '{selector}' mist een verzendknop (button of input[type=submit]).",
        )

    active_submits = [btn for btn in submit_candidates if not btn.has_attr("disabled")]
    if not active_submits:
        return (
            False,
            f"De verzendknop van formulier '{selector}' staat op disabled.",
        )

    return True, f"Formulier '{selector}' geldig ({len(visible_inputs)} velden gevonden)."


def inspect_keywords(html: str, expected_keywords: list[str]) -> tuple[bool, list[str]]:
    """Controleert case-insensitive of alle verwachte kernwoorden op de pagina staan."""
    if not expected_keywords:
        return True, []

    soup = BeautifulSoup(html, "html.parser")
    visible_text = soup.get_text(separator=" ", strip=True).lower()
    raw_lower = html.lower()

    missing: list[str] = []
    for kw in expected_keywords:
        needle = kw.strip().lower()
        if not needle:
            continue
        if needle not in visible_text and needle not in raw_lower:
            missing.append(kw.strip())

    return len(missing) == 0, missing


async def run_http_and_content_check(
    url: str,
    expected_keywords: list[str] | None = None,
    form_selector: str | None = None,
    max_response_ms: int = 2500,
    timeout_seconds: float = 12.0,
    client: httpx.AsyncClient | None = None,
) -> CheckOutcome:
    """Voert de 1-minuut HTTP-, laadtijd-, kernwoord- en DOM-formuliercheck uit."""
    keywords = expected_keywords or []
    owns_client = client is None
    http_client = client or httpx.AsyncClient(
        headers=DEFAULT_HEADERS,
        follow_redirects=True,
        timeout=httpx.Timeout(timeout_seconds),
    )

    start_ns = time.perf_counter_ns()
    try:
        response = await http_client.get(url)
        elapsed_ms = max(1, int((time.perf_counter_ns() - start_ns) / 1_000_000))
    except httpx.TimeoutException:
        elapsed_ms = int((time.perf_counter_ns() - start_ns) / 1_000_000)
        return CheckOutcome(
            check_type="fast_http",
            is_up=False,
            response_ms=elapsed_ms,
            error_code="HTTP_TIMEOUT",
            error_detail=f"Website reageerde niet binnen {timeout_seconds:.0f} seconden.",
        )
    except httpx.RequestError as exc:
        elapsed_ms = int((time.perf_counter_ns() - start_ns) / 1_000_000)
        return CheckOutcome(
            check_type="fast_http",
            is_up=False,
            response_ms=elapsed_ms,
            error_code="CONNECTION_ERROR",
            error_detail=f"Verbindingsfout bij openen van {url}: {exc}",
        )
    finally:
        if owns_client:
            await http_client.aclose()

    status_code = response.status_code
    if status_code >= 400 or status_code < 200:
        return CheckOutcome(
            check_type="fast_http",
            is_up=False,
            status_code=status_code,
            response_ms=elapsed_ms,
            error_code=f"HTTP_{status_code}",
            error_detail=f"Onverwachte HTTP-statuscode {status_code} ontvangen.",
        )

    html_body = response.text or ""

    # 1. Kernwoorden-check
    keywords_ok, missing_keywords = inspect_keywords(html_body, keywords)
    if not keywords_ok:
        missing_str = ", ".join(missing_keywords)
        return CheckOutcome(
            check_type="fast_http",
            is_up=False,
            status_code=status_code,
            response_ms=elapsed_ms,
            keywords_ok=False,
            missing_keywords=missing_keywords,
            error_code="KEYWORD_MISSING",
            error_detail=f"Verplichte kernwoorden ontbreken op pagina: {missing_str}",
        )

    # 2. DOM Formulier-check
    form_ok, form_detail = inspect_html_form(html_body, form_selector)
    if not form_ok:
        return CheckOutcome(
            check_type="fast_http",
            is_up=False,
            status_code=status_code,
            response_ms=elapsed_ms,
            keywords_ok=True,
            form_ok=False,
            form_detail=form_detail,
            error_code="FORM_BROKEN",
            error_detail=form_detail or "Contactformulier ontbreekt of is ongeldig.",
        )

    # 3. Laadtijd-drempel check (Degraded bij overschrijding)
    is_slow = elapsed_ms > max_response_ms
    return CheckOutcome(
        check_type="fast_http",
        is_up=True,
        is_degraded=is_slow,
        status_code=status_code,
        response_ms=elapsed_ms,
        keywords_ok=True,
        form_ok=True,
        form_detail=form_detail,
        error_code="SLOW_RESPONSE" if is_slow else None,
        error_detail=(
            f"Trage laadtijd: {elapsed_ms} ms (drempel is {max_response_ms} ms)."
            if is_slow
            else None
        ),
    )
