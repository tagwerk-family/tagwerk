#!/bin/sh
# Prepares the mounted storage volume and the caches, then starts the command.
# Only the main container (supervisord) migrates — the Reverb container uses
# the same image and must not race it.
set -eu

cd /var/www/html

# Renamed from Tagwerk: a deploy/.env from before still says TAGWERK_*. Each
# one stands in for its FAHOBA_* twin unless that is set.
for name in $(env | sed -n 's/^TAGWERK_\([A-Z0-9_]*\)=.*/\1/p'); do
  eval "[ -n \"\${FAHOBA_$name+x}\" ] || export FAHOBA_$name=\"\$TAGWERK_$name\""
  echo "TAGWERK_$name is deprecated, rename it to FAHOBA_$name in deploy/.env." >&2
done

mkdir -p storage/app/public storage/app/photos storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs
# Only what is not www-data's yet: a recursive chown rewrote every uploaded
# photo on each start and grew with the collection — downtime per deploy.
find storage bootstrap/cache ! -user www-data -exec chown www-data:www-data {} +

# Plugins before migrating: their tables come with them. Only the main
# container needs them; Reverb only relays what the app announces.
if [ "${1:-}" = "supervisord" ]; then
  fahoba-install-plugins
fi

# Migrate before caching: a plugin that reads its new table while booting
# would fail during optimize, and the route cache would keep it without
# routes until the next restart.
if [ "${1:-}" = "supervisord" ]; then
  su-exec www-data php artisan migrate --force
fi

# Cached from the container's environment; a changed .env needs a restart.
su-exec www-data php artisan optimize

if [ "${1:-}" = "supervisord" ]; then
  # The app's Content Security Policy, plugins' hosts included, for nginx.
  su-exec www-data php artisan fahoba:csp > /etc/nginx/csp.conf

  # The official app, or the frontend FAHOBA_FRONTEND names.
  fahoba-install-frontend
fi

exec "$@"
