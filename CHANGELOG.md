# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning according to [SemVer](https://semver.org/). Until 1.0.0 a minor
release may still change the plugin API, the HTTP contract and the design
tokens; such a change is named here.

## [Unreleased]

### Changed

- **The family reads “Family Home Base”, written out** — app, admin,
  wordmark, mails, push and the app's name on the home screen. “Fahoba”
  stays the short form for code, repositories and packages: an acronym
  nobody can pronounce at first sight is no name to greet a family with.
  A migration relabels the built-in connections, and an export from 0.3.0
  imports with the new label. Set `APP_NAME="Family Home Base"` in
  `deploy/.env`: mails take their sender's name from it.
- **Opening the app shows the mark and the name at once.** Until the
  texts had loaded the screen stayed blank, and then read “… wird
  geladen” without a picture; it now stands from the first paint (Nuxt's
  loading template) and keeps its place when the app takes over. The home
  screen says “Home Base”: Android cut the full name off.
- **A new mark: a roof over a small house**, terracotta on sand, by a
  designer. Android gets a maskable icon of its own — the rounded tile
  cropped to a circle showed its corners — and the admin's lockup sets the
  mark beside the name without the tile.

## [0.3.0] — 2026-11-12

### Renamed: Tagwerk is now Fahoba

For trademark reasons. The name is short for **Fa**mily **Ho**me
**Ba**se. The next release is 0.3.0 — still a minor one, as everything
before 1.0.0: plugin authors must follow the rename, a running server and
its tablets need not.

- **Breaking for plugins.** `Tagwerk\PluginApi` is `Fahoba\PluginApi`,
  `tagwerk/plugin-api` is `fahoba/plugin-api`, `@tagwerk/client` is
  `@fahoba/client`, `extra.tagwerk` in a plugin's `composer.json` is
  `extra.fahoba`, Artisan commands are `fahoba:*`. No aliases: there are
  hardly any third-party plugins yet, and a clean cut costs them less than
  years of two names.
- **What a server holds is carried over.**
  - A migration renames the bundled plugins' keys (`tagwerk/bring` →
    `fahoba/bring`) in every column of the schema, json included —
    connections, favourites, tiles, designs and extensions' settings
    stay. It also renames the built-in calendar's id, without which every
    household would have got a second family calendar.
  - Exports from before still import (`RenamedFromTagwerk`): household
    archives, recipe collections (`tagwerk-recipes`) and the marked secrets
    (`tagwerk_plain_secret`).
- **Paired tablets and signed-in phones stay signed in.** `RenamedCookies`
  reads `tagwerk_device_token`/`tagwerk_user_token`, sets the new cookie and
  clears the old; `theme.js` moves the browser's `tagwerk-*` storage keys
  before anything reads them.
- **Calendars written before keep their people and echo protection:**
  Google's `tagwerk_people`/`tagwerk_revision` and CalDAV's `X-TAGWERK-*`
  are still read, written anew under the new names.
- **Webhooks send `X-Fahoba-*` and, for receivers set up before,
  `X-Tagwerk-*`.**
- **`deploy/.env` may keep `TAGWERK_*`** — the entrypoint and Compose fall
  back to them and log a hint. Except `FAHOBA_HOST` and `FAHOBA_SITE`:
  Compose cannot fall back for a required variable, the start names the old
  one. `TAGWERK_PLUGINS="tagwerk/caldav"` installs `fahoba/caldav`. The
  Compose project, its network and the database defaults keep the name
  `tagwerk`: Compose names the volumes after the project, and a new name
  would have started every server on an empty database.
- **A new mark: a tick inside a house on the tile** —
  and the wordmark in Plus Jakarta Sans 800. The browser tab gets a bold
  variant (`favicon.svg`): the outline blurred at 16 pixels.

### Changed

- **Symfony 8.1.11.** Advisories of 2026-11-04 against `http-foundation`,
  `http-kernel`, `routing` and `html-sanitizer` stopped the image build at
  `composer audit`; the lock now pins the fixed releases.
