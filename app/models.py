from datetime import datetime, timezone
import enum
from sqlalchemy import (
    Boolean,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    String,
    Text,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class PlanTier(str, enum.Enum):
    BASIS = "basis"          # €3 / domein / mnd
    PRO = "pro"              # €5 / domein / mnd
    COMPLEET = "compleet"    # €7 / domein / mnd

    @property
    def monthly_price_eur(self) -> int:
        return {
            PlanTier.BASIS: 3,
            PlanTier.PRO: 5,
            PlanTier.COMPLEET: 7,
        }[self]


class MonitorStatus(str, enum.Enum):
    UP = "up"
    DEGRADED = "degraded"
    DOWN = "down"


class ChannelType(str, enum.Enum):
    TELEGRAM = "telegram"
    EMAIL = "email"
    WHATSAPP = "whatsapp"


class Subscriber(Base):
    __tablename__ = "subscribers"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    company_name: Mapped[str] = mapped_column(String(150), nullable=False)
    contact_email: Mapped[str] = mapped_column(String(200), nullable=False)
    plan_tier: Mapped[PlanTier] = mapped_column(
        Enum(PlanTier), default=PlanTier.PRO, nullable=False
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    monitors: Mapped[list["Monitor"]] = relationship(
        "Monitor", back_populates="subscriber", cascade="all, delete-orphan"
    )
    channels: Mapped[list["NotificationChannel"]] = relationship(
        "NotificationChannel", back_populates="subscriber", cascade="all, delete-orphan"
    )


class NotificationChannel(Base):
    __tablename__ = "notification_channels"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    subscriber_id: Mapped[int] = mapped_column(
        ForeignKey("subscribers.id", ondelete="CASCADE"), nullable=False
    )
    channel_type: Mapped[ChannelType] = mapped_column(Enum(ChannelType), nullable=False)
    target: Mapped[str] = mapped_column(String(255), nullable=False)
    is_enabled: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    subscriber: Mapped["Subscriber"] = relationship("Subscriber", back_populates="channels")


class Monitor(Base):
    __tablename__ = "monitors"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    subscriber_id: Mapped[int] = mapped_column(
        ForeignKey("subscribers.id", ondelete="CASCADE"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(150), nullable=False)
    url: Mapped[str] = mapped_column(String(500), nullable=False)
    expected_keywords: Mapped[str] = mapped_column(String(500), default="", nullable=False)
    check_form_selector: Mapped[str | None] = mapped_column(String(200), nullable=True)
    max_response_ms: Mapped[int] = mapped_column(Integer, default=2500, nullable=False)
    ssl_warn_days: Mapped[int] = mapped_column(Integer, default=14, nullable=False)
    is_paused: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)

    status: Mapped[MonitorStatus] = mapped_column(
        Enum(MonitorStatus), default=MonitorStatus.UP, nullable=False
    )
    consecutive_failures: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    consecutive_slow: Mapped[int] = mapped_column(Integer, default=0, nullable=False)

    last_checked_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    last_response_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    last_status_code: Mapped[int | None] = mapped_column(Integer, nullable=True)
    ssl_days_remaining: Mapped[int | None] = mapped_column(Integer, nullable=True)
    ssl_expires_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    form_status_ok: Mapped[bool | None] = mapped_column(Boolean, nullable=True)
    keywords_status_ok: Mapped[bool | None] = mapped_column(Boolean, nullable=True)
    last_error_reason: Mapped[str | None] = mapped_column(String(500), nullable=True)

    subscriber: Mapped["Subscriber"] = relationship("Subscriber", back_populates="monitors")
    check_results: Mapped[list["CheckResult"]] = relationship(
        "CheckResult", back_populates="monitor", cascade="all, delete-orphan"
    )
    incidents: Mapped[list["Incident"]] = relationship(
        "Incident", back_populates="monitor", cascade="all, delete-orphan"
    )

    @property
    def keyword_list(self) -> list[str]:
        if not self.expected_keywords:
            return []
        return [kw.strip() for kw in self.expected_keywords.split(",") if kw.strip()]


class CheckResult(Base):
    __tablename__ = "check_results"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    monitor_id: Mapped[int] = mapped_column(
        ForeignKey("monitors.id", ondelete="CASCADE"), nullable=False, index=True
    )
    checked_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, index=True
    )
    check_type: Mapped[str] = mapped_column(String(30), default="fast_http")
    is_up: Mapped[bool] = mapped_column(Boolean, nullable=False)
    is_degraded: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    status_code: Mapped[int | None] = mapped_column(Integer, nullable=True)
    response_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    keywords_ok: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    form_ok: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    ssl_days_left: Mapped[int | None] = mapped_column(Integer, nullable=True)
    error_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    error_detail: Mapped[str | None] = mapped_column(Text, nullable=True)

    monitor: Mapped["Monitor"] = relationship("Monitor", back_populates="check_results")


class Incident(Base):
    __tablename__ = "incidents"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    monitor_id: Mapped[int] = mapped_column(
        ForeignKey("monitors.id", ondelete="CASCADE"), nullable=False, index=True
    )
    started_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    resolved_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    severity: Mapped[MonitorStatus] = mapped_column(
        Enum(MonitorStatus), default=MonitorStatus.DOWN, nullable=False
    )
    reason_code: Mapped[str] = mapped_column(String(64), nullable=False)
    summary: Mapped[str] = mapped_column(String(500), nullable=False)
    notification_sent: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)

    monitor: Mapped["Monitor"] = relationship("Monitor", back_populates="incidents")

    @property
    def is_open(self) -> bool:
        return self.resolved_at is None
