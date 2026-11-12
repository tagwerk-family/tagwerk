#!/usr/bin/env bash
# Copies one household from the server into a local database of its own, to
# look at what a family really has. Run from a development checkout:
#
#   deploy/pull-household.sh <ssh-host> <household-id> [deploy-dir on the server]
#
# The server needs a release with `fahoba:export-household`. Access tokens,
# push subscriptions and Google channels are left out, so the copy cannot act
# on the family's accounts or devices; photo files stay on the server.
#
# Result: apps/platform/storage/app/household-<id>.json.gz (ignored by Git)
# and the database fahoba_household_<id>. Both hold a family's data —
# delete them once done:
#   rm apps/platform/storage/app/household-<id>.json.gz
#   ddev exec 'dropdb -h db -U db fahoba_household_<id>'
set -euo pipefail

if [ $# -lt 2 ] || ! [[ $2 =~ ^[0-9]+$ ]]; then
  echo "Usage: $0 <ssh-host> <household-id> [deploy-dir, default /opt/fahoba/deploy]" >&2
  exit 1
fi

host=$1
id=$2
dir=${3:-/opt/fahoba/deploy}
db=fahoba_household_$id
file=storage/app/household-$id.json.gz

cd "$(dirname "$0")/.."

# Streamed through ssh: no copy of the family's data stays on the server.
ssh "$host" "cd '$dir' && docker compose exec -T app su-exec www-data php artisan fahoba:export-household $id" \
  | gzip > "apps/platform/$file"

# A database of its own: the development database stays as it is.
ddev exec "dropdb --if-exists -h db -U db $db && createdb -h db -U db $db"
ddev exec "cd apps/platform && DB_DATABASE=$db php artisan migrate --force --no-interaction >/dev/null && DB_DATABASE=$db php artisan fahoba:import-household $file"

echo "Household $id is in the database $db."
echo "Look at it:  ddev exec 'psql -h db -U db -d $db'"
