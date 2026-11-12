# syntax=docker/dockerfile:1

# Production image: nginx, PHP-FPM, queue worker and scheduler in one
# container, plus the built app for wall and phone. Built from the repo root:
#   docker compose -f deploy/compose.yaml build

# --- Frontend -----------------------------------------------------------------
# Static files, the same for every platform: built natively on the build
# machine, never under emulation, which made an arm64 build crawl.
FROM --platform=$BUILDPLATFORM node:24-alpine AS frontend

# Everything here is a build tool: the output is static files. A new advisory
# against one of them must not stop a rollout, so the audit only reports here;
# CI (tests.yml) fails on it on every push. The PHP stage stays strict.

# The repo's layout below /build: plugin layers are found relative to the
# frontend (plugin-layers.ts), next to the PHP of their package.
WORKDIR /build/apps/platform/frontend
RUN --mount=type=cache,id=fahoba-npm,target=/root/.npm \
    npm install --global pnpm@10

# Packages come from a store that outlives the build: the registry can be
# slow from the server, and every code change starts this step afresh — a
# full download each time took eight minutes. With the lockfile pinned,
# only what is missing from the store is fetched.
COPY apps/platform/frontend/ ./
# Which plugins bring a layer: composer.lock's packages, without the dev ones.
COPY apps/platform/composer.lock ../composer.lock
COPY apps/platform/plugins/ ../plugins/
# The .npmrc points the store at DDEV's cache volume; irrelevant here.
RUN --mount=type=cache,id=fahoba-pnpm,target=/pnpm-store \
    rm -f .npmrc \
 && pnpm install --frozen-lockfile --prefer-offline --store-dir /pnpm-store \
 && { pnpm audit --prod || true; } \
 && pnpm build

# --- Application --------------------------------------------------------------
FROM php:8.5-fpm-alpine AS app

# Set by CI (.github/workflows/image.yml); a local build keeps "dev".
ARG FAHOBA_REVISION=dev
ARG FAHOBA_RELEASE=dev
LABEL org.opencontainers.image.title="Fahoba" \
      org.opencontainers.image.description="Self-hosted family calendar for a wall tablet and the family's phones" \
      org.opencontainers.image.source="https://github.com/fahoba/fahoba" \
      org.opencontainers.image.licenses="PolyForm-Shield-1.0.0" \
      org.opencontainers.image.revision="$FAHOBA_REVISION" \
      org.opencontainers.image.version="$FAHOBA_RELEASE"

RUN apk add --no-cache nginx supervisor su-exec icu-libs libpng libjpeg-turbo libwebp freetype libzip postgresql-libs postgresql16-client \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers icu-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev libzip-dev postgresql-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl gd exif zip bcmath pcntl \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG FAHOBA_AUDIT=strict

WORKDIR /var/www/html

# Dependencies first: they change far less often than the code. The plugin API
# is a path package; the plugins are too, installed by none but present for
# FAHOBA_PLUGINS (install-plugins.sh), at this release's version.
COPY apps/platform/composer.json apps/platform/composer.lock ./
COPY apps/platform/plugins/ ./plugins/
COPY apps/platform/packages/ ./packages/
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
 && { composer audit --locked --no-dev --abandoned=report || [ "$FAHOBA_AUDIT" = warn ]; } \
 && cp composer.lock composer.built.lock

COPY apps/platform/ ./
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover \
 && php artisan filament:assets \
 && php artisan storage:link \
 && rm -rf frontend

# The official app; /var/www/app points at it unless FAHOBA_FRONTEND names
# another (install-frontend.sh, at every start).
COPY --from=frontend /build/apps/platform/frontend/packages/app/.output/public /var/www/fahoba-app
RUN ln -s /var/www/fahoba-app /var/www/app

COPY deploy/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/security-headers.conf /etc/nginx/security-headers.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-fahoba.ini
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-fahoba.conf
COPY deploy/supervisord.conf /etc/supervisord.conf
COPY deploy/entrypoint.sh /usr/local/bin/fahoba-entrypoint
COPY deploy/install-plugins.sh /usr/local/bin/fahoba-install-plugins
COPY deploy/install-frontend.sh /usr/local/bin/fahoba-install-frontend

RUN chmod +x /usr/local/bin/fahoba-entrypoint /usr/local/bin/fahoba-install-plugins /usr/local/bin/fahoba-install-frontend \
 && chown -R www-data:www-data storage bootstrap/cache

# Wall tablets reload when this changes (config app.version), so a new image
# reaches them without APP_VERSION being set by hand.
ENV FAHOBA_BUILD=$FAHOBA_REVISION

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
  CMD wget -qO- http://127.0.0.1:8080/up > /dev/null || exit 1

ENTRYPOINT ["fahoba-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
