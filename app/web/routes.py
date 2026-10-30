from pathlib import Path
import secrets
from fastapi import APIRouter, BackgroundTasks, Depends, Form, HTTPException, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.config import settings
from app.database import get_db
from app.models import (
    ChannelType,
    CheckResult,
    Incident,
    Monitor,
    MonitorStatus,
    NotificationChannel,
    PlanTier,
    Subscriber,
)
from app.schemas import ProvisionSubscriberRequest, SubscriptionStatusUpdateRequest
from app.services.scheduler import execute_single_monitor_check

TEMPLATES_DIR = Path(__file__).parent / "templates"
templates = Jinja2Templates(directory=str(TEMPLATES_DIR))

router = APIRouter()


async def _fetch_dashboard_context(session: AsyncSession, root_path: str = "") -> dict:
    stmt = (
        select(Monitor)
        .options(
            selectinload(Monitor.subscriber).selectinload(Subscriber.channels),
            selectinload(Monitor.incidents),
        )
        .order_by(Monitor.id.desc())
    )
    monitors = list((await session.execute(stmt)).scalars().all())

    total_monitors = len(monitors)
    up_count = sum(1 for m in monitors if m.status == MonitorStatus.UP)
    degraded_count = sum(1 for m in monitors if m.status == MonitorStatus.DEGRADED)
    down_count = sum(1 for m in monitors if m.status == MonitorStatus.DOWN)

    mrr_eur = sum(
        m.subscriber.plan_tier.monthly_price_eur
        for m in monitors
        if m.subscriber and m.subscriber.is_active and not m.is_paused
    )

    # Bereken totale check-uptime percentage
    total_checks = (
        await session.execute(select(func.count(CheckResult.id)))
    ).scalar_one() or 0
    up_checks = (
        await session.execute(
            select(func.count(CheckResult.id)).where(CheckResult.is_up.is_(True))
        )
    ).scalar_one() or 0
    uptime_pct = round((up_checks / total_checks) * 100, 2) if total_checks > 0 else 100.0

    stmt_incidents = (
        select(Incident)
        .options(selectinload(Incident.monitor))
        .order_by(Incident.started_at.desc())
        .limit(10)
    )
    recent_incidents = list((await session.execute(stmt_incidents)).scalars().all())

    return {
        "root_path": root_path.rstrip("/"),
        "monitors": monitors,
        "total_monitors": total_monitors,
        "up_count": up_count,
        "degraded_count": degraded_count,
        "down_count": down_count,
        "mrr_eur": mrr_eur,
        "uptime_pct": uptime_pct,
        "recent_incidents": recent_incidents,
    }


def _get_prefix(request: Request) -> str:
    prefix = request.headers.get("X-Forwarded-Prefix") or request.scope.get("root_path") or ""
    return prefix.rstrip("/")


@router.get("/", response_class=HTMLResponse)
async def dashboard_home(
    request: Request, session: AsyncSession = Depends(get_db)
) -> HTMLResponse:
    ctx = await _fetch_dashboard_context(session, root_path=_get_prefix(request))
    ctx["request"] = request
    return templates.TemplateResponse(request, "dashboard.html", ctx)


@router.get("/partials/monitors", response_class=HTMLResponse)
async def monitors_partial(
    request: Request, session: AsyncSession = Depends(get_db)
) -> HTMLResponse:
    ctx = await _fetch_dashboard_context(session, root_path=_get_prefix(request))
    ctx["request"] = request
    return templates.TemplateResponse(request, "partials/monitor_table.html", ctx)


@router.post("/monitors", response_class=HTMLResponse)
async def create_monitor(
    request: Request,
    company_name: str = Form(...),
    contact_email: str = Form(...),
    plan_tier: str = Form("pro"),
    monitor_name: str = Form(...),
    url: str = Form(...),
    expected_keywords: str = Form(""),
    check_form_selector: str = Form("form"),
    max_response_ms: int = Form(2500),
    ssl_warn_days: int = Form(14),
    telegram_chat_id: str = Form(""),
    whatsapp_number: str = Form(""),
    session: AsyncSession = Depends(get_db),
):
    tier_enum = PlanTier(plan_tier.lower()) if plan_tier.lower() in {"basis", "pro", "compleet"} else PlanTier.PRO
    normalized_url = url.strip()
    if not normalized_url.startswith(("http://", "https://")):
        normalized_url = f"https://{normalized_url}"

    subscriber = Subscriber(
        company_name=company_name.strip(),
        contact_email=contact_email.strip(),
        plan_tier=tier_enum,
        is_active=True,
    )
    session.add(subscriber)
    await session.flush()

    # Voeg notificatiekanalen toe
    if contact_email.strip():
        session.add(
            NotificationChannel(
                subscriber_id=subscriber.id,
                channel_type=ChannelType.EMAIL,
                target=contact_email.strip(),
            )
        )
    if telegram_chat_id.strip():
        session.add(
            NotificationChannel(
                subscriber_id=subscriber.id,
                channel_type=ChannelType.TELEGRAM,
                target=telegram_chat_id.strip(),
            )
        )
    if whatsapp_number.strip():
        session.add(
            NotificationChannel(
                subscriber_id=subscriber.id,
                channel_type=ChannelType.WHATSAPP,
                target=whatsapp_number.strip(),
            )
        )

    monitor = Monitor(
        subscriber_id=subscriber.id,
        name=monitor_name.strip() or company_name.strip(),
        url=normalized_url,
        expected_keywords=expected_keywords.strip(),
        check_form_selector=check_form_selector.strip() or None,
        max_response_ms=max_response_ms,
        ssl_warn_days=ssl_warn_days,
    )
    session.add(monitor)
    await session.commit()

    prefix = _get_prefix(request)
    return RedirectResponse(url=f"{prefix}/", status_code=303)


