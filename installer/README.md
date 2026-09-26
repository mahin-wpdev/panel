# JM Broadband one-command installer

Fresh Ubuntu/Debian installation:

    curl -fsSL https://raw.githubusercontent.com/mahin-wpdev/panel/next-release/installer/install.sh | sudo bash

Supported hosts: Ubuntu 22.04/24.04 or Debian 12 with root access, outbound HTTPS,
at least 1 CPU, 1 GB RAM and 5 GB free disk. Ports 80/tcp, 443/tcp,
1812/udp, 1813/udp and 3799/udp must be available.

Optional environment values: PANEL_HOST, PANEL_SITE_ADDRESS, PANEL_COMPANY_NAME,
PANEL_ADMIN_USER, PANEL_ADMIN_PASSWORD and RADIUS_CLIENT_NETWORK. For automatic
HTTPS, point a domain to the server and set PANEL_SITE_ADDRESS to that domain.

The installer creates MariaDB, FreeRADIUS, bundled WhatsApp, cron, backup and
Caddy services. Caddy and Apache deny direct web access to configuration,
installer, database, test, backup and `system/secure` paths while keeping the
normal panel/mobile endpoints available. Generated credentials are saved once to
/opt/jm-panel/install-credentials.txt with mode 600. On the first admin login,
the setup wizard collects company, public access, billing, network, WhatsApp
and mobile-release settings and then locks itself.

Operational commands:

    sudo /opt/jm-panel/installer/update.sh
    sudo /opt/jm-panel/installer/repair.sh
    sudo /opt/jm-panel/installer/backup.sh
    sudo /opt/jm-panel/installer/restore.sh backups/daily/<backup>.tar.gz
    sudo /opt/jm-panel/installer/uninstall.sh

Use uninstall.sh --purge-data only for intentional permanent removal of Docker
volumes and the application directory. Normal uninstall keeps data and backups.
The bundled WhatsApp service is managed inside the panel; it is not exposed as
a separate public dashboard. MikroTik changes should be reviewed in
Network -> MikroTik Onboarding before applying.
