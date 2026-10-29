import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from app.database import Base
from app.models import (
    ChannelType,
    Monitor,
    MonitorStatus,
    NotificationChannel,
    PlanTier,
    Subscriber,
)
from app.schemas import CheckOutcome
from app.services.incident_engine import process_check_outcome
from app.services.notifier import NotificationDispatcher


class DummyDispatcher(NotificationDispatcher):
    def __init__(self) -> None:
        super().__init__()
        self.events: list[tuple[str, bool]] = []

    async def dispatch_for_incident(self, monitor, incident, channels, is_recovery=False) -> int:
        self.events.append((incident.reason_code, is_recovery))
        return len(channels)


@pytest.mark.asyncio
async def test_anti_flapping_and_recovery_lifecycle():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

    session_factory = async_sessionmaker(engine, expire_on_commit=False)
    dispatcher = DummyDispatcher()

    async with session_factory() as session:
        sub = Subscriber(
            company_name="Loodgieter De Vries",
            contact_email="info@devries.nl",
            plan_tier=PlanTier.COMPLEET,
        )
        session.add(sub)
        await session.flush()

        session.add(
            NotificationChannel(
                subscriber_id=sub.id,
                channel_type=ChannelType.TELEGRAM,
                target="998877",
            )
        )
        monitor = Monitor(
            subscriber_id=sub.id,
            name="Hoofdpagina",
            url="https://devries.nl",
            expected_keywords="Spoed,Loodgieter",
            check_form_selector="form",
        )
        session.add(monitor)
        await session.commit()

        # 1e mislukte check -> Nog GEEN incident vanwege confirmation_threshold=2 (anti-flapping)
        fail_outcome = CheckOutcome(
            is_up=False,
            status_code=500,
            response_ms=120,
            error_code="HTTP_500",
            error_detail="Internal Server Error",
        )
        inc1 = await process_check_outcome(
            session, monitor, fail_outcome, dispatcher=dispatcher, confirmation_threshold=2
        )
        assert inc1 is None
        assert monitor.consecutive_failures == 1
        assert monitor.status == MonitorStatus.UP
        assert len(dispatcher.events) == 0

        # 2e opeenvolgende mislukte check -> Incident wordt geopend en alert verstuurd!
        inc2 = await process_check_outcome(
            session, monitor, fail_outcome, dispatcher=dispatcher, confirmation_threshold=2
        )
        assert inc2 is not None
        assert inc2.is_open is True
        assert monitor.status == MonitorStatus.DOWN
        assert len(dispatcher.events) == 1
        assert dispatcher.events[0] == ("HTTP_500", False)

        # Website herstelt -> Incident wordt gesloten en "Weer Online" bericht wordt verstuurd
        ok_outcome = CheckOutcome(
            is_up=True,
            status_code=200,
            response_ms=180,
            keywords_ok=True,
            form_ok=True,
        )
        inc_resolved = await process_check_outcome(
            session, monitor, ok_outcome, dispatcher=dispatcher, confirmation_threshold=2
        )
        assert inc_resolved is not None
        assert inc_resolved.is_open is False
        assert monitor.status == MonitorStatus.UP
        assert monitor.consecutive_failures == 0
        assert len(dispatcher.events) == 2
        assert dispatcher.events[1] == ("HTTP_500", True)

    await engine.dispose()
