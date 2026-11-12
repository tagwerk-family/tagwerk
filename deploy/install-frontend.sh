#!/bin/sh
# Points /var/www/app — what nginx serves at / — at the frontend to show:
#
#   FAHOBA_FRONTEND="acme/fahoba-kiosk"        a package, installed with the
#   FAHOBA_FRONTEND="acme/fahoba-kiosk:^1.0"   plugins (install-plugins.sh)
#
# Empty means Fahoba's own app. The package names its built files in
# composer.json (`extra.fahoba.app`, a directory with index.html and
# assets/); `fahoba:frontend` checks them. A frontend that cannot be served
# stops the start: a blank page on every wall is worse than no new container,
# and rollout.sh keeps the old one running then.
set -eu

name="${FAHOBA_FRONTEND:-}"
name="${name%%:*}"

if [ -z "$name" ]; then
  ln -sfn /var/www/fahoba-app /var/www/app
  exit 0
fi

if ! directory="$(su-exec www-data php artisan fahoba:frontend "$name")"; then
  echo "FAHOBA_FRONTEND: $directory" >&2
  exit 1
fi

echo "Serving the frontend $name from $directory"
ln -sfn "$directory" /var/www/app
