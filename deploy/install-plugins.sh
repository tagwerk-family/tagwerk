#!/bin/sh
# Installs the plugins named in FAHOBA_PLUGINS, space-separated:
#
#   FAHOBA_PLUGINS="fahoba/caldav fahoba/two-factor acme/waste:^1.2"
#
# The image ships no plugin enabled. Fahoba's own (fahoba/*) come from the
# sources in the image, at exactly this release's version, and their
# dependencies at the versions composer.lock pins. Anything else comes from
# Packagist, or from a repository named in FAHOBA_COMPOSER_REPOSITORIES
# (space-separated VCS addresses; a private one needs COMPOSER_AUTH):
#
#   FAHOBA_COMPOSER_REPOSITORIES="https://github.com/acme/fahoba-waste.git"
#   COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'
#
# A plugin that cannot be installed stops the start. Running on without it
# would quietly drop what it does — two-factor sign-in, for one.
set -eu

# A community frontend (FAHOBA_FRONTEND, see install-frontend.sh) is a
# package like a plugin and installs with them.
# Fahoba's own plugins were tagwerk/* before the rename.
plugins="$(printf '%s %s' "${FAHOBA_PLUGINS:-}" "${FAHOBA_FRONTEND:-}" | xargs -n1 | sed 's#^tagwerk/#fahoba/#' | xargs)"
[ -n "$plugins" ] || exit 0

for plugin in $plugins; do
  if ! printf '%s\n' "$plugin" | grep -Eq '^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?/[a-z0-9]([a-z0-9_.-]*[a-z0-9])?(:[0-9A-Za-z.*^~|<>=@_-]+)?$'; then
    echo "FAHOBA_PLUGINS: \"$plugin\" is no package name (vendor/name or vendor/name:constraint)." >&2
    exit 1
  fi
done

cd /var/www/html
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/var/www/html/storage/framework/composer

index=0
for repository in ${FAHOBA_COMPOSER_REPOSITORIES:-}; do
  if ! printf '%s\n' "$repository" | grep -Eq '^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/~-]+$'; then
    echo "FAHOBA_COMPOSER_REPOSITORIES: \"$repository\" is no https address." >&2
    exit 1
  fi
  index=$((index + 1))
  composer config --no-interaction "repositories.fahoba-extra-$index" vcs "$repository"
done

echo "Installing plugins: $plugins"
# shellcheck disable=SC2086 # one argument per plugin
composer require --no-interaction --no-progress --no-scripts --update-no-dev --optimize-autoloader $plugins

# A plugin with its own screens is built into the app; that needs an image
# of its own (see docs/plugins.md), a running container cannot do it.
# composer.built.lock is the lock the image's app was built from.
php -r '
$built = array_column(json_decode(file_get_contents("composer.built.lock"), true)["packages"] ?? [], "name");
$installed = json_decode(file_get_contents("vendor/composer/installed.json"), true)["packages"] ?? [];
$late = array_filter($installed, fn (array $p): bool => isset($p["extra"]["fahoba"]["frontend"]) && ! in_array($p["name"], $built, true));
if ($late !== []) {
    fwrite(STDERR, "These plugins bring screens and need an image built with them: ".implode(", ", array_column($late, "name")).PHP_EOL);
    exit(1);
}
'

su-exec www-data php artisan package:discover
