# Arivo ISP Billing WhatsApp Service

**Maintainer:** Mustafizur Rahman Mahin
**Project:** https://github.com/mahin-wpdev/panel

This service is the bundled WhatsApp gateway used by Arivo ISP Billing. It runs inside the main Docker Compose stack and provides authenticated status, QR pairing, messaging, reconnect and logout operations to the panel.

## Recommended operation

Manage the service from the Arivo ISP Billing root deployment:

```bash
cd /opt/jm-panel
docker compose up -d whatsapp
docker compose logs -f whatsapp
```

Use the Arivo panel's WhatsApp administration screen for QR pairing, status, reconnect, logout and test messages. Do not expose the standalone service UI publicly.

## Development

```bash
npm install
npm test
npm start
```

The service expects its API key, storage paths and runtime settings from the Arivo deployment environment.

## Source attribution

This bundled service contains code derived from the Baileys API work by Mohammad Rameez Imdad (Rameez Scripts). Original third-party copyright and license notices remain applicable. Arivo-specific integration, deployment, security controls and maintenance are by Mustafizur Rahman Mahin.
