from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import settings
from app.models import (
    CheckResult,
    Incident,
    Monitor,
    MonitorStatus,
    NotificationChannel,
    utcnow,
)
from app.schemas import CheckOutcome
from app.services.notifier import NotificationDispatcher


async def process_check_outcome(
    session: AsyncSession,
    monitor: Monitor,
    outcome: CheckOutcome,
    dispatcher: NotificationDispatcher | None = None,
    confirmation_threshold: int | None = None,
) -> Incident | None:
    """
    Slaat het CheckResult op, werkt de Monitor-status bij met anti-flapping logica,
    en opent of sluit automatisch een Incident (inclusief notificaties).
    """
    threshold = (
        confirmation_threshold
        if confirmation_threshold is not None
        else settings.confirmation_threshold
    )
    notifier = dispatcher or NotificationDispatcher()
    now = utcnow()

    # 1. Sla de individuele meting op
    check_row = CheckResult(
        monitor_id=monitor.id,
        checked_at=now,
        check_type=outcome.check_type,
        is_up=outcome.is_up,
        is_degraded=outcome.is_degraded,
        status_code=outcome.status_code,
        response_ms=outcome.response_ms,
        keywords_ok=outcome.keywords_ok,
        form_ok=outcome.form_ok,
        ssl_days_left=outcome.ssl_days_left,
        error_code=outcome.error_code,
        error_detail=outcome.error_detail,
    )
    session.add(check_row)

    # 2. Werk laatst bekende eigenschappen op Monitor bij
    monitor.last_checked_at = now
    if outcome.response_ms is not None:
        monitor.last_response_ms = outcome.response_ms
    if outcome.status_code is not None:
        monitor.last_status_code = outcome.status_code
    if outcome.ssl_days_left is not None:
        monitor.ssl_days_remaining = outcome.ssl_days_left
    if outcome.ssl_expires_at is not None:
        monitor.ssl_expires_at = outcome.ssl_expires_at

    if outcome.check_type in ("fast_http", "playwright_form"):
        monitor.form_status_ok = outcome.form_ok
    if outcome.check_type == "fast_http":
        monitor.keywords_status_ok = outcome.keywords_ok

    # Zoek eventueel openstaand incident voor deze monitor
    stmt_open = (
        select(Incident)
        .where(Incident.monitor_id == monitor.id, Incident.resolved_at.is_(None))
        .order_by(Incident.started_at.desc())
    )
    open_incident = (await session.execute(stmt_open)).scalars().first()

    stmt_channels = select(NotificationChannel).where(
        NotificationChannel.subscriber_id == monitor.subscriber_id,
        NotificationChannel.is_enabled.is_(True),
    )
    channels = list((await session.execute(stmt_channels)).scalars().all())

    # 3A. Check is gefaald (is_up == False)
    if not outcome.is_up:
        monitor.consecutive_failures += 1
        monitor.last_error_reason = outcome.error_detail or outcome.error_code or "Onbekende fout"

        if monitor.consecutive_failures >= threshold:
            monitor.status = MonitorStatus.DOWN
            if open_incident is None:
                new_incident = Incident(
                    monitor_id=monitor.id,
                    started_at=now,
                    severity=MonitorStatus.DOWN,
                    reason_code=outcome.error_code or "DOWN",
                    summary=monitor.last_error_reason,
                    notification_sent=False,
                )
                session.add(new_incident)
                await session.flush()

                sent = await notifier.dispatch_for_incident(
                    monitor, new_incident, channels, is_recovery=False
                )
                new_incident.notification_sent = sent > 0
                await session.commit()
                return new_incident
            else:
                # Als een bestaand DEGRADED incident nu escaleert naar DOWN
                if open_incident.severity == MonitorStatus.DEGRADED:
                    open_incident.severity = MonitorStatus.DOWN
                    open_incident.reason_code = outcome.error_code or "DOWN"
                    open_incident.summary = monitor.last_error_reason
                    await notifier.dispatch_for_incident(
                        monitor, open_incident, channels, is_recovery=False
                    )
        await session.commit()
        return open_incident

    # 3B. Check is geslaagd (is_up == True), controleer op DEGRADED (trage site of bijna verlopen SSL)
    monitor.consecutive_failures = 0

    if outcome.is_degraded:
        if outcome.error_code == "SLOW_RESPONSE":
            monitor.consecutive_slow += 1
        else:
            # Bijv. SSL_EXPIRING_SOON is direct relevant
            monitor.consecutive_slow = threshold

        monitor.last_error_reason = outcome.error_detail
        if monitor.consecutive_slow >= threshold:
            monitor.status = MonitorStatus.DEGRADED
            if open_incident is None:
                degraded_incident = Incident(
                    monitor_id=monitor.id,
                    started_at=now,
                    severity=MonitorStatus.DEGRADED,
                    reason_code=outcome.error_code or "DEGRADED",
                    summary=outcome.error_detail or "Vertraagde laadtijd of SSL-waarschuwing",
                    notification_sent=False,
                )
                session.add(degraded_incident)
                await session.flush()
                sent = await notifier.dispatch_for_incident(
                    monitor, degraded_incident, channels, is_recovery=False
                )
                degraded_incident.notification_sent = sent > 0
                await session.commit()
                return degraded_incident
        await session.commit()
        return open_incident

    # 3C. Volledig gezond (UP en niet DEGRADED)
    monitor.consecutive_slow = 0
    monitor.status = MonitorStatus.UP
    monitor.last_error_reason = None

    if open_incident is not None:
        # Sluit een SSL-incident niet per ongeluk tijdens een gewone HTTP-check
        if open_incident.reason_code.startswith("SSL_") and outcome.check_type != "ssl":
            monitor.status = open_incident.severity
            await session.commit()
            return open_incident

        open_incident.resolved_at = now
        await session.flush()
        await notifier.dispatch_for_incident(
            monitor, open_incident, channels, is_recovery=True
        )
        await session.commit()
        return open_incident

    await session.commit()
    return None
