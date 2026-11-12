#!/bin/sh
# Nightly backup of database and uploads, run on the host, e.g. by cron:
#   17 3 * * * /opt/fahoba/deploy/backup.sh /srv/backup/fahoba
#
# Keeps KEEP_DAYS days (default 14). Copy the target off this server — a
# backup on the same disk survives a mistake, not a broken disk. The .env is
# needed for a restore too (APP_KEY), but holds secrets: store it separately.
set -eu

cd "$(dirname "$0")"

target=${1:-./backups}
keep=${KEEP_DAYS:-14}
stamp=$(date +%Y-%m-%d_%H%M)

mkdir -p "$target"

docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$target/db_$stamp.dump"
docker compose exec -T app tar -C /var/www/html/storage/app -czf - . > "$target/storage_$stamp.tar.gz"

find "$target" -name 'db_*.dump' -mtime +"$keep" -delete
find "$target" -name 'storage_*.tar.gz' -mtime +"$keep" -delete

echo "Backup written to $target ($stamp)"
