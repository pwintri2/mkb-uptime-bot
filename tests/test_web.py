import httpx
import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from app.config import settings
from app.database import Base, get_db
from app.main import app


@pytest.mark.asyncio
async def test_dashboard_and_create_monitor(monkeypatch):
    monkeypatch.setattr(settings, "enable_scheduler", False)

    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

    session_factory = async_sessionmaker(engine, expire_on_commit=False)

    async def _override_get_db():
        async with session_factory() as session:
            yield session

    app.dependency_overrides[get_db] = _override_get_db

    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(transport=transport, base_url="http://testserver") as client:
        # 1. Dashboard opent leeg
        resp_home = await client.get("/")
        assert resp_home.status_code == 200
        assert "MKB Uptime & Formulier Bot" in resp_home.text

        # 2. Voeg nieuwe MKB-klant en monitor toe
        resp_post = await client.post(
            "/monitors",
            data={
                "company_name": "Fietsenmakerij Peters",
                "contact_email": "info@petersfietsen.nl",
                "plan_tier": "compleet",
                "monitor_name": "Contact & Reparatie",
                "url": "https://petersfietsen.nl/contact",
                "expected_keywords": "Reparatie, E-bike",
                "check_form_selector": "form#afspraak",
                "max_response_ms": "2000",
                "ssl_warn_days": "14",
                "telegram_chat_id": "55667788",
                "whatsapp_number": "+31699887766",
            },
            follow_redirects=True,
        )
        assert resp_post.status_code == 200
        assert "Fietsenmakerij Peters" in resp_post.text

        # 3. Controleer JSON samenvatting (MRR moet €7 zijn voor pakket 'compleet')
        resp_api = await client.get("/api/summary")
        assert resp_api.status_code == 200
        summary = resp_api.json()
        assert summary["total_monitors"] == 1
        assert summary["mrr_eur"] == 7

    app.dependency_overrides.clear()
    await engine.dispose()
