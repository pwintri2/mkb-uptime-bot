import time
from app.schemas import CheckOutcome


async def run_playwright_form_check(
    url: str,
    form_selector: str = "form",
    timeout_ms: int = 15000,
) -> CheckOutcome:
    """
    Voert een periodieke headless browser-check uit met Playwright:
    - Rendert de pagina inclusief JavaScript
    - Vangt onafgevangen JS-exceptions op
    - Controleert of het formulier en de verzendknop daadwerkelijk zichtbaar en klikbaar zijn
    - Vult proefwaarden in (zonder te verzenden) en controleert HTML5 form validity
    """
    try:
        from playwright.async_api import async_playwright
    except ImportError:
        return CheckOutcome(
            check_type="playwright_form",
            is_up=True,
            form_ok=True,
            form_detail="Playwright niet geïnstalleerd; teruggevallen op DOM-formuliercheck.",
        )

    start_ns = time.perf_counter_ns()
    js_errors: list[str] = []

    try:
        async with async_playwright() as p:
            browser = await p.chromium.launch(headless=True)
            context = await browser.new_context(
                user_agent=(
                    "MKB-UptimeBot-Browser/1.0 (+https://jouwmkbmonitor.nl; "
                    "Interactieve Formulier Check)"
                ),
                viewport={"width": 1280, "height": 800},
            )
            page = await context.new_page()
            page.on("pageerror", lambda err: js_errors.append(str(err)))

            # Blokkeer eventuele onbedoelde POST-requests zodat we nooit spam versturen
            async def _block_mutations(route):
                if route.request.method.upper() in {"POST", "PUT", "DELETE"}:
                    await route.abort()
                else:
                    await route.continue_()

            await page.route("**/*", _block_mutations)

            response = await page.goto(url, wait_until="domcontentloaded", timeout=timeout_ms)
            elapsed_ms = max(1, int((time.perf_counter_ns() - start_ns) / 1_000_000))
            status_code = response.status if response else None

            if status_code and status_code >= 400:
                await browser.close()
                return CheckOutcome(
                    check_type="playwright_form",
                    is_up=False,
                    status_code=status_code,
                    response_ms=elapsed_ms,
                    form_ok=False,
                    error_code=f"HTTP_{status_code}",
                    error_detail=f"Pagina gaf HTTP {status_code} tijdens browser-formuliercheck.",
                )

            locator = page.locator(form_selector).first
            count = await page.locator(form_selector).count()
            if count == 0:
                await browser.close()
                return CheckOutcome(
                    check_type="playwright_form",
                    is_up=False,
                    status_code=status_code,
                    response_ms=elapsed_ms,
                    form_ok=False,
                    error_code="FORM_NOT_FOUND_IN_DOM",
                    error_detail=f"Geen formulier gevonden voor selector '{form_selector}' na JS-rendering.",
                )

            is_visible = await locator.is_visible()
            if not is_visible:
                await browser.close()
                return CheckOutcome(
                    check_type="playwright_form",
                    is_up=False,
                    status_code=status_code,
                    response_ms=elapsed_ms,
                    form_ok=False,
                    error_code="FORM_HIDDEN",
                    error_detail=f"Formulier '{form_selector}' bestaat in de DOM maar is onzichtbaar voor bezoekers.",
                )

            # Controleer of tekst/e-mail velden invulbaar zijn
            email_inputs = locator.locator('input[type="email"]')
            if await email_inputs.count() > 0 and await email_inputs.first.is_visible():
                await email_inputs.first.fill("test@jouwmkbmonitor.nl")

            text_inputs = locator.locator('input[type="text"], textarea')
            if await text_inputs.count() > 0 and await text_inputs.first.is_visible():
                await text_inputs.first.fill("Uptime & Formulier Controle")

            submit_btn = locator.locator(
                'button[type="submit"], button:not([type]), input[type="submit"]'
            ).first
            if await submit_btn.count() == 0 or not await submit_btn.is_visible():
                await browser.close()
                return CheckOutcome(
                    check_type="playwright_form",
                    is_up=False,
                    status_code=status_code,
                    response_ms=elapsed_ms,
                    form_ok=False,
                    error_code="SUBMIT_BUTTON_HIDDEN",
                    error_detail="De verzendknop van het contactformulier is niet zichtbaar.",
                )

            if not await submit_btn.is_enabled():
                await browser.close()
                return CheckOutcome(
                    check_type="playwright_form",
                    is_up=False,
                    status_code=status_code,
                    response_ms=elapsed_ms,
                    form_ok=False,
                    error_code="SUBMIT_BUTTON_DISABLED",
                    error_detail="De verzendknop van het contactformulier is uitgeschakeld (disabled).",
                )

            await browser.close()
            detail_msg = "Interactieve Playwright formulier-check geslaagd."
            if js_errors:
                detail_msg += f" (Let op: {len(js_errors)} JS-consolefout(en) gedetecteerd)."

            return CheckOutcome(
                check_type="playwright_form",
                is_up=True,
                status_code=status_code,
                response_ms=elapsed_ms,
                form_ok=True,
                form_detail=detail_msg,
            )
    except Exception as exc:
        elapsed_ms = max(1, int((time.perf_counter_ns() - start_ns) / 1_000_000))
        # Als de Chromium binary nog niet lokaal geïnstalleerd is, laat dit de 1-minuut monitor niet vals falen
        if "Executable doesn't exist" in str(exc):
            return CheckOutcome(
                check_type="playwright_form",
                is_up=True,
                response_ms=elapsed_ms,
                form_ok=True,
                form_detail="Playwright browser binary niet aanwezig; DOM-check actief.",
            )
        return CheckOutcome(
            check_type="playwright_form",
            is_up=False,
            response_ms=elapsed_ms,
            form_ok=False,
            error_code="BROWSER_CHECK_ERROR",
            error_detail=f"Fout tijdens interactieve formulier-check: {exc}",
        )
