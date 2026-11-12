# Fahoba auf einem Server installieren

*English version: [`installation.md`](installation.md)*

Ein Server betreibt Fahoba für eine Familie oder für mehrere, streng
getrennt. Diese Anleitung führt von einem leeren Rechner bis zum
Wandtablet, das den Kalender zeigt. Das Koppeln des Tablets selbst steht in
[`kiosk.de.md`](kiosk.de.md).

- [Was du brauchst](#was-du-brauchst)
- [Aufbau wählen](#aufbau-wählen)
- [Installieren](#installieren)
- [Erste Anmeldung](#erste-anmeldung)
- [Optionale Teile](#optionale-teile)
- [Aktualisieren](#aktualisieren)
- [Sicherung und Wiederherstellung](#sicherung-und-wiederherstellung)
- [Fehlersuche](#fehlersuche)
- [Nachschlagen: Einstellungen](#nachschlagen-einstellungen)

## Was du brauchst

- **Einen Linux-Server mit Docker** (Engine 25 oder neuer, mit dem
  Compose-Plugin). amd64 oder arm64 — auch ein Raspberry Pi 4 oder 5. Dazu
  Platz auf der Platte für Fotos.
- **Einen Hostnamen, der auf den Server zeigt**, etwa
  `fahoba.example.org`. Fahoba braucht HTTPS: Das Anmelde-Cookie geht nur
  über eine sichere Verbindung, und Push-Benachrichtigungen sowie das
  Installieren der App am Handy funktionieren nur dort. Eine einfache
  `http://192.168.…`-Adresse reicht nicht.
- **Optional:** ein SMTP-Konto für Einladungen, Passwort-Zurücksetzen und
  Anmeldelinks. Ohne läuft Fahoba mit abgeschalteter Mail
  (`FAHOBA_MAIL=off`), und Eltern vergeben Passwörter in der Verwaltung.

## Aufbau wählen

Beide verwenden dasselbe Image: nginx, PHP, Queue-Worker und Scheduler in
einem Container, PostgreSQL daneben.

| | **Eigenständig** (`compose.standalone.yaml`) | **Hinter Traefik** (`compose.yaml`) |
|---|---|---|
| Für | Heimserver, NAS, kleiner VPS | einen Server, auf dem Traefik schon läuft |
| HTTPS | Caddy im Stack holt das Zertifikat, oder ein Proxy, den du schon betreibst | Zertifikats-Resolver von Traefik |
| Image | das veröffentlichte `ghcr.io/fahoba/fahoba` (oder aus dem Quellcode gebaut) | dasselbe |
| Ports | 80 und 443 (oder ein beliebiger Port hinter deinem eigenen Proxy) | keine; Traefik erreicht den Container über sein Netzwerk |

Im Zweifel: **eigenständig**.

## Installieren

### 1. Dateien holen

```bash
git clone https://github.com/fahoba/fahoba.git /opt/fahoba
cd /opt/fahoba/deploy
cp .env.example .env
```

Alles Weitere passiert in `deploy/`. Der Checkout liefert die
Compose-Dateien, die Caddyfile und die Skripte — und den Quellcode, falls
du das Image selbst bauen willst.

### 2. `.env` ausfüllen

Öffne `.env`; jede Zeile ist dort erklärt. Die notwendigen:

```bash
# Welcher Aufbau
COMPOSE_FILE=compose.standalone.yaml      # oder compose.yaml für Traefik

# Wo Fahoba erreichbar ist
APP_URL=https://fahoba.example.org
FAHOBA_SITE=fahoba.example.org          # eigenständig
FAHOBA_HOST=fahoba.example.org          # Traefik

# Geheimnisse: einmal erzeugen und aufheben
APP_KEY=base64:…        # openssl rand -base64 32, mit base64: davor
DB_PASSWORD=…           # openssl rand -hex 24
```

> **Bewahre `APP_KEY` sicher auf.** Er entschlüsselt alle gespeicherten
> Zugangsdaten (Google, Bring, CalDAV, Tandoor). Ein verlorener Schlüssel
> kostet keine Familiendaten, aber jede Verbindung muss neu eingerichtet
> werden.

**Hinter einem Proxy, den du schon betreibst** (Nginx Proxy Manager, Router,
ein anderer Caddy): `FAHOBA_SITE=:80` und `FAHOBA_HTTP_PORT=8080` setzen,
den Proxy HTTPS machen lassen und auf Port 8080 weiterleiten. Er muss
`X-Forwarded-Proto` mitschicken; private Adressen gelten standardmäßig als
vertrauenswürdig (`FAHOBA_TRUSTED_PROXIES`).

### 3. Starten

**Eigenständig:**

```bash
docker compose pull
docker compose up -d
```

Um statt zu laden aus dem Checkout zu bauen, `FAHOBA_IMAGE=fahoba-app` in
`.env` setzen und vorher `docker compose build` ausführen.

**Hinter Traefik:** Traefik muss an einem Docker-Netzwerk namens `web`
hängen (oder dem aus `TRAEFIK_NETWORK`), mit dem Entrypoint `websecure` und
dem Zertifikats-Resolver `letsencrypt` (oder deinen in `TRAEFIK_*`). Wie
beim eigenständigen Aufbau wird `FAHOBA_IMAGE` geladen; mit `fahoba-app`
vorher `docker compose build` ausführen.

```bash
docker compose up -d
```

Der erste Build dauert einige Minuten. Er bricht ab, wenn eine PHP-Abhängigkeit
eine bekannte Sicherheitslücke hat — `FAHOBA_AUDIT=warn` meldet sie nur.
Lücken in den Build-Werkzeugen des Frontends werden nur gemeldet; sie landen
nie auf dem Server.

Die Datenbank wird bei jedem Start migriert. `docker compose ps` zeigt die
App als `healthy`, sobald sie antwortet; `https://fahoba.example.org/up`
sagt es dann auch.

### 4. Betreiber anlegen

Der Betreiber führt den Server und gehört zu keinem Haushalt:

```bash
docker compose exec app su-exec www-data php artisan fahoba:create-operator "Dein Name" du@example.org
```

Das Passwort wird im Terminal abgefragt.

## Erste Anmeldung

1. `https://fahoba.example.org/admin` öffnen und als Betreiber anmelden.
2. **Betrieb → Alle Haushalte → Erstellen**: Name, Adresse (sie bestimmt
   Zeitzone, Wetter und Sonnenstand) und der erste Elternteil. Er bekommt
   eine Einladung per Mail — oder, ohne Mail, gleich dort ein Passwort.
3. Der Elternteil meldet sich unter derselben Adresse an, am Handy oder in
   `/admin`, und legt unter **Familie → Personen** die Familie an. Ein neuer
   Haushalt startet mit Beispiel-Routinen, Belohnungen und einer
   Einkaufsliste.
4. **Geräte → Erstellen → Kopplungscode** koppelt das Wandtablet — siehe
   [`kiosk.de.md`](kiosk.de.md).

Unter **Betrieb → Alle Haushalte** kann der Betreiber einen Haushalt auch
sperren (niemand kommt mehr hinein) oder schreibschützen (alles bleibt
sichtbar, ändern lässt sich nichts, Synchronisationen pausieren) — und
beides wieder aufheben. **Löschen** entfernt einen Haushalt samt Dateien und
allen Konten, die zu keinem anderen Haushalt gehören, nachdem sein Name
eingetippt wurde; das lässt sich nicht rückgängig machen. Ein Elternteil
kann auch das eigene Konto löschen (Profil oder Einstellungen in der App);
verwaltet sonst niemand den Haushalt, geht er mit.

### Einen Haushalt umziehen

**Einrichtung → Export & Umzug** gibt einer Familie alles als eine ZIP-Datei
und importiert den Export eines anderen Servers als neuen Haushalt neben den
bestehenden — in jede Richtung, auch zwischen zwei eigenen Servern.
Verbindungen werden neu eingerichtet, Tablets neu gekoppelt. Der Upload im
Admin nimmt bis 12 MB (Grenze von Livewire); größere Exporte per Befehl:

```bash
docker compose exec app su-exec www-data php artisan fahoba:import-household /pfad/export.zip --new --importer=eltern@example.org
```

`FAHOBA_HOSTED_URL` in `deploy/.env` zeigt auf der Exportseite eine Zeile
zu einem gehosteten Fahoba; leer erscheint sie nicht.

Passwörter (Verbindungen wie Bring oder CalDAV, Webhooks, Dashboard-Kacheln)
sind mit `APP_KEY` verschlüsselt und auf einem anderen Server nicht lesbar.
Für einen Umzug kann der Export sie im Klartext mitnehmen („Passwörter im
Klartext mitnehmen“, Schalter mit Bestätigung;
`fahoba:export-household --with-secrets`); der Import verschlüsselt sie mit
dem neuen Schlüssel. Google funktioniert nur weiter, wenn derselbe
Google-Client (`GOOGLE_CLIENT_ID`) eingerichtet ist, sonst bittet es um eine
neue Verbindung. Behandle so eine Datei wie eine Passwortliste, lösch sie
nach dem Umzug und sperr den Haushalt am alten Server, damit nicht beide
gleichzeitig synchronisieren.

## Optionale Teile

### Plugins

Das Image startet ohne. Trag die gewünschten in `deploy/.env` ein, durch
Leerzeichen getrennt, und starte neu (`./deploy/rollout.sh`):

```bash
FAHOBA_PLUGINS="fahoba/caldav fahoba/two-factor fahoba/ics-feed"
```

Der Container installiert sie bei jedem Start, vor dem Migrieren. Die von
Fahoba kommen aus dem Image, in seiner Version; alle anderen von Packagist
(`vendor/name`, oder `vendor/name:^1.2` für eine feste Version). Lässt sich
eines nicht installieren, bricht der Start ab, und das Log sagt warum —
ohne etwa die Zwei-Faktor-Anmeldung einfach weiterzulaufen, würde sie
stillschweigend wegfallen. Ein Plugin aus einem anderen Repository braucht
`FAHOBA_COMPOSER_REPOSITORIES` (durch Leerzeichen getrennte
`https://`-VCS-Adressen); ein privates zusätzlich `COMPOSER_AUTH`, etwa
`{"github-oauth":{"github.com":"<token>"}}`. Ein entfernter Name entfernt das Plugin beim
nächsten Start; seine Tabellen bleiben. Die Liste der Fahoba-eigenen steht
im [README](../README.de.md#plugins-und-schnittstellen); ein eigenes
schreiben: [plugins.md](plugins.md) (Englisch).

### Designs und andere Oberflächen

Ein **Design** (Farben, Schrift, Ecken) ist ein Plugin wie jedes andere:
Namen in `FAHOBA_PLUGINS` eintragen, dann
auswählen (Haushalt → Helles und dunkles Thema); Tablets und Konten können beim Fahoba-eigenen bleiben.

Eine **Oberfläche** ersetzt die ganze App unter `/` — etwa eine aus der
Community für eine E-Ink-Wand. Trag sie in `deploy/.env` ein und starte neu:

```bash
FAHOBA_FRONTEND="acme/fahoba-frontend:^1.0"
```

Sie wird wie ein Plugin installiert; Verwaltung, API und alles auf dem
Server bleiben. Eine Oberfläche, deren gebaute Dateien der Browser
ablehnen würde (ein Skript direkt in der Seite, ein Skript von einem
anderen Host), bricht den Start ab, und das Log sagt warum. Leer bringt die
Fahoba-eigene App zurück. Eine eigene schreiben:
[frontends.md](frontends.md) (Englisch).

### Echtzeit

Ohne sie schauen Wand und Handys alle fünf Minuten nach Änderungen. Mit
Reverb erscheint eine abgehakte Aufgabe sofort überall:

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

Reverb braucht keinen eigenen Port; der App-Container reicht `/app` an ihn
weiter.

### Push-Benachrichtigungen

```bash
docker compose run --rm --no-deps app php artisan fahoba:vapid-keys
```

Beide Zeilen in `.env` übernehmen, `VAPID_SUBJECT` auf eine eigene
Mailadresse setzen und neu starten (`docker compose up -d`). Die Schlüssel
aufheben: Neue beenden das Abo jedes Handys.

### Mail

Die `MAIL_*`-Zeilen ausfüllen (SMTP). Verschickt wird vom Queue-Worker im
App-Container, ein weiterer Dienst ist nicht nötig. `FAHOBA_MAIL=off`
schaltet Mail ganz ab.

### Google Kalender und Google Tasks

Ein Google-Cloud-Projekt mit der Calendar API (und der Tasks API, wenn
gewünscht) und einem OAuth-Client vom Typ *Webanwendung*.
Weiterleitungs-URIs:

```
https://fahoba.example.org/auth/calendar/google/callback
https://fahoba.example.org/auth/tasks/google/callback
```

`GOOGLE_CLIENT_ID` und `GOOGLE_CLIENT_SECRET` in `.env` eintragen; Eltern
verbinden unter **Verbindungen** in der Verwaltung. Die Anmeldung mit
Google ist ein eigenes Projekt — siehe [README](../README.de.md#mit-google-anmelden).

### Fotoordner

Neben hochgeladenen Bildern liest der Bilderrahmen einen Ordner am Server,
ein Unterordner je Haushalts-ID:

```
./photos/1/      # Haushalt 1
./photos/2/
```

`FAHOBA_PHOTO_FOLDERS_HOST` setzen, wenn die Ordner woanders liegen.
Fahoba liest dort nur.

### Verwaltung einschränken

`FAHOBA_ADMIN_ALLOWED_IPS=100.64.0.0/10` (etwa dein Tailnet) hält `/admin`
vom Internet fern; Wand und Handys funktionieren weiter von überall.

## Aktualisieren

```bash
cd /opt/fahoba && git pull && ./deploy/rollout.sh
```

`rollout.sh` lädt das neue Image (oder baut es), startet es neben dem
laufenden, wartet, bis es gesund ist, und entfernt erst dann das alte — die
Familien merken nichts. Wandtablets laden sich neu, sobald die neue Version
antwortet.

Vor einer neuen Hauptversion den [Changelog](../CHANGELOG.md) lesen: Er
nennt, was eine laufende Installation wissen muss.

### Von Tagwerk zu Fahoba

Bis 0.3.0 hieß das Projekt Tagwerk. Ein bestehender Server aktualisiert wie
oben; Tablets und Handys bleiben angemeldet, die Datenbank wird übernommen.

- In `deploy/.env` `TAGWERK_HOST` (Traefik) bzw. `TAGWERK_SITE` (Caddy) in
  `FAHOBA_HOST`/`FAHOBA_SITE` umbenennen — ohne startet der Server nicht.
  Die übrigen `TAGWERK_*` gelten vorerst weiter; bei Gelegenheit umbenennen.
- `APP_NAME="Family Home Base"` setzen, sonst tragen Mails und der Browser-Tab den alten
  Namen.
- Zeigt `FAHOBA_IMAGE`/`TAGWERK_IMAGE` auf die alte Image-Adresse, auf
  `ghcr.io/fahoba/fahoba` umstellen.
- Das Compose-Projekt bleibt `tagwerk` (Volumes, Netz); nicht umbenennen.
- Ein Checkout in `/opt/tagwerk` darf dort bleiben; `pull-household.sh`
  braucht diesen Ordner dann als drittes Argument.

## Sicherung und Wiederherstellung

```bash
./deploy/backup.sh /srv/backup/fahoba     # z. B. nächtlich per cron
```

Schreibt die Datenbank (`db_<datum>.dump`) und alle hochgeladenen Dateien
(`storage_<datum>.tar.gz`) und behält 14 Tage (`KEEP_DAYS`). Kopiere sie
vom Server weg und bewahre `.env` getrennt auf: Ohne ihren `APP_KEY` sind
die gespeicherten Zugangsdaten nicht lesbar. Die Erweiterung
`fahoba/webdav-backup` (in der Verwaltung unter **Betrieb →
Erweiterungen**) lädt stattdessen jede Nacht eine Sicherung in einen
WebDAV-Ordner.

**Wiederherstellen** auf eine frische Installation mit derselben `.env`:

```bash
docker compose up -d db
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < db_<datum>.dump
docker compose up -d app
docker compose exec -T app tar -C /var/www/html/storage/app -xzf - < storage_<datum>.tar.gz
```

## Fehlersuche

**Zuerst die Logs:** `docker compose logs app --tail 100`. Laravel schreibt
in die Ausgabe des Containers.

| Was du siehst | Warum, und was hilft |
|---|---|
| `FAHOBA_SITE missing in .env` / `FAHOBA_HOST missing in .env` | Der in `COMPOSE_FILE` gewählte Aufbau braucht seinen Hostnamen. |
| `network web declared as external, but could not be found` | `compose.yaml` erwartet Traefik. Die eigenständige Datei nehmen oder `TRAEFIK_NETWORK` setzen. |
| Caddy bekommt kein Zertifikat | Ports 80 und 443 müssen aus dem Internet erreichbar sein und der Name auf diesen Server zeigen. Hinter einem eigenen Proxy `FAHOBA_SITE=:80`. |
| Anmeldung am Handy passiert nichts | Die Adresse ist kein HTTPS, oder dein Proxy schickt `X-Forwarded-Proto` nicht mit. |
| `No application encryption key has been specified` | `APP_KEY` ist leer — siehe Schritt 2. |
| 500 nach einem Update, „column … does not exist“ | Die Migration ist gescheitert; `docker compose logs app` nennt den Grund. Ein Neustart versucht es erneut. |
| Der Build bricht bei `composer audit` ab | Eine PHP-Abhängigkeit hat eine bekannte Lücke. Fahoba aktualisieren; gibt es noch keine Behebung, baut `FAHOBA_AUDIT=warn` trotzdem. |
| Die Wand aktualisiert sich nicht von selbst | Ohne Reverb schaut sie alle fünf Minuten. Mit Reverb prüfen: `BROADCAST_CONNECTION=reverb` und läuft der Container `reverb`. |
| Kein Push am Handy | VAPID-Schlüssel fehlen oder wurden geändert; unter iOS muss die App installiert sein (Zum Home-Bildschirm). |
| Läuft der Queue-Worker? | `docker compose exec app ps aux \| grep queue:work` |

## Nachschlagen: Einstellungen

Jede Einstellung ist in [`deploy/.env.example`](../deploy/.env.example)
erklärt. Die über diese Anleitung hinaus:

| Einstellung | Standard | Bedeutung |
|---|---|---|
| `FAHOBA_CSP` | `report-only` | Content Security Policy: `report-only`, `enforce` sobald das Log ruhig bleibt, oder `off` |
| `FAHOBA_TRUSTED_PROXIES` | private Bereiche | Adressen, die `X-Forwarded-For` setzen dürfen |
| `FAHOBA_AUDIT` | `strict` | eine verwundbare PHP-Abhängigkeit stoppt den Build; `warn` meldet nur |
| `FAHOBA_PLUGINS` | leer | Plugins, die beim Start installiert werden, durch Leerzeichen getrennt (siehe [Plugins](#plugins)) |
| `FAHOBA_FRONTEND` | leer | eine Oberfläche als Paket an Stelle der offiziellen App (siehe [Designs und andere Oberflächen](#designs-und-andere-oberflächen)) |
| `APP_VERSION` | Commit des Images | Wandtablets laden neu, wenn er sich ändert |
| `APP_LOCALE` | `de` | Sprache, wo weder Haushalt, Konto noch Tablet eine gewählt hat |
| `LOG_LEVEL` | `warning` | `info` oder `debug` während der Fehlersuche |
