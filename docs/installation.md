# Installing Fahoba on a server

*Deutsche Fassung: [`installation.de.md`](installation.de.md)*

One server runs Fahoba for one family or for several, strictly separated.
This guide takes you from an empty machine to a wall tablet showing the
calendar. Pairing the tablet itself is in [`kiosk.md`](kiosk.md).

- [What you need](#what-you-need)
- [Choose a setup](#choose-a-setup)
- [Install](#install)
- [First sign-in](#first-sign-in)
- [Optional parts](#optional-parts)
- [Updating](#updating)
- [Backup and restore](#backup-and-restore)
- [Troubleshooting](#troubleshooting)
- [Reference: settings](#reference-settings)

## What you need

- **A Linux server with Docker** (Engine 25 or newer, with the Compose
  plugin). amd64 or arm64 — a Raspberry Pi 4 or 5 will do. Plus disk space
  for photos.
- **A host name that points to the server**, e.g. `fahoba.example.org`.
  Fahoba needs HTTPS: the sign-in cookie is sent only over a secure
  connection, and push notifications and installing the app on a phone work
  only there. A plain `http://192.168.…` address is not enough.
- **Optional:** an SMTP account for invitations, password reset and sign-in
  links. Without one, Fahoba runs with mail switched off
  (`FAHOBA_MAIL=off`) and parents set passwords in the admin.

## Choose a setup

Both run the same image: nginx, PHP, the queue worker and the scheduler in
one container, PostgreSQL beside it.

| | **Standalone** (`compose.standalone.yaml`) | **Behind Traefik** (`compose.yaml`) |
|---|---|---|
| For | a home server, a NAS, a small VPS | a server that already runs Traefik |
| HTTPS | Caddy in the stack fetches the certificate, or a proxy you already run | Traefik's certificate resolver |
| Image | the published `ghcr.io/fahoba/fahoba` (or built from the source) | the same |
| Ports | 80 and 443 (or one port of your choice behind your own proxy) | none; Traefik reaches the container over its network |

If you are unsure, take **standalone**.

## Install

### 1. Get the files

```bash
git clone https://github.com/fahoba/fahoba.git /opt/fahoba
cd /opt/fahoba/deploy
cp .env.example .env
```

Everything below happens in `deploy/`. The checkout is needed for the
compose files, the Caddyfile and the scripts — and for building the image
yourself, if you want to.

### 2. Fill in `.env`

Open `.env`; each line is explained there. The required ones:

```bash
# Which setup
COMPOSE_FILE=compose.standalone.yaml      # or compose.yaml for Traefik

# Where Fahoba is reached
APP_URL=https://fahoba.example.org
FAHOBA_SITE=fahoba.example.org          # standalone
FAHOBA_HOST=fahoba.example.org          # Traefik

# Secrets: generate each once and keep them
APP_KEY=base64:…        # openssl rand -base64 32, prefixed with base64:
DB_PASSWORD=…           # openssl rand -hex 24
```

> **Write `APP_KEY` down somewhere safe.** It decrypts every stored
> credential (Google, Bring, CalDAV, Tandoor). A lost key does not lose the
> families' data, but every connection must be set up again.

**Behind a proxy you already run** (Nginx Proxy Manager, a router, another
Caddy): set `FAHOBA_SITE=:80` and `FAHOBA_HTTP_PORT=8080`, let your proxy
terminate HTTPS and forward to port 8080. It must pass
`X-Forwarded-Proto`; private addresses are trusted by default
(`FAHOBA_TRUSTED_PROXIES`).

### 3. Start

**Standalone:**

```bash
docker compose pull
docker compose up -d
```

To build from the checkout instead of pulling, set
`FAHOBA_IMAGE=fahoba-app` in `.env` and run `docker compose build` first.

**Behind Traefik:** Traefik must be on a Docker network named `web` (or the
one in `TRAEFIK_NETWORK`), with an entrypoint `websecure` and a certificate
resolver `letsencrypt` (or yours in `TRAEFIK_*`). Like standalone it pulls
`FAHOBA_IMAGE`; with `fahoba-app` run `docker compose build` first.

```bash
docker compose up -d
```

The first build takes several minutes. It stops if a PHP dependency has a
known vulnerability — `FAHOBA_AUDIT=warn` only reports it. Advisories for the
frontend's build tools are only reported; they never reach the server.

The database is migrated on every start. `docker compose ps` shows the app
as `healthy` once it answers; `https://fahoba.example.org/up` then says so
too.

### 4. Create the operator

The operator runs the server and belongs to no household:

```bash
docker compose exec app su-exec www-data php artisan fahoba:create-operator "Your name" you@example.org
```

The password is asked for in the terminal.

## First sign-in

1. Open `https://fahoba.example.org/admin` and sign in as the operator.
2. **Operations → All households → Create**: name, address (it sets the
   time zone, the weather and the position of the sun) and the first parent.
   The parent gets an invitation by mail — or, with mail off, a password
   right there.
3. The parent signs in at the same address, on a phone or in `/admin`, and
   adds the family under **Family → People**. A new household starts with
   sample routines, rewards and a shopping list.
4. **Devices → New device → Pairing code** pairs the wall tablet — see
   [`kiosk.md`](kiosk.md).

Under **Operations → All households** the operator can also suspend a
household (nobody gets in) or make it read-only (everything stays visible,
nothing can be changed, syncs pause) — and lift either again. **Delete**
removes a household with its files and every account that belongs to no
other household, after its name was typed; it cannot be undone. A parent can
also delete their own account (profile, or the app's settings); where nobody
else manages the household, it goes with it.

### Moving a household

**Setup → Export & move** gives a family everything as one ZIP and imports
another server's export as a new household next to the existing ones — in
either direction, also between two self-hosted servers. Connections are set
up again, tablets pair again. The admin upload takes up to 12 MB (Livewire's
limit); bigger exports by command:

```bash
docker compose exec app su-exec www-data php artisan fahoba:import-household /path/export.zip --new --importer=parent@example.org
```

`FAHOBA_HOSTED_URL` in `deploy/.env` adds one line on the export page
pointing at a hosted Fahoba; empty, it is not shown.

Passwords (connections such as Bring or CalDAV, webhooks, dashboard tiles)
are encrypted with `APP_KEY` and cannot be read on another server. For a
move the export can include them in plain text (“Passwörter im Klartext
mitnehmen”, a toggle with a confirmation; `fahoba:export-household
--with-secrets`); the import encrypts them with the new key. Google keeps
working only where the same Google client (`GOOGLE_CLIENT_ID`) is set up,
otherwise it asks to connect again. Treat such a file like a password list,
delete it after the move, and switch the old server's household off so
both do not sync at once.

## Optional parts

### Plugins

The image starts with none. Name the ones wanted in `deploy/.env`, separated
by spaces, and start again (`./deploy/rollout.sh`):

```bash
FAHOBA_PLUGINS="fahoba/caldav fahoba/two-factor fahoba/ics-feed"
```

The container installs them on every start, before it migrates. Fahoba's
own come from the image, at its version; any other comes from Packagist
(`vendor/name`, or `vendor/name:^1.2` to pin it). One that cannot be
installed stops the start, and the log says why — running on without, say,
two-factor would quietly drop it. A plugin from another repository needs
`FAHOBA_COMPOSER_REPOSITORIES` (space-separated `https://` VCS addresses);
a private one also `COMPOSER_AUTH`, e.g.
`{"github-oauth":{"github.com":"<token>"}}`. Removing a name removes the plugin at the
next start; its tables stay. The list of Fahoba's own is in the
[README](../README.md#plugins-and-interfaces); writing one:
[plugins.md](plugins.md).

### Designs and other frontends

A **design** (colours, font, corners) is a plugin like any other: add its
name to `FAHOBA_PLUGINS`, then pick it under Household → Light and dark theme; tablets and
accounts may keep Fahoba's own.

A **frontend** replaces the whole app at `/` — a community interface for
an e-ink wall, say. Name it in `deploy/.env` and start again:

```bash
FAHOBA_FRONTEND="acme/fahoba-frontend:^1.0"
```

It installs like a plugin; the admin, the API and the official app's
features on the server stay. A frontend whose built files the browser would
refuse (an inline script, a script from another host) stops the start with
the reason in the log. Empty brings back Fahoba's own app. Writing one:
[frontends.md](frontends.md).

### Realtime

Without it, wall and phones look for changes every five minutes. With
Reverb a ticked-off task appears everywhere at once:

```bash
# .env
BROADCAST_CONNECTION=reverb
REVERB_APP_KEY=…        # openssl rand -hex 16
REVERB_APP_SECRET=…     # openssl rand -hex 16
REVERB_HOST=fahoba.example.org
```

```bash
docker compose --profile realtime up -d
```

Reverb needs no port of its own; the app container passes `/app` on to it.

### Push notifications

```bash
docker compose run --rm --no-deps app php artisan fahoba:vapid-keys
```

Copy both lines into `.env`, set `VAPID_SUBJECT` to a mail address of
yours and restart (`docker compose up -d`). Keep the keys: new ones cancel
every phone's subscription.

### Mail

Fill in the `MAIL_*` lines (SMTP). Mail is sent by the queue worker in the
app container, so it needs no extra service. `FAHOBA_MAIL=off` switches
mail off entirely.

### Google Calendar and Google Tasks

A Google Cloud project with the Calendar API (and the Tasks API, if wanted)
and an OAuth client of type *web application*. Redirect URIs:

```
https://fahoba.example.org/auth/calendar/google/callback
https://fahoba.example.org/auth/tasks/google/callback
```

Enter `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`; parents
connect under **Connections** in the admin. Signing in with Google is a
separate project — see the [README](../README.md#signing-in-with-google).

### Photo folders

Besides uploads, the photo frame can read a folder on the server, one
subfolder per household id:

```
./photos/1/      # household 1
./photos/2/
```

Set `FAHOBA_PHOTO_FOLDERS_HOST` if the folders live elsewhere. Fahoba only
reads them.

### Restricting the admin

`FAHOBA_ADMIN_ALLOWED_IPS=100.64.0.0/10` (for example your tailnet) keeps
`/admin` away from the internet; wall and phones keep working from
anywhere.

## Updating

```bash
cd /opt/fahoba && git pull && ./deploy/rollout.sh
```

`rollout.sh` pulls the new image (or builds it), starts it beside the
running one, waits until it is healthy and only then removes the old one —
the families notice nothing. Wall tablets reload themselves once the new
version answers.

Read the [changelog](../CHANGELOG.md) before a major version: it names what
a running installation must know.

### From Tagwerk to Fahoba

The project was called Tagwerk until 0.3.0. An existing server updates as
above; tablets and phones stay signed in, and the database is carried over.

- In `deploy/.env`, rename `TAGWERK_HOST` (Traefik) or `TAGWERK_SITE`
  (Caddy) to `FAHOBA_HOST`/`FAHOBA_SITE` — the start stops without it.
  Other `TAGWERK_*` keep working for now; rename them when convenient.
- Set `APP_NAME="Family Home Base"`, otherwise mails and the browser tab keep the old
  name.
- With `FAHOBA_IMAGE`/`TAGWERK_IMAGE` set to the old image address, point it
  at `ghcr.io/fahoba/fahoba`.
- The Compose project stays `tagwerk` (volumes, network); do not rename it.
- A checkout in `/opt/tagwerk` may stay there; `pull-household.sh` then
  needs that folder as its third argument.

## Backup and restore

```bash
./deploy/backup.sh /srv/backup/fahoba     # e.g. nightly from cron
```

It writes the database (`db_<date>.dump`) and all uploads
(`storage_<date>.tar.gz`) and keeps 14 days (`KEEP_DAYS`). Copy them off
the server, and keep `.env` separately: without its `APP_KEY` the stored
credentials cannot be read. The extension `fahoba/webdav-backup` (in the
admin under **Operations → Extensions**) uploads a nightly backup to a
WebDAV folder instead.

**Restore** onto a fresh installation with the same `.env`:

```bash
docker compose up -d db
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < db_<date>.dump
docker compose up -d app
docker compose exec -T app tar -C /var/www/html/storage/app -xzf - < storage_<date>.tar.gz
```

## Troubleshooting

**Start with the logs:** `docker compose logs app --tail 100`. Laravel logs
to the container's output.

| What you see | Why, and what helps |
|---|---|
| `FAHOBA_SITE missing in .env` / `FAHOBA_HOST missing in .env` | The setup chosen in `COMPOSE_FILE` needs its host name. |
| `network web declared as external, but could not be found` | `compose.yaml` expects Traefik. Use the standalone file, or set `TRAEFIK_NETWORK`. |
| Caddy cannot get a certificate | Ports 80 and 443 must be reachable from the internet and the name must point to this server. Behind your own proxy use `FAHOBA_SITE=:80`. |
| Sign-in on the phone does nothing | The address is not HTTPS, or your proxy does not pass `X-Forwarded-Proto`. |
| `No application encryption key has been specified` | `APP_KEY` is empty — see step 2. |
| 500 after an update, “column … does not exist” | The migration failed; `docker compose logs app` names the cause. A restart retries it. |
| The build stops at `composer audit` | A PHP dependency has a known vulnerability. Update Fahoba; if no fix exists yet, `FAHOBA_AUDIT=warn` builds anyway. |
| The wall does not update by itself | Without Reverb it looks every five minutes. With Reverb, check `BROADCAST_CONNECTION=reverb` and that the `reverb` container runs. |
| No push on the phone | VAPID keys missing or changed; the app must be installed on iOS (Add to Home Screen). |
| Is the queue worker running? | `docker compose exec app ps aux \| grep queue:work` |

## Reference: settings

Every setting is explained in [`deploy/.env.example`](../deploy/.env.example).
The ones beyond this guide:

| Setting | Default | Meaning |
|---|---|---|
| `FAHOBA_CSP` | `report-only` | Content Security Policy: `report-only`, `enforce` once the log stays quiet, or `off` |
| `FAHOBA_TRUSTED_PROXIES` | private ranges | addresses allowed to set `X-Forwarded-For` |
| `FAHOBA_AUDIT` | `strict` | a vulnerable PHP dependency stops the build; `warn` only reports |
| `FAHOBA_PLUGINS` | empty | plugins installed at start, space-separated (see [Plugins](#plugins)) |
| `FAHOBA_FRONTEND` | empty | a frontend package in place of the official app (see [Designs and other frontends](#designs-and-other-frontends)) |
| `APP_VERSION` | the image's commit | wall tablets reload when it changes |
| `APP_LOCALE` | `de` | language where neither household, account nor tablet chose one |
| `LOG_LEVEL` | `warning` | `info` or `debug` while looking for a problem |
