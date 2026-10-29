from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_name: str = "MKB Uptime & Formulier Bot"
    database_url: str = "sqlite+aiosqlite:///./mkb_monitor.db"
    confirmation_threshold: int = 2
    fast_check_interval_seconds: int = 60
    deep_check_interval_minutes: int = 15
    enable_scheduler: bool = True
    enable_playwright: bool = True
    max_concurrent_checks: int = 50
    retention_days: int = 30

    # Telegram
    telegram_bot_token: str = ""

    # E-mail (Resend of SMTP)
    resend_api_key: str = ""
    email_from: str = "alerts@jouwmkbmonitor.nl"
    smtp_host: str = ""
    smtp_port: int = 587
    smtp_user: str = ""
    smtp_password: str = ""

    # WhatsApp (Twilio of generieke Cloud API webhook)
    twilio_account_sid: str = ""
    twilio_auth_token: str = ""
    twilio_whatsapp_from: str = "whatsapp:+14155238886"
    whatsapp_webhook_url: str = ""


settings = Settings()