@router.post("/monitors/{monitor_id}/check-now", response_class=HTMLResponse)
async def trigger_manual_check(
    monitor_id: int,
    request: Request,
    session: AsyncSession = Depends(get_db),
) -> HTMLResponse:
    await execute_single_monitor_check(monitor_id, include_deep_checks=True)
    prefix = _get_prefix(request)
    if request.headers.get("HX-Request") == "true":
        ctx = await _fetch_dashboard_context(session, root_path=prefix)
        ctx["request"] = request
        return templates.TemplateResponse(request, "partials/monitor_table.html", ctx)
    return RedirectResponse(url=f"{prefix}/monitors/{monitor_id}", status_code=303)


@router.post("/monitors/{monitor_id}/delete")
async def delete_monitor(
    monitor_id: int,
    request: Request,
    session: AsyncSession = Depends(get_db),
):
    monitor = await session.get(Monitor, monitor_id)
    if monitor:
        await session.delete(monitor)
        await session.commit()
    prefix = _get_prefix(request)
    return RedirectResponse(url=f"{prefix}/", status_code=303)


@router.get("/monitors/{monitor_id}", response_class=HTMLResponse)
async def monitor_detail(
    monitor_id: int,
    request: Request,
    session: AsyncSession = Depends(get_db),
) -> HTMLResponse:
    stmt = (
        select(Monitor)
        .options(
            selectinload(Monitor.subscriber).selectinload(Subscriber.channels),
            selectinload(Monitor.incidents),
        )
        .where(Monitor.id == monitor_id)
    )
    monitor = (await session.execute(stmt)).scalars().first()
    if not monitor:
        raise HTTPException(status_code=404, detail="Monitor niet gevonden")

    stmt_checks = (
        select(CheckResult)
        .where(CheckResult.monitor_id == monitor_id)
        .order_by(CheckResult.checked_at.desc())
        .limit(35)
    )
    recent_checks = list((await session.execute(stmt_checks)).scalars().all())

    return templates.TemplateResponse(
        request,
        "monitor_detail.html",
        {
            "request": request,
            "root_path": _get_prefix(request),
            "monitor": monitor,
            "recent_checks": recent_checks,
            "incidents": sorted(
                monitor.incidents, key=lambda i: i.started_at, reverse=True
            ),
        },
    )


@router.get("/api/summary")
async def api_summary(session: AsyncSession = Depends(get_db)) -> dict:
    ctx = await _fetch_dashboard_context(session)
    return {
        "total_monitors": ctx["total_monitors"],
        "up_count": ctx["up_count"],
        "degraded_count": ctx["degraded_count"],
        "down_count": ctx["down_count"],
        "mrr_eur": ctx["mrr_eur"],
        "uptime_pct": ctx["uptime_pct"],
    }


def _verify_access_code(request: Request, body_code: str = "") -> None:
    provided = (
        request.headers.get("X-Access-Code")
        or body_code
        or ""
    ).strip()
    expected = settings.provision_access_code.strip()
    if not expected or not provided or not secrets.compare_digest(provided, expected):
        raise HTTPException(
            status_code=401,
            detail="Ongeldig toegangsnummer (X-Access-Code) voor VPS provisioning.",
        )


