# JM Broadband one-command installer

Fresh Ubuntu installation command:

    curl -fsSL https://raw.githubusercontent.com/mahin-wpdev/panel/next-release/installer/install.sh | sudo bash

Optional values can be supplied with PANEL_HOST, PANEL_SITE_ADDRESS,
PANEL_COMPANY_NAME, PANEL_ADMIN_USER, PANEL_ADMIN_PASSWORD and
RADIUS_CLIENT_NETWORK.

The installer generates database, RADIUS and WhatsApp secrets, installs an
empty customer database, and starts the panel, FreeRADIUS and bundled WhatsApp
service. Initial login is saved once at
/opt/jm-panel/install-credentials.txt with mode 600.
