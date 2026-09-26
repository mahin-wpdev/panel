# Arivo ISP Billing WhatsApp Service — Installation

**Maintainer:** Mustafizur Rahman Mahin
**Project:** https://github.com/mahin-wpdev/panel

The supported installation method is the Arivo ISP Billing root installer. It provisions the WhatsApp service together with the panel, database, FreeRADIUS, Caddy, cron and backup services.

```bash
curl -fsSL https://raw.githubusercontent.com/mahin-wpdev/panel/next-release/installer/install.sh | sudo bash
```

After installation:

```bash
cd /opt/jm-panel
docker compose ps whatsapp
docker compose logs --tail=100 whatsapp
```

Pair WhatsApp from the Arivo panel administration UI. The service's standalone web interface is not intended for public exposure.

## Source attribution

The bundled service contains code derived from the Baileys API project by Mohammad Rameez Imdad (Rameez Scripts). Original third-party notices remain applicable. Arivo-specific integration and maintenance are by Mustafizur Rahman Mahin.
