from datetime import datetime, timedelta, timezone
import httpx
import pytest

from app.checks.http_content import run_http_and_content_check
from app.checks.ssl_checker import evaluate_ssl_expiry

VALID_MKB_HTML = """
<!DOCTYPE html>
<html>
<body>
    <h1>Bakkerij Jansen</h1>
    <p>Onze Openingstijden zijn ma-za en u kunt online Bestellen.</p>
    <form id="contact-form" action="/contact" method="post">
        <input type="text" name="name" required />
        <input type="email" name="email" required />
        <textarea name="message"></textarea>
        <button type="submit">Versturen</button>
    </form>
</body>
</html>
"""


@pytest.mark.asyncio
async def test_http_content_check_all_ok():
    transport = httpx.MockTransport(
        lambda request: httpx.Response(200, text=VALID_MKB_HTML)
    )
    async with httpx.AsyncClient(transport=transport) as client:
        outcome = await run_http_and_content_check(
            url="https://bakkerij-jansen.nl/contact",
            expected_keywords=["Openingstijden", "Bestellen"],
            form_selector="form#contact-form",
            max_response_ms=2500,
            client=client,
        )

    assert outcome.is_up is True
    assert outcome.is_degraded is False
    assert outcome.keywords_ok is True
    assert outcome.form_ok is True
    assert outcome.error_code is None


@pytest.mark.asyncio
async def test_http_content_check_missing_keyword():
    transport = httpx.MockTransport(
        lambda request: httpx.Response(200, text=VALID_MKB_HTML)
    )
    async with httpx.AsyncClient(transport=transport) as client:
        outcome = await run_http_and_content_check(
            url="https://bakkerij-jansen.nl/contact",
            expected_keywords=["Openingstijden", "WinkelwagenOnbekend"],
            form_selector="form#contact-form",
            client=client,
        )

    assert outcome.is_up is False
    assert outcome.keywords_ok is False
    assert outcome.error_code == "KEYWORD_MISSING"
    assert "WinkelwagenOnbekend" in outcome.missing_keywords


@pytest.mark.asyncio
async def test_http_content_check_broken_form():
    broken_html = "<html><body><h1>Bakkerij Jansen</h1><p>Openingstijden</p></body></html>"
    transport = httpx.MockTransport(
        lambda request: httpx.Response(200, text=broken_html)
    )
    async with httpx.AsyncClient(transport=transport) as client:
        outcome = await run_http_and_content_check(
            url="https://bakkerij-jansen.nl/contact",
            expected_keywords=["Openingstijden"],
            form_selector="form#contact-form",
            client=client,
        )

    assert outcome.is_up is False
    assert outcome.form_ok is False
    assert outcome.error_code == "FORM_BROKEN"


def test_ssl_expiry_evaluation():
    now = datetime(2026, 10, 29, 12, 0, tzinfo=timezone.utc)

    # 1. Ruim geldig (45 dagen)
    ok_outcome = evaluate_ssl_expiry(now + timedelta(days=45), warn_days=14, now=now)
    assert ok_outcome.is_up is True
    assert ok_outcome.is_degraded is False
    assert ok_outcome.ssl_days_left == 45

    # 2. Verloopt binnenkort (7 dagen <= 14 warn_days)
    warn_outcome = evaluate_ssl_expiry(now + timedelta(days=7), warn_days=14, now=now)
    assert warn_outcome.is_up is True
    assert warn_outcome.is_degraded is True
    assert warn_outcome.error_code == "SSL_EXPIRING_SOON"

    # 3. Verlopen (-1 dag)
    expired_outcome = evaluate_ssl_expiry(now - timedelta(days=1), warn_days=14, now=now)
    assert expired_outcome.is_up is False
    assert expired_outcome.error_code == "SSL_EXPIRED"
