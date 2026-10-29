import asyncio
from datetime import timedelta
import logging
from apscheduler.schedulers.asyncio import AsyncIOScheduler
from sqlalchemy import delete, select

from app.checks.browser_form import run_playwright_form_check
from app.checks.http_content import run_http_and_content_check
from app.checks.ssl_checker import run_ssl_check
from app.config import settings
from app.database import AsyncSessionLocal
from app.models import CheckResult, Monitor, PlanTier, Subscriber, utcnow
from app.services.incident_engine import process_check_outcome

logger = logging.getLogger(__name__)


async def execute_single_monitor_check(
    monitor_id: int, include_deep_checks: bool = False
) -> None:
    """Voert de geconfigureerde checks uit voor één specifieke monitor."""
    async with AsyncSessionLocal() as session:
        stmt = (
            select(Monitor, Subscriber)
            .join(Subscriber, Monitor.subscriber_id == Subscriber.id)
            .where(Monitor.id == monitor_id)
        )
        row = (await session.execute(stmt)).first()
        if not row:
            return
        monitor, subscriber = row
        if monitor.is_paused or not subscriber.is_active:
            return

        # 1. Snelle HTTP + Kernwoord + DOM-formulier check
        outcome = await run_http_and_content_check(
            url=monitor.url,
            expected_keywords=monitor.keyword_list,
            form_selector=monitor.check_form_selector,
            max_response_ms=monitor.max_response_ms,
        )

        # Directe retry na 2 seconden bij allereerste fout om netwerkhickups te filteren
        if not outcome.is_up and monitor.consecutive_failures == 0:
            await asyncio.sleep(2.0)
            retry_outcome = await run_http_and_content_check(
                url=monitor.url,
                expected_keywords=monitor.keyword_list,
                form_selector=monitor.check_form_selector,
                max_response_ms=monitor.max_response_ms,
            )
            if not retry_outcome.is_up:
                # Registreer de 1e fout én de bevestigde 2e fout zodat direct gealarmeerd wordt
                await process_check_outcome(session, monitor, outcome)
                outcome = retry_outcome
            else:
                outcome = retry_outcome

        await process_check_outcome(session, monitor, outcome)

        # 2. Optionele diepe checks (SSL + Playwright Headless Formulier)
        if include_deep_checks or monitor.ssl_days_remaining is None:
            ssl_outcome = await run_ssl_check(
                url=monitor.url, warn_days=monitor.ssl_warn_days
            )
            if ssl_outcome.ssl_days_left is not None or not ssl_outcome.is_up:
                await process_check_outcome(session, monitor, ssl_outcome)

        if (
            include_deep_checks
            and settings.enable_playwright
            and monitor.check_form_selector
            and subscriber.plan_tier in (PlanTier.PRO, PlanTier.COMPLEET)
            and outcome.is_up
        ):
            pw_outcome = await run_playwright_form_check(
                url=monitor.url,
                form_selector=monitor.check_form_selector,
            )
            await process_check_outcome(session, monitor, pw_outcome)


async def run_all_fast_checks() -> None:
    """Wordt elke minuut aangeroepen voor alle actieve monitors."""
    async with AsyncSessionLocal() as session:
        stmt = (
            select(Monitor.id)
            .join(Subscriber, Monitor.subscriber_id == Subscriber.id)
            .where(Monitor.is_paused.is_(False), Subscriber.is_active.is_(True))
        )
        monitor_ids = list((await session.execute(stmt)).scalars().all())

    if not monitor_ids:
        return

    semaphore = asyncio.Semaphore(settings.max_concurrent_checks)

    async def _bounded_check(m_id: int) -> None:
        async with semaphore:
            try:
                await execute_single_monitor_check(m_id, include_deep_checks=False)
            except Exception as exc:
                logger.exception("Fout tijdens 1-minuut check voor monitor %s: %s", m_id, exc)

    await asyncio.gather(*(_bounded_check(m_id) for m_id in monitor_ids))


async def run_all_deep_checks() -> None:
    """Wordt elke 15 minuten aangeroepen voor SSL- en Playwright formulier-checks."""
    async with AsyncSessionLocal() as session:
        stmt = (
            select(Monitor.id)
            .join(Subscriber, Monitor.subscriber_id == Subscriber.id)
            .where(Monitor.is_paused.is_(False), Subscriber.is_active.is_(True))
        )
        monitor_ids = list((await session.execute(stmt)).scalars().all())

    if not monitor_ids:
        return

    semaphore = asyncio.Semaphore(5)

    async def _bounded_deep(m_id: int) -> None:
        async with semaphore:
            try:
                await execute_single_monitor_check(m_id, include_deep_checks=True)
            except Exception as exc:
                logger.exception("Fout tijdens diepe check voor monitor %s: %s", m_id, exc)

    await asyncio.gather(*(_bounded_deep(m_id) for m_id in monitor_ids))


async def cleanup_old_check_results() -> None:
    """Verwijdert gedetailleerde CheckResult-rijen ouder dan `retention_days`."""
    cutoff = utcnow() - timedelta(days=settings.retention_days)
    async with AsyncSessionLocal() as session:
        stmt = delete(CheckResult).where(CheckResult.checked_at < cutoff)
        await session.execute(stmt)
        await session.commit()


def create_scheduler() -> AsyncIOScheduler:
    scheduler = AsyncIOScheduler()
    scheduler.add_job(
        run_all_fast_checks,
        "interval",
        seconds=settings.fast_check_interval_seconds,
        id="fast_1min_checks",
        replace_existing=True,
        max_instances=1,
        coalesce=True,
    )
    scheduler.add_job(
        run_all_deep_checks,
        "interval",
        minutes=settings.deep_check_interval_minutes,
        id="deep_15min_checks",
        replace_existing=True,
        max_instances=1,
        coalesce=True,
    )
    scheduler.add_job(
        cleanup_old_check_results,
        "cron",
        hour=3,
        minute=15,
        id="daily_retention_cleanup",
        replace_existing=True,
    )
    return scheduler
