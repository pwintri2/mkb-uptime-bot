from contextlib import asynccontextmanager
import logging
from fastapi import FastAPI
from fastapi.responses import HTMLResponse

from app.config import settings
from app.database import init_db
from app.services.scheduler import create_scheduler
from app.web.routes import router as web_router

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    scheduler = None
    if settings.enable_scheduler:
        scheduler = create_scheduler()
        scheduler.start()
        logger.info(
            "Achtergrond-scheduler gestart (snelle check elke %ss, diepe check elke %sm).",
            settings.fast_check_interval_seconds,
            settings.deep_check_interval_minutes,
        )
    try:
        yield
    finally:
        if scheduler is not None:
            scheduler.shutdown(wait=False)


app = FastAPI(
    title=settings.app_name,
    version="0.1.0",
    lifespan=lifespan,
)

app.include_router(web_router)


@app.get("/demo/mkb-contact", response_class=HTMLResponse)
async def demo_mkb_contact_page(broken_form: bool = False, missing_keyword: bool = False):
    """Ingebouwde MKB-voorbeeldpagina om de formulier- en kernwoordchecks lokaal te testen."""
    keyword_block = (
        ""
        if missing_keyword
        else "<p>Openingstijden: Ma-Za 08:00 - 17:30 · KvK 12345678 · Bestellen mogelijk</p>"
    )
    form_block = (
        "<div id='contact-form'><p>Formulier tijdelijk niet beschikbaar</p></div>"
        if broken_form
        else """
        <form id="contact-form" action="/demo/mkb-contact" method="post">
            <input type="text" name="naam" required placeholder="Uw naam" />
            <input type="email" name="email" required placeholder="uw@email.nl" />
            <textarea name="bericht" required placeholder="Uw vraag of bestelling"></textarea>
            <button type="submit">Verstuur Bericht</button>
        </form>
        """
    )
    return HTMLResponse(
        f"""
        <!DOCTYPE html>
        <html lang="nl">
        <head><title>Bakkerij Jansen - Contact</title></head>
        <body>
            <h1>Welkom bij Bakkerij Jansen</h1>
            {keyword_block}
            {form_block}
        </body>
        </html>
        """
    )
