FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends freeradius freeradius-mysql freeradius-utils gettext-base mariadb-client && rm -rf /var/lib/apt/lists/*
COPY entrypoint.sh /entrypoint.sh
COPY sql.template /etc/freeradius/3.0/mods-available/sql.jm-template
RUN chmod +x /entrypoint.sh
EXPOSE 1812/udp 1813/udp 3799/udp
ENTRYPOINT ["/entrypoint.sh"]