@router.post("/api/provision")
async def api_provision_subscriber(
    payload: ProvisionSubscriberRequest,
    request: Request,
    background_tasks: BackgroundTasks,
    session: AsyncSession = Depends(get_db),
) -> dict:
    """
    Beveiligd endpoint waarmee de gehoste aanmeldpagina (philip-wintrip.com)
    met het geheime toegangsnummer automatisch een nieuw MKB-account + monitor aanmaakt op de VPS.
    """
    _verify_access_code(request, payload.access_code)

    tier_str = payload.plan_tier.lower().strip()
    tier_enum = (
        PlanTier(tier_str)
        if tier_str in {"basis", "pro", "compleet"}
        else PlanTier.PRO
    )

    normalized_url = payload.url.strip()
    if not normalized_url.startswith(("http://", "https://")):
        normalized_url = f"https://{normalized_url}"

    subscriber = Subscriber(
        company_name=payload.company_name.strip(),
        contact_email=payload.contact_email.strip(),
        plan_tier=tier_enum,
        is_active=(payload.payment_status != "canceled"),
        payment_status=payload.payment_status,
        mollie_customer_id=payload.mollie_customer_id,
        mollie_subscription_id=payload.mollie_subscription_id,
    )
    session.add(subscriber)
    await session.flush()

    if payload.contact_email.strip():
        session.add(
            NotificationChannel(
                subscriber_id=subscriber.id,
                channel_type=ChannelType.EMAIL,
                target=payload.contact_email.strip(),
            )
        )
    if payload.telegram_chat_id.strip():
        session.add(
            NotificationChannel(
                subscriber_id=subscriber.id,
                channel_type=ChannelType.TELEGRAM,
                target=payload.telegram_chat_id.strip(),
            )
        )
    if payload.whatsapp_number.strip():
        session.add(
            NotificationChannel(
                subscriber_id=subscriber.id,
                channel_type=ChannelType.WHATSAPP,
                target=payload.whatsapp_number.strip(),
            )
        )

    monitor = Monitor(
        subscriber_id=subscriber.id,
        name=payload.monitor_name.strip() or payload.company_name.strip(),
        url=normalized_url,
        expected_keywords=payload.expected_keywords.strip(),
        check_form_selector=(payload.check_form_selector or "").strip() or None,
        max_response_ms=payload.max_response_ms,
        ssl_warn_days=payload.ssl_warn_days,
    )
    session.add(monitor)
    await session.commit()

    if payload.run_initial_check:
        background_tasks.add_task(execute_single_monitor_check, monitor.id, True)

    return {
        "ok": True,
        "subscriber_id": subscriber.id,
        "subscriber_token": subscriber.access_token,
        "monitor_id": monitor.id,
        "company_name": subscriber.company_name,
        "url": monitor.url,
        "plan_tier": tier_enum.value,
        "monthly_price_eur": tier_enum.monthly_price_eur,
        "payment_status": subscriber.payment_status,
        "portal_url": f"https://philip-wintrip.nl/mkb-uptime-bot/klant.php?token={subscriber.access_token}",
    }


@router.post("/api/subscription-status")
async def api_update_subscription_status(
    payload: SubscriptionStatusUpdateRequest,
    request: Request,
    session: AsyncSession = Depends(get_db),
) -> dict:
    """
    Ontvangt maandelijkse incasso-updates (bijv. vanuit de Mollie webhook op philip-wintrip.com)
    en activeert of pauzeert het MKB-account op de VPS.
    """
    _verify_access_code(request, payload.access_code)

    stmt = select(Subscriber).options(selectinload(Subscriber.monitors))
    if payload.subscriber_token:
        stmt = stmt.where(Subscriber.access_token == payload.subscriber_token)
    elif payload.mollie_subscription_id:
        stmt = stmt.where(Subscriber.mollie_subscription_id == payload.mollie_subscription_id)
    elif payload.mollie_customer_id:
        stmt = stmt.where(Subscriber.mollie_customer_id == payload.mollie_customer_id)
    else:
        raise HTTPException(status_code=400, detail="Geen klant-identificatie opgegeven.")

    subscriber = (await session.execute(stmt)).scalars().first()
    if not subscriber:
        raise HTTPException(status_code=404, detail="Abonnee niet gevonden.")

    subscriber.payment_status = payload.payment_status
    subscriber.is_active = payload.is_active
    if payload.mollie_customer_id:
        subscriber.mollie_customer_id = payload.mollie_customer_id
    if payload.mollie_subscription_id:
        subscriber.mollie_subscription_id = payload.mollie_subscription_id

    for m in subscriber.monitors:
        m.is_paused = not payload.is_active

    await session.commit()
    return {
        "ok": True,
        "subscriber_id": subscriber.id,
        "subscriber_token": subscriber.access_token,
        "is_active": subscriber.is_active,
        "payment_status": subscriber.payment_status,
    }


@router.get("/portal/{subscriber_token}", response_class=HTMLResponse)
async def subscriber_portal_page(
    subscriber_token: str,
    request: Request,
    session: AsyncSession = Depends(get_db),
) -> HTMLResponse:
    """Persoonlijke statuspagina voor één geabonneerde MKB-klant."""
    stmt = (
        select(Subscriber)
        .options(
            selectinload(Subscriber.monitors).selectinload(Monitor.incidents),
            selectinload(Subscriber.channels),
        )
        .where(Subscriber.access_token == subscriber_token)
    )
    subscriber = (await session.execute(stmt)).scalars().first()
    if not subscriber:
        raise HTTPException(status_code=404, detail="Klantportaal niet gevonden.")

    monitor = subscriber.monitors[0] if subscriber.monitors else None
    recent_checks: list[CheckResult] = []
    if monitor:
        stmt_checks = (
            select(CheckResult)
            .where(CheckResult.monitor_id == monitor.id)
            .order_by(CheckResult.checked_at.desc())
            .limit(25)
        )
        recent_checks = list((await session.execute(stmt_checks)).scalars().all())

    return templates.TemplateResponse(
        request,
        "subscriber_portal.html",
        {
            "request": request,
            "subscriber": subscriber,
            "monitor": monitor,
            "recent_checks": recent_checks,
        },
    )