- **node-forge 1.4.3.** Four advisories against `node-forge` up to 1.4.2
  (via Nuxt's development server, `listhen`) stopped CI at `pnpm audit`; the
  override lifts it.
- **The tapped reward card says it was requested** (“Angefragt – die Eltern
  entscheiden”, framed in the child's colour). The hourglass beside the
  balance sat above the cards, where nobody looked after tapping one below;
  the open requests' list further down was out of sight on the tablet.
  `/api/wall/rewards` adds `reward_id` to each open request for it.

## [0.2.0] — 2026-11-08

A minor release because the HTTP contract grows by `GET /api/statistics`
and the MCP plugin by a tool; nothing that existed changes, a 0.1.0 tablet
keeps working.

### Added

- **Statistics in the admin (Everyday → Statistics).** Who did which task
  how often, as a table that darkens with the count. Beside it: done tasks
  over time per person, the share of their own tasks each person did,
  weekdays, and stars earned against spent. A period, people and one task
  narrow everything; the rows download as CSV (semicolon and BOM, so Excel
  with German settings opens it). Everything is read from completions and
  star entries, nothing new is stored. The share of own tasks counts
  derived occurrences of *active* tasks only: a retired task has no rule
  left to say what was due, so its history shows in the counts but not in
  the share. Gifts between siblings count neither as earned nor as spent.
- **The same statistics in the app, over the API and for an assistant.**
  `GET /api/statistics` (period, dates, people, task) answers with the
  numbers the admin page draws; the app opens them from the menu
  (“Statistik”), the MCP plugin offers them as `task-statistics` with
  names instead of ids. Parents only (`household.manage`): the numbers
  compare children, which a child's phone or the wall in passing should
  not. On the wall it would need the PIN, so the wall offers no entry.
  A custom span is at most two years — every occurrence in it is derived.
- **Requested stars show beside the balance** (hourglass, `−20`). The open
  requests are listed below the rewards, out of sight on a tablet, so a
  child's tap on a reward answered with nothing visible.

### Changed

- **The project lives at `github.com/tagwerk-family`, the image at
  `ghcr.io/tagwerk-family/tagwerk`.** Old addresses under `pointia` redirect
  for git, not for the image: set `TAGWERK_IMAGE` anew.
- **Installing needs no checkout.** The README's setup is a `compose.yaml`
  and a `.env` written in place; the image carries everything else.
- **The Traefik setup pulls the published image too.** `compose.yaml`
  ignored `TAGWERK_IMAGE` and always built on the server; it now follows it
  like the standalone setup, and `tagwerk-app` still builds from the
  checkout.
- **`@tagwerk/client` is published through npm's trusted publishing.** npm
  refuses tokens without two-factor bypass; GitHub's OIDC identity of
  `split.yml` needs no token at all, so none can leak.

### Fixed

- **A tapped notification opens where it is handled.** A reward request
  leads to the stars, the daily summary to the tasks. The messages carried
  no link, so the push fell back to `/`, and the app opened the account's
  start area.
- **The dashboard on an upright tablet.** Below `lg` the twelve columns of
  the landscape grid left every tile some 150 pixels: the clock was cut off,
  names shrank to one letter. There the grid now has six columns, each tile
  keeps its size in columns and is set anew in reading order; arranging stays
  in landscape, the only layout stored.
- **The timetable's times no longer run into the lessons** on a narrow
  screen: their column was too slim for `08:00`.
- **The app shows a favicon.** Its head linked no icon, so browsers asked
  for `/favicon.ico`, which nginx hands to Laravel's empty file; the head now
  names `icon.svg`, which nginx already served for exactly that purpose.

## [0.1.0] — 2026-11-04

The first release. Everything a family uses from day to day, on a wall
tablet and on the phone, from one self-hosted server for several
households. Why it is shaped the way it is: `docs/architecture.md`.

### Added

- **Calendar** — built in, or Google and CalDAV in both directions; day,
  three days, week, month and list, one column per person, school band.
- **Tasks and routines** — taking turns, times of day, one per child,
  paused in holidays; tap, confirm, then “who was that?”; four weeks to
  catch up on from the phone.
- **Stars and rewards** — day bonus, extra stars with a reason, gifts
  between siblings, badges, reward requests; the parent PIN guards the
  wall.
- **Lists** — shopping lists in Tagwerk, mirrored to Bring in both
  directions or pushed to Google Tasks.
- **Meals and recipes** — week plan, portions by who eats, the family's own
  book (typed, from a link, photographed, as a collection), Koch-Wiki and
  Tandoor as sources, ingredients summed onto the list.
- **Timetable** per child with bell schedule, holidays and cancellations.
- **Dashboard** per tablet and per account, weather, resting mode with
  dimming.
- **Photo frame** from uploads and server folders in five layouts per
  device, a library with favourites, albums and memories, a picture sent
  from the phone to the wall.
- **One app** for wall and phone, with push, realtime, light and dark, a
  glass look and backgrounds.
- **Languages** — German and English, more as language packs.
- **Operation** — several households on one server, an operator with
  support access, permissions per person, guests, one account in several
  households, read-only households, export, move and deletion, notices and
  messages, two-factor and social sign-in, a published image with a
  Traefik or a Caddy setup.
- **Plugins** — `tagwerk/plugin-api` (MIT) for providers, dashboard tiles,
  photo rules, whole areas, extensions, notices, language packs and design
  packs; a generator, a testkit and a template repository. Tagwerk's own:
  API tokens, OpenAPI, calendar subscriptions, webhooks, MCP, CalDAV,
  WebDAV backup, Bring, Koch-Wiki, two-factor, social sign-in.
- **Own frontends** — `routes/api-contract.txt` fixes the HTTP contract,
  `@tagwerk/client` (MIT, npm) talks to it, `TAGWERK_FRONTEND` serves
  another frontend at `/`, `tagwerk-frontend-template` is the start.

### Known limitations

- **Signing in with Google from an iPhone home-screen app may land in
  Safari.** iOS keeps the installed app's cookies apart; the way back from
  Google opens in the browser, which then is signed in instead. Android is
  not affected.
- **The dashboard cannot be arranged on a phone.** Below `sm` the tiles
  stack and the pencil is gone; an account arranges its dashboard on a
  tablet or computer and sees the result stacked on its phone.
- **e2e still runs per entry, not per area.** `wall.spec.ts` (tablet, wide)
  and `mobile.spec.ts` (account, narrow) stay as they were; the crossings
  are two smoke tests (`responsive.spec.ts`).
- **Stored content stays in the language it was created in.** Starter
  tasks, rewards and the first shopping list of a new household are rows,
  not texts: they are written in the household's language at creation and do
  not change when someone switches later.
- **Amazon Photos cannot be synced automatically.** There is no interface;
  pictures reach the server folder by download.
- **A recipe's photographed pages do not travel in a collection export.**
