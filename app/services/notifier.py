import asyncio
from email.message import EmailMessage
import logging
import smtplib
import httpx

from app.config import settings
from app.models import ChannelType, Incident, Monitor, NotificationChannel

logger = logging.getLogger(__name__)


def format_alert_message(monitor: Monitor, incident: Incident, is_recovery: bool = False) -> str:
    """Formatteert een helder, actiegericht bericht voor MKB-ondernemers."""
    if is_recovery:
        duration_str = ""
        if incident.started_at and incident.resolved_at:
            seconds = max(1, int((incident.resolved_at - incident.started_at).total_seconds()))
            minutes = max(1, round(seconds / 60))
            duration_str = f" (na ~{minutes} min)"
        return (
            f"🟢 WEER ONLINE: {monitor.name}{duration_str}\n"
            f"URL: {monitor.url}\n"
            f"Opgelost probleem: {incident.summary}"
        )

    icon = "🟠 WAARSCHUWING" if incident.severity.value == "degraded" else "🔴 STORING GEDETECTEERD"
    return (
        f"{icon}: {monitor.name}\n"
        f"URL: {monitor.url}\n"
        f"Oorzaak [{incident.reason_code}]: {incident.summary}\n"
        f"Tijdstip: {incident.started_at.strftime('%d-%m-%Y %H:%M:%S UTC')}"
    )


class NotificationDispatcher:
    """Verstuurt meldingen via Telegram, E-mail (Resend/SMTP) en WhatsApp (Twilio/Webhook)."""

    def __init__(self, http_client: httpx.AsyncClient | None = None) -> None:
        self._client = http_client

    async def dispatch_for_incident(
        self,
        monitor: Monitor,
        incident: Incident,
        channels: list[NotificationChannel],
        is_recovery: bool = False,
    ) -> int:
        message = format_alert_message(monitor, incident, is_recovery=is_recovery)
        subject = (
            f"🟢 Weer online: {monitor.name}"
            if is_recovery
            else f"🔴 Storing op {monitor.name} ({incident.reason_code})"
        )

        sent_count = 0
        owns_client = self._client is None
        client = self._client or httpx.AsyncClient(timeout=10.0)
        try:
            for ch in channels:
                if not ch.is_enabled:
                    continue
                ok = False
                if ch.channel_type == ChannelType.TELEGRAM:
                    ok = await self.send_telegram(client, ch.target, message)
                elif ch.channel_type == ChannelType.EMAIL:
                    ok = await self.send_email(client, ch.target, subject, message)
                elif ch.channel_type == ChannelType.WHATSAPP:
                    ok = await self.send_whatsapp(client, ch.target, message)
                if ok:
                    sent_count += 1
        finally:
            if owns_client:
                await client.aclose()

        return sent_count

    async def send_telegram(
        self, client: httpx.AsyncClient, chat_id: str, text: str
    ) -> bool:
        token = settings.telegram_bot_token
        if not token:
            logger.info("[Telegram Simulatie -> %s] %s", chat_id, text.replace("\n", " | "))
            return True

        url = f"https://api.telegram.org/bot{token}/sendMessage"
        payload = {
            "chat_id": chat_id,
            "text": text,
            "disable_web_page_preview": True,
        }
        try:
            resp = await client.post(url, json=payload)
            return resp.status_code < 300
        except httpx.HTTPError as exc:
            logger.error("Telegram verzendfout voor %s: %s", chat_id, exc)
            return False

    async def send_email(
        self, client: httpx.AsyncClient, to_email: str, subject: str, body: str
    ) -> bool:
        # 1. Resend HTTP API indien geconfigureerd
        if settings.resend_api_key:
            try:
                resp = await client.post(
                    "https://api.resend.com/emails",
                    headers={"Authorization": f"Bearer {settings.resend_api_key}"},
                    json={
                        "from": settings.email_from,
                        "to": [to_email],
                        "subject": subject,
                        "text": body,
                    },
                )
                return resp.status_code < 300
            except httpx.HTTPError as exc:
                logger.error("Resend e-mailfout naar %s: %s", to_email, exc)
                return False

        # 2. Standaard SMTP indien geconfigureerd
        if settings.smtp_host:
            try:
                await asyncio.to_thread(
                    self._send_smtp_sync, to_email, subject, body
                )
                return True
            except Exception as exc:
                logger.error("SMTP fout naar %s: %s", to_email, exc)
                return False

        logger.info("[E-mail Simulatie -> %s] %s: %s", to_email, subject, body.replace("\n", " | "))
        return True

    def _send_smtp_sync(self, to_email: str, subject: str, body: str) -> None:
        msg = EmailMessage()
        msg["Subject"] = subject
        msg["From"] = settings.email_from
        msg["To"] = to_email
        msg.set_content(body)

        with smtplib.SMTP(settings.smtp_host, settings.smtp_port, timeout=10) as server:
            server.ehlo()
            if settings.smtp_port in (587, 2525):
                server.starttls()
            if settings.smtp_user and settings.smtp_password:
                server.login(settings.smtp_user, settings.smtp_password)
            server.send_message(msg)

    async def send_whatsapp(
        self, client: httpx.AsyncClient, phone_number: str, text: str
    ) -> bool:
        # 1. Twilio WhatsApp API indien geconfigureerd
        if settings.twilio_account_sid and settings.twilio_auth_token:
            to_formatted = (
                phone_number
                if phone_number.startswith("whatsapp:")
                else f"whatsapp:{phone_number}"
            )
            url = (
                f"https://api.twilio.com/2010-04-01/Accounts/"
                f"{settings.twilio_account_sid}/Messages.json"
            )
            try:
                resp = await client.post(
                    url,
                    data={
                        "From": settings.twilio_whatsapp_from,
                        "To": to_formatted,
                        "Body": text,
                    },
                    auth=(settings.twilio_account_sid, settings.twilio_auth_token),
                )
                return resp.status_code < 300
            except httpx.HTTPError as exc:
                logger.error("Twilio WhatsApp fout naar %s: %s", phone_number, exc)
                return False

        # 2. Generieke WhatsApp Cloud / n8n / Make webhook adapter
        if settings.whatsapp_webhook_url:
            try:
                resp = await client.post(
                    settings.whatsapp_webhook_url,
                    json={"to": phone_number, "message": text},
                )
                return resp.status_code < 300
            except httpx.HTTPError as exc:
                logger.error("WhatsApp webhook fout naar %s: %s", phone_number, exc)
                return False

        logger.info("[WhatsApp Simulatie -> %s] %s", phone_number, text.replace("\n", " | "))
        return True
