import asyncio
from datetime import datetime, timezone
import socket
import ssl
from urllib.parse import urlparse

from app.schemas import CheckOutcome


def _fetch_certificate_expiry_sync(hostname: str, port: int = 443, timeout: float = 8.0) -> datetime:
    context = ssl.create_default_context()
    with socket.create_connection((hostname, port), timeout=timeout) as sock:
        with context.wrap_socket(sock, server_hostname=hostname) as ssock:
            cert = ssock.getpeercert()
            if not cert or "notAfter" not in cert:
                raise ssl.SSLError("Geen geldig X.509 certificaat ontvangen.")
            not_after_str = cert["notAfter"]
            # Voorbeeld formaat: 'Dec 15 23:59:59 2026 GMT'
            expires_naive = datetime.strptime(not_after_str, "%b %d %H:%M:%S %Y %Z")
            return expires_naive.replace(tzinfo=timezone.utc)


def evaluate_ssl_expiry(
    expires_at: datetime,
    warn_days: int = 14,
    now: datetime | None = None,
) -> CheckOutcome:
    """Beoordeelt een SSL-verloopdatum ten opzichte van het huidige tijdstip."""
    reference_now = now or datetime.now(timezone.utc)
    delta = expires_at - reference_now
    days_left = int(delta.total_seconds() // 86400)

    if delta.total_seconds() <= 0:
        return CheckOutcome(
            check_type="ssl",
            is_up=False,
            ssl_days_left=days_left,
            ssl_expires_at=expires_at,
            error_code="SSL_EXPIRED",
            error_detail=f"SSL-certificaat is verlopen op {expires_at.strftime('%d-%m-%Y')}!",
        )

    if days_left <= warn_days:
        return CheckOutcome(
            check_type="ssl",
            is_up=True,
            is_degraded=True,
            ssl_days_left=days_left,
            ssl_expires_at=expires_at,
            error_code="SSL_EXPIRING_SOON",
            error_detail=(
                f"SSL-certificaat verloopt over {days_left} dagen "
                f"(op {expires_at.strftime('%d-%m-%Y')})."
            ),
        )

    return CheckOutcome(
        check_type="ssl",
        is_up=True,
        is_degraded=False,
        ssl_days_left=days_left,
        ssl_expires_at=expires_at,
    )


async def run_ssl_check(url: str, warn_days: int = 14) -> CheckOutcome:
    """Controleert asynchroon het SSL/TLS-certificaat van een HTTPS-domein."""
    parsed = urlparse(url)
    if parsed.scheme.lower() != "https":
        return CheckOutcome(
            check_type="ssl",
            is_up=True,
            is_degraded=False,
            error_detail="Geen HTTPS-URL opgegeven; SSL-check overgeslagen.",
        )

    hostname = parsed.hostname
    port = parsed.port or 443
    if not hostname:
        return CheckOutcome(
            check_type="ssl",
            is_up=False,
            error_code="SSL_INVALID_HOST",
            error_detail=f"Ongeldige hostnaam in URL: {url}",
        )

    try:
        expires_at = await asyncio.to_thread(
            _fetch_certificate_expiry_sync, hostname, port
        )
        return evaluate_ssl_expiry(expires_at, warn_days=warn_days)
    except ssl.SSLCertVerificationError as exc:
        return CheckOutcome(
            check_type="ssl",
            is_up=False,
            error_code="SSL_VERIFICATION_FAILED",
            error_detail=f"SSL-certificaat ongeldig voor {hostname}: {exc.verify_message or exc}",
        )
    except (ssl.SSLError, socket.timeout, OSError) as exc:
        return CheckOutcome(
            check_type="ssl",
            is_up=False,
            error_code="SSL_HANDSHAKE_ERROR",
            error_detail=f"Kon SSL-certificaat van {hostname} niet controleren: {exc}",
        )
