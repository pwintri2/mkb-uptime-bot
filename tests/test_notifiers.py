import httpx
import pytest

from app.config import settings
from app.models import (
    ChannelType,
    Incident,
    Monitor,
    MonitorStatus,
    NotificationChannel,
    utcnow,
)
from app.services.notifier import NotificationDispatcher


@pytest.mark.asyncio
async def test_notification_dispatcher_channels(monkeypatch):
    captured_requests: list[httpx.Request] = []

    def _handler(request: httpx.Request) -> httpx.Response:
        captured_requests.append(request)
        return httpx.Response(200, json={"ok": True})

    monkeypatch.setattr(settings, "telegram_bot_token", "12345:TEST_TOKEN")
    monkeypatch.setattr(settings, "resend_api_key", "re_test_key")
    monkeypatch.setattr(settings, "whatsapp_webhook_url", "https://webhook.example.com/wa")

    transport = httpx.MockTransport(_handler)
    async with httpx.AsyncClient(transport=transport) as client:
        dispatcher = NotificationDispatcher(http_client=client)
        monitor = Monitor(
            id=1,
            subscriber_id=1,
            name="Kapsalon Mooi",
            url="https://kapsalon-mooi.nl",
        )
        incident = Incident(
            id=10,
            monitor_id=1,
            started_at=utcnow(),
            severity=MonitorStatus.DOWN,
            reason_code="FORM_BROKEN",
            summary="Contactformulier niet gevonden op pagina.",
        )
        channels = [
            NotificationChannel(
                subscriber_id=1,
                channel_type=ChannelType.TELEGRAM,
                target="11223344",
                is_enabled=True,
            ),
            NotificationChannel(
                subscriber_id=1,
                channel_type=ChannelType.EMAIL,
                target="eigenaar@kapsalon-mooi.nl",
                is_enabled=True,
            ),
            NotificationChannel(
                subscriber_id=1,
                channel_type=ChannelType.WHATSAPP,
                target="+31612345678",
                is_enabled=True,
            ),
        ]

        sent_count = await dispatcher.dispatch_for_incident(
            monitor, incident, channels, is_recovery=False
        )

    assert sent_count == 3
    assert len(captured_requests) == 3
    urls = [str(r.url) for r in captured_requests]
    assert any("api.telegram.org" in u for u in urls)
    assert any("api.resend.com" in u for u in urls)
    assert any("webhook.example.com/wa" in u for u in urls)
