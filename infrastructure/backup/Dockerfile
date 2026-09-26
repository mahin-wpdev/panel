FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client sqlite3 ca-certificates tar gzip coreutils findutils && rm -rf /var/lib/apt/lists/*
COPY backup.sh /usr/local/bin/jm-backup
RUN chmod +x /usr/local/bin/jm-backup
ENTRYPOINT ["/usr/local/bin/jm-backup"]
CMD ["daemon"]
