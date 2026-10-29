from datetime import datetime
from pydantic import BaseModel, Field

from app.models import PlanTier


class CheckOutcome(BaseModel):
    check_type: str = "fast_http"
    is_up: bool = True
    is_degraded: bool = False
    status_code: int | None = None
    response_ms: int | None = None
    keywords_ok: bool = True
    missing_keywords: list[str] = Field(default_factory=list)
    form_ok: bool = True
    form_detail: str | None = None
    ssl_days_left: int | None = None
    ssl_expires_at: datetime | None = None
    error_code: str | None = None
    error_detail: str | None = None


class CreateMonitorForm(BaseModel):
    company_name: str
    contact_email: str
    plan_tier: PlanTier = PlanTier.PRO
    monitor_name: str
    url: str
    expected_keywords: str = ""
    check_form_selector: str | None = "form"
    max_response_ms: int = 2500
    ssl_warn_days: int = 14
    telegram_chat_id: str = ""
    whatsapp_number: str = ""
