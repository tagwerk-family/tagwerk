# Building your own Fahoba frontend

Fahoba's server decides everything — who may do what, whose turn it is,
what happens when — and the app at `/` only shows it. That makes the app
replaceable: a community frontend for an e-ink wall, a reduced view for a
grandparent's tablet, an interface in another framework. This guide is for
the people who build one. Changing only colours, font and corners is
simpler: a [design pack](plugins.md#design-packs).

## Start

Open [`fahoba-frontend-template`](https://github.com/fahoba/fahoba-frontend-template),
press **Use template**, then:

```bash
npm install
FAHOBA_URL=https://fahoba.test npm run dev
```

The template is plain Vite + TypeScript on
[`@fahoba/client`](https://www.npmjs.com/package/@fahoba/client): pair a
wall or sign in, show the coming week, reload when the server announces a
change. Bring any framework.

## How a frontend reaches the server

- **Served by Fahoba, on its origin.** The sign-in is an httpOnly cookie
  (`fahoba_device_token` for a wall, `fahoba_user_token` for an account)
  that the server sets. JavaScript never sees a token, and from another
  origin the cookie would not travel. In development, Vite's proxy passes
  `/api` and `/broadcasting` to your server, which gives the same picture.
- **Two ways in.** `GET /api/session` answers `device` or `user`; 401 means
  nobody. A wall pairs with a code from the admin (`POST /api/devices/pair`),
  an account signs in (`POST /api/auth/login`, 202 with a `two_factor`
  ticket when the account needs its authenticator code, redeemed at
  `POST /api/auth/two-factor`). Both use the same routes afterwards.
- **One call knows the household:** `GET /api/wall/agenda?from&to` — name,
  time zone, areas switched on, notices, people, events, language, and
  `app_version` (reload when it changes after an update).
- **Realtime announces, it never carries data.** The private channel
  `household.<id>` (Reverb, settings from `GET /api/realtime/config`) sends
  `.updated` with an area: `agenda`, `tasks`, `lists`, `points`, `meals`,
  `photos`, `notifications`, or `all`. Load that area again the way you load
  it anyway. Without Reverb on the server there is no socket; poll.
  `client.realtime()` gathers bursts and announces `all` after a lost
  connection.

## What the server decides

- **Permissions** arrive as the account's `permissions` and per item as
  `can` (`can.edit` on an event, `can.complete` on a task). Hide what is not
  allowed; the server refuses it regardless.
- **Refusals mean something** (`FahobaError.kind` in the client):

  | Status | `kind` | What the screen does |
  |---|---|---|
  | 401 | `unauthenticated` | back to pairing or sign-in |
  | 402 | `read-only` | the household is read-only; show `message` (it explains why) |
  | 403 | `forbidden` | an account may not do this |
  | 404 | `not-found` | gone — or the area is switched off for this household |
  | 422 | `invalid` | show `firstMessage` at the form |
  | 423 | `pin` | a wall needs the parent PIN (`POST /api/wall/pin`), then repeat the call |
  | 429 | `throttled` | wait |

- **Days are the household's.** Plain `YYYY-MM-DD` in
  `agenda.household.timezone`; format with `Intl` and that `timeZone`.
  `getDate()` and friends bind to the device's zone and move all-day events
  across midnight.
- **Rules the official app applies, in the client:** `occurrenceKey()` (a
  task occurrence is task + day + person + daypart — never key by task and
  day), `keepTicks()` (a load must not undo a tick this device just made),
  `justFinished()` (celebrate the moment a day is done, not every reload),
  `alarmIn()`/`withoutServer()` (light and dark switch at the server's
  `next_change`), `shownNotices()`, `holdAnnouncements()` while writing.

## Ship it

```bash
npm run build          # dist/index.html and dist/assets/, committed
git tag v1.0.0 && git push --tags
```

`composer.json` names the build: `"extra": {"fahoba": {"app": "dist"}}`.
Publish the repository on Packagist; an operator sets
`FAHOBA_FRONTEND=vendor/name:^1.0` in `deploy/.env`
([installation](installation.md#designs-and-other-frontends)). The
container installs the package at start, checks it (`php artisan
fahoba:frontend vendor/name`) and serves it at `/`.

- **`index.html` at `/`, everything else under `/assets/`** (Vite's
  default). Beside `index.html`, `sw.js`, `manifest.webmanifest`,
  `icon.svg`, `icon-192.png` and `icon-512.png` are served too. Without a
  `sw.js`, browsers drop the official app's service worker on their own.
- **No inline script, nothing from other hosts.** The app's Content
  Security Policy allows script files of the server's origin only — the
  check refuses an `index.html` with an inline script, an import map or a
  foreign script, because it would stay blank. Fonts and pictures go into
  `assets/`.
- **Trust.** A frontend runs with the signed-in person's cookie and sees what
  they see. Only the operator installs one, like a plugin. The server stays
  the authority: a frontend cannot get around a permission.

## What stays stable

- **The routes in
  [`apps/platform/routes/api-contract.txt`](../apps/platform/routes/api-contract.txt)**
  are the contract, together with the realtime areas and the error table
  above. A test fails when one goes missing: routes are added, never removed
  or renamed — that waits for a major release. Plugins' routes
  (`/api/<vendor>/<name>/…`) are theirs.
- **Fields are added, not removed.** An answer may grow; build on the fields
  you use.
- **Until 1.0.0** Fahoba is `0.x` and a minor release may still change the
  contract; the CHANGELOG names it. From 1.0.0 on, SemVer applies.
- **The client** (`@fahoba/client`) follows the same rule, with its own
  version.

Details per route: `/api/openapi.json` on a server with the
`fahoba/openapi` plugin.
