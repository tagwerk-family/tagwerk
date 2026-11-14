# Fahoba Project AGENTS.md

<!-- Last updated: 2026-11-11 -->

## OVERVIEW

What a family sees is **Family Home Base**, written out: app, admin,
mails, push, wordmark, translations. “Fahoba” (its short form) names the
project in code, repositories, packages and the README; `fahoba` in
identifiers. Never put “Fahoba” into a text someone reads in the UI.

Self-hosted family calendar for a wall-mounted Android tablet, as a replacement
for commercial devices such as Dæly Calendar or Hearth Display.
**Several households on one server**, strictly separated via `household_id`.
The operator creates them in `/admin` under Operations (“Betrieb”), not through
self-registration — see OPERATIONS.

Why it is shaped this way: **`docs/architecture.md`** — read it before any
substantive work. This file holds the rules; that one the reasons.

## STRUCTURE

```
fahoba/
├── apps/platform/    — Laravel 13 API + Filament 5
│   ├── app/Integrations/ — providers: contracts, data objects, implementations
│   ├── app/Services/     — what Fahoba computes itself, without the outside world
│   ├── plugins/          — Composer path packages, e.g. `bring` (fahoba/bring)
│   └── frontend/     — pnpm workspace
│       └── packages/
│           ├── app/    — Nuxt 4, the one app for wall and phone, built under /, dev server port 3000
│           ├── shared/ — Nuxt layer: design, theme, realtime, translations (i18n/)
│           └── e2e/    — Playwright against https://fahoba.test, runs from the host
├── .github/workflows/ — tests on every push, image on a release tag
├── deploy/           — production image, compose.yaml (Traefik), compose.standalone.yaml (Caddy), nginx, backup
├── docs/
│   ├── architecture.md — design and the reasons behind it
│   ├── plugins.md    — guide for plugin authors
│   └── kiosk.md      — pairing the tablet and kiosk operation
└── .ddev/            — DDEV environment incl. Valkey service
```

## TECH-STACK

- **PHP 8.5**, Laravel 13, Filament 5, Sanctum
- **PostgreSQL 16** · **Valkey** for cache, queue and session in
  development; the server uses the database for that (one service less)
- **DDEV** as the development environment (`php_version: 8.5`, `database: postgres:16`)
- Namespace `App\`, `declare(strict_types=1)` everywhere

Deviations from the sister projects `sovelle` and `skal`: they use Laravel 12 and
Filament 3. Here the respective current major versions are used on purpose, because it is a new project.

## CRITICAL RULES (always loaded)

- **NEVER SQLite** — tests run against real PostgreSQL (`fahoba_test`)
- Every domain table has `household_id` and uses `BelongsToHousehold`
- **Nothing is visible without a set `HouseholdContext`** — intended, so that
  a forgotten context yields empty results instead of someone else's data
- Background processes use `Model::withoutHouseholdScope()` or
  `HouseholdContext::forId()` and set the context deliberately
- **Sanctum resolves tokens through `App\Models\PersonalAccessToken`**, which
  bypasses the household scope. Without this exception a device could never log in,
  because the context only arises from the authenticated model
- **Relations carry the scope along.** `Modell::withoutHouseholdScope()` frees
  only the root query. Whoever touches `$modell->relation` afterwards triggers a new,
  filtered-again query and gets `null`. In background processes,
  therefore set `HouseholdContext::forId()` first, then load relations —
  see `SyncCalendarJob`, which deliberately does not preload `source`
- **Middleware order comes from the priority list, not from the route.**
  Whoever must run before authentication registers itself via
  `prependToPriorityList()` — with `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`
  as the anchor, **not** with `Authenticate`: Only the interface is in the
  list; using the class as the anchor appends the middleware ineffectively at the end.
  `route:list` shows the registration order, not the execution order, and
  is therefore no good for verification (see `DeviceTokenFromCookie`)
- **`getJson()` sends no cookies without `withCredentials()`.** Cookie tests
  otherwise pass vacuously, because nothing arrives at all
- **`Livewire::test()` does not go through the middleware** and thus not through
  `ResolveHousehold` either. Without an explicit `HouseholdContext::setId()` the
  table does not find its own records — the message then reads
  “Record [n] no longer exists.” and sounds like a Filament error, but is
  the scope working exactly as intended
- Seeders **never** with `WithoutModelEvents` — otherwise the `creating` hook does not fire
  and `household_id` stays empty
- **`BusyHouseholdSeeder` fills three weeks of activity** (around 200 events, tasks with
  completed past, long lists). It runs last in
  `DatabaseSeeder` and is deterministic — two runs give the same picture.
  e2e tests therefore must **not expect fixed counts** from the demo data;
  they run against the development database
- **e2e tests that count what one person has use `ownHousehold()`.**
  Parallel tests (wall and phone projects alike) book stars for the same demo
  children, so a relative count (`before + 1`) breaks in the suite only. A
  throwaway household (`ownHousehold()`/`dropHousehold()`, teardown removes
  `E2E %` leftovers) isolates it; an extra demo child would break tests that
  count children. Helpers address the demo household as
  `Household::orderBy("id")->first()` — unordered, PostgreSQL may return the
  throwaway one
- **Models with `timestampTz` columns need `HasZonedTimestamps`.**
  Laravel's default format for date casts is `Y-m-d H:i:s` — without
  zone offset. Carbon formats in its own zone, PostgreSQL reads the
  result in the session zone UTC: Vienna midnight becomes 02:00 Vienna
  time. The wall-clock time survives, the point in time does not. Affects **every**
  write, including the Google sync
- **Timestamp assertions never only on `toDateString()`.** A point in time shifted by the
  zone offset almost always keeps its date — precisely
  why the bug above went unnoticed despite existing tests. Always check the
  point in time (`equalTo()`) or at least `format('H:i')`
- **The wall computes in the household's time zone, never in its own.**
  The API delivers `household.timezone` for exactly that purpose. Days are plain
  calendar dates (`YYYY-MM-DD`), the mapping runs through `Intl` with an
  explicit `timeZone`, the date arithmetic through UTC. Local
  `Date` methods such as `getDate()` silently bind to the device time zone
  and let all-day events slip across the day boundary — on a
  tablet in Austria this never shows, in the UTC container immediately. That is why
  Vitest deliberately runs with `TZ=UTC`: If `Europe/Vienna` were set there, the
  tests would pass even with wrong code
- **Vite only lets known hosts through.** The development server is reached via
  DDEV's router at `fahoba.test` and, without
  `vite.server.allowedHosts`, answers with `403 Blocked request` — on *every* URL, including
  the proxy paths. Looks like a broken proxy, but is Vite
- **The pnpm store needs a destination.** In the container there is no
  writable default destination, so pnpm otherwise puts it in the workspace
  — 290 MB and 12,678 files in the middle of the repo. The destination is set in
  `apps/platform/frontend/.npmrc`, and additionally `.pnpm-store/` in
  `.gitignore` applies. After a change of the store path pnpm demands
  recreating `node_modules` (`CI=true` allows that without a terminal)
- **Promises about outages must not depend on the queue driver.**
  “A Bring outage costs a convenience, not the core function” holds
  only if the job catches and logs the exception itself. With `sync`
  — common in tests and small installations — it otherwise propagates into the
  response, and creating an item fails although it is long since in
  the authoritative list
- **`make:filament-resource --generate` produces a `household_id` select field.**
  It has to go: `Household` is the tenant itself and carries no scope, so the
  list would offer all households for selection. The column is set by
  `BelongsToHousehold` in the `creating` hook — see `DeviceForm` as a model
- **The auth guard keeps the resolved user in the container.** A test
  runs several requests in the same container; a deleted token then
  apparently keeps working. `Auth::forgetGuards()` between the requests shows the
  true behaviour — in production every request starts fresh anyway
- **Wall and phone share the endpoints.** `ResolveHousehold` takes the
  household from the authenticated model, and users as well as devices carry
  a `household_id`. That is why the routes carry both cookie middlewares
  (`device.cookie`, `user.cookie`); each sets the Authorization header only
  if it is still missing. Both cookies live on `/`, and `device.cookie` runs
  first: a browser holding both is the tablet (`/api/session` says
  `device`). Unpairing (`DELETE /api/devices/me`, behind `household.manage`
  and so the PIN; “Gerät entkoppeln” in `SettingsSheet`) or signing out is
  the way from one to the other. A device cookie whose token no longer
  exists is cleared on its 401 (`DeviceTokenFromCookie`) — otherwise it
  shadows every later account sign-in in that browser. A
  second interface just for the phone would mean double work on every change
- **`Device` is not `Authenticatable`.** On the wall the authenticated party is a
  device with a Sanctum token but without the auth interface. Anything that silently
  calls `getAuthIdentifier()` breaks there with `BadMethodCallException` —
  `throttle:10,1`, for example. For such routes create a dedicated limiter in
  `AppServiceProvider` and key it via `$request->user()?->getKey()`
  (see `RateLimiter::for('pin', ...)`)
- **Book points only on `wasRecentlyCreated`.** Ticking off uses
  `firstOrCreate`, because people regularly tap twice on the tablet. Without this
  check the second tap would bring a second credit
- **A form field is not checked via the page content.**
  `assertDontSee('Household')` fails, because the word appears in the
  navigation bar. Use `Livewire::test(...)
  ->assertFormFieldDoesNotExist('household_id')` instead
- **Tandoor throttles login sharply** (about ten attempts per day and
  address). Hence a fixed token from the Tandoor settings, never a
  login per call
- **`Broadcast::channel()` registers on the driver that is set at boot.**
  The BroadcastManager has no `channel()` method of its own;
  the call passes through to `driver()` via `__call`. Whoever switches `broadcasting.default`
  only inside the test gets a fresh driver with an **empty**
  channel list — every channel is then rejected, and the test checks nothing.
  Conversely, the `null` driver (default in tests) authorises **every** channel
  silently with 200, even a foreign one. A test of channel authorisation must therefore set the
  connection before `parent::setUp()`, in `$_SERVER`,
  `$_ENV` **and** `putenv()` — the first two take precedence, and PHPUnit
  fills them from `phpunit.xml`. See `RealtimeTest`
- **`/broadcasting/auth` needs the same cookie middlewares as `/api`** and
  is therefore set via `withBroadcasting(attributes: …)`, not via
  `withRouting(channels:)` — otherwise authorisation would hang on the web guard, which neither
  wall nor phone uses. In development, `/broadcasting`
  must additionally be in the `devProxy` of both Nuxt configurations
- **Announcements are per household and area, never per row.** Every
  saved domain model dispatches `AnnounceHouseholdUpdateJob` (model events
  in `AppServiceProvider`), and every open wall and phone fetches everything
  again on it. A Google sync saving hundreds of events once sent one
  announcement each — the browsers ran out of connections
  (`ERR_INSUFFICIENT_RESOURCES`) and even the admin in the same browser
  stalled, because DevTools show the service worker's requests of every
  tab. The job is `ShouldBeUniqueUntilProcessing` (key household:area,
  dispatched with two seconds' delay), and `useRealtime` gathers the
  announced areas (`gatherAnnouncements`, via `coalesce()`): a burst during a
  refresh becomes one more refresh, and `AppShell.refresh(areas)` loads only
  what those areas touch — a tick no longer reloads the agenda. While the
  device itself writes (`holdAnnouncements()` in `useTasks().toggle`),
  announcements wait until it has been quiet for `QUIET_MS`: ticking off
  together in the evening, every tap otherwise met a reload on a slow
  tablet. A new area name the shell does not know loads everything.
  When the socket comes back after being lost (`onConnectionChange`), it
  announces `all`: a phone asleep missed what was announced, and its
  `visibilitychange` reload often runs before the network is back.
  `save()` on an unchanged model still fires `saved` — a read must never
  save (`ReadingAnnouncesNothingTest`). Locally `BROADCAST_CONNECTION=log`,
  so none of this shows without Reverb
- **Cache locks run on their own connection** (`locks` in
  `config/database.php`, `cache.stores.database.lock_connection`). The
  server keeps its cache in the database; a unique job's lock is taken at
  dispatch, and a lock that already exists fails its insert — inside the
  app's transaction PostgreSQL then aborts everything (`25P02`). Approving a
  reward saves two rows in one transaction and failed with a 500 on every
  try. Locally Valkey hides it; tests set `cache.default` to `database`
  (`WallRewardTest`)
- **Occurrences and responsibility are derived, never stored.** A
  routine has no occurrence in the database, and whose turn it is follows
  from the running number of the occurrence since `due_on`, modulo the length of
  `tasks.rotation`. Counting therefore starts **from `due_on`**, not from the
  requested period — otherwise the sequence would start over on every page turn.
  Only the deviation is stored (`task_assignments`), just like
  `task_completions`: a fact about an occurrence, addressed via
  task and calendar day
- **Ticking off without a person follows a chain, not a single source:**
  explicit person → person responsible for the occurrence → `task.person_id`. The
  last link is not cosmetic: The derivation only applies if the date
  really is an occurrence of the rule, and when ticking off a
  postponed day retroactively it is not — without the fallback the
  credit would silently lapse (see `WallPointsTest`)
- **Code is English**: identifiers, comments, test names, logs, CSS classes,
  file names and stored values (defaults, area and icon names,
  directories). Texts that someone reads in the UI go through the
  translations (see LANGUAGES); only demo data stays German
- Auth tokens NEVER in `localStorage` — httpOnly cookies
- NEVER gate features only in the frontend — the backend is authoritative
- NO `as any` / `@ts-ignore`; never suppress PHPUnit errors with `@`
- No AI attribution in commits or files

### UI

- **Colours only via the tokens** from
  `packages/shared/app/assets/css/fahoba.css`: `bg-canvas`, `bg-surface`,
  `text-ink-2`, `border-line`, `bg-accent` and so on. Never `bg-white` or
  `text-slate-*` — in the dark theme exactly that spot would stay light. Only
  person and list colours come as hex from the database and are set inline.
- **The glass look is tokens, not components.** `<html data-look>` comes
  from the `look` preference (per device/account, `classic` while resting),
  `data-backdrop` from `/api/theme` (`households.backdrop`, enum `Backdrop`,
  colours in `fahoba.css`; a device's or account's `backdrop` preference
  wins unless it is `household` — resolved by `ThemeController`, so the app
  reloads the theme after choosing). Glass redefines `--surface`, `--line` etc. as
  translucent; blur sits only on `.shadow-floating` and `.sticky`. A new
  floating panel therefore needs `shadow-floating`, and must not contain a
  `position: fixed` child — `backdrop-filter` makes it the containing block.
  Person colours as a fill go through `tint()` (`utils/tint.ts`), never a
  bare `backgroundColor: color + '2e'`: on glass the tint would mix with the
  gradient. Calendar entries (events, lessons
  in the grid, month bars) pass `solid`; everything else stays `soft`.
- **Design packs set tokens, never components** (`offer('themes', …)`,
  contract `Fahoba\PluginApi\Themes\Theme`, rules in `Stylesheet`).
  - **The token list is a contract.** `fahoba:design-reference` writes
    every custom property of the `:root` rules in `fahoba.css` into
    `plugin-api/resources/reference/design.json`; `DesignReferenceTest`
    fails while stale. A new token is a minor API change, a renamed one a
    major — add, never rename. Font and corners are tokens too
    (`--font-body`, `--font-heading`, `--corner-*`): `@theme inline` points
    Tailwind's `--font-sans`/`--radius-*` at them, values sit in `:root`.
  - **Served weight equals the core's.** `Stylesheet::serve()` writes
    `:root<attrs>:where([data-design])` — the same specificity as the core
    rule with the same attributes, winning only by order (the link is
    appended after the app's CSS). So the core's dark and glass tokens
    still beat a pack's plain light ones; a pack narrows as far to win.
  - **Checked on first ask** (`App\Support\Designs`, like `Languages`):
    selectors, tokens, `url()` only to fonts/pictures beside the file,
    no escapes or `<`, light and dark present, AA contrast. A failure
    withdraws the package. The served CSS and its files carry a hash
    (`/api/design/{vendor}/{name}/{hash}.css`, `…/{hash}/{file}`, public,
    `immutable`, only files the sheet names).
  - **Chosen like the backdrop:** `households.design` (Household → Light and dark theme,
    hidden while none is installed), the `design` preference (`household`,
    `core`, or a key) wins; `ThemeController` resolves it into `design`
    (`{key, name, url}` or null) plus `designs`. A removed package is
    Fahoba's own. `useTheme` appends `<link id="fahoba-design">` and sets
    `<html data-design>`; `public/theme.js` does it before the first paint
    from `localStorage` (`fahoba-design`, only an `/api/design/` URL —
    `utils/design.ts` holds the same check).
- **A horizontal scroller needs `relative`.** An absolutely placed child
  (`sr-only` included) takes its containing block from outside the scroller
  if the scroller is not positioned, escapes its clipping and widens the
  whole page — a swipe beside the strip then moves everything. Seen in the
  task columns (`DayColumns`); `responsive.spec.ts` checks the width.
- **`defineProps` in layer components is `any` in the apps' type check.**
  This only shows when a callback has parameters (“implicitly has an
  'any' type”). Annotate the props explicitly:
  `const props: Props = defineProps<Props>()` — see `TimeGrid`, `EventChip`.
- **Lists in the device preferences** (`Preferences::lists()`): menu order
  and hidden entries, on the wall behind `dashboard.arrange`.
- **Import relatively inside the layer, not via `~`.** `~` points to the `app/` of
  the including app, not to that of the layer. Composables and components of the
  layer are auto-imported; its `utils` via `../utils/…`.
- **Classes that occur only in the layer need `@source`.** Tailwind
  scans only the app by itself; `fahoba.css` therefore carries
  `@source "../.."`. Check after `pnpm build`: A class used only in the layer
  must appear in the app's CSS.
- **No straight `"` in Vue attribute expressions.** In
  ``:aria-label="`„${titel}" entfernen`"`` it ends the attribute, and the
  build only reports “Unterminated template”. Close typographically with `“`.
- **Icons come from Lucide via `TwIcon`**, never imported directly in views.
  A new icon is one named import from `lucide-vue-next` plus a line in
  `ICONS`; the build ships only what is listed there.
- **`$fetch` passed through a wrapper needs a response type**
  (`withPin(() => $fetch<unknown>(…))`, `write(() => …)`). Inferred, it is
  matched against Nuxt's typed routes and the type check fails with
  TS2321 “Excessive stack depth” — at a different file whenever the
  project's types grow.
- **Every popup closes with Esc — via `useEscape`**, never with a custom
  `keydown` listener. The shared stack closes only the topmost popup;
  two listeners would close the PIN prompt and the form beneath it at once.
  Own component: `useEscape(() => emit('close'))`; popup inside a
  view: `useEscape(close, () => offen)`.
- **A view shows `TwLoading` until its first answer**, never its empty
  state: “Nichts zu tun” before the data arrive reads like a fact. The
  composable keeps a `loaded` flag in `useState` (`usePoints`, `useLists`,
  `useTimetable`; tasks use `range === null`).
- **A button that waits for the server uses `useBusy` and `TwPending`.**
  `run()` ignores a second tap while busy; `TwPending` shows a spinner inside
  the button only after 300 ms, next to the unchanged label. Not for actions
  that show their result at once (ticking off, favourites).
- **Icon buttons carry `aria-label`, hit areas at least `min-h-11`,
  removal is two-step** (`useTwoStep`). On the wall in the hallway people tap
  in passing.
- **The server computes the theme** (`App\Services\ThemeSchedule`). The
  UI does not compare with times of day itself, but sets an
  alarm for `next_change` and then asks again.
- **The mark** is the family's home base: a roof over a small house,
  the app's accent orange `#C2410C` on a sand tile `#F4E3CF` (three paths in a 160 box;
  `TwLogo.vue` and `app/public/icon.svg` hold the
  tile). The variants differ only in the mark's scale:
  `public/favicon.svg` (Laravel, linked by app and admin) draws it larger,
  every pixel counts at 16 px; `resources/brand/maskable.svg` full bleed
  with the mark inside Android's safe circle (`icon-maskable-512.png`) —
  the tile's corners would show in a circle; `badge.svg` white on
  transparent; `avatar.svg` square for GitHub and npm. The lockup
  (`public/logo-light.svg`, `logo-dark.svg`, for the admin) sets the mark
  without its tile beside the name, Plus Jakarta Sans 700 as a path, not
  as text, so swapping the UI font does not change it
  (`storage/app/brand/lockup.py`, not in Git).
  The app also ships `icon-192.png`, `icon-512.png`, `favicon.ico` and the
  `badge-96.png` for the notification status bar — Android ignores SVG
  there and reads the badge by alpha only. **Render them through Chromium,
  never `magick` on the SVG:** its built-in renderer dropped gradients and
  strokes of the earlier mark. `node scripts/render-brand.mjs` in
  `packages/e2e`, then pack `favicon.ico` as its header says. nginx serves
  the app's icons by name (`.ddev/nginx/fahoba-apps.conf`,
  `deploy/nginx.conf`): a new file there 404s until both list it.
- **Admin groups** (`NavigationGroup`): Family (people, accounts),
  Everyday (tasks, rewards, star history, lists, recipes, photos),
  Calendar & school (calendars, timetable, school closures), Setup,
  Integrations (everything that links outside — connections, API access,
  calendar subscriptions, webhooks), Operations. A new resource joins the
  group of what a family does with it, not of its table.
- **Statistics read, never store** (`App\Services\Statistics\TaskStatistics`,
  page `App\Filament\Pages\Statistics`). Counts come from `task_completions`
  (`skipped` left out), stars from `point_entries` without `gift_id`; the
  share of own tasks from `TaskOccurrenceService` up to today, so it knows
  only active tasks. Filters are `StatisticsPeriod::fromFilters()` (in the
  address, garbage falls back to four weeks).
  - Its widgets live in `app/Filament/Statistics/`, not `Widgets/`: the
    panel discovers that folder and would put them on the dashboard.
  - A person keeps their colour in every chart (`ReadsStatistics::seriesOf()`),
    “without a name” is grey.
  - `RawJs` chart options are written into an HTML attribute: a `"` from
    PHP (`json_encode` of a text) ends it and the chart shows as text.
    Put such texts in the dataset and read them in the callback.
  - One shape for everyone: `GET /api/statistics` (`StatisticsController`,
    `household.manage`, `feature:tasks`) feeds the app's `StatisticsView`
    (account only, opened from drawer and bar like the photo manager, pure
    rules in `app/utils/statistics.ts`) and the MCP tool `task-statistics`.
    Per-person maps are cast to objects: a PHP array with keys `0..n`
    would leave as a JSON list.
  - `__('Period')` is the school period (“Stunde”); a time span is
    `__('Time span')`. Check `de.json` before reusing a short English key.
- **Admin (Filament):** colours in `AdminPanelProvider::colors()`,
  everything else in `public/css/fahoba-admin.css` via the `fi-` classes. Primary
  shade 500 must carry light text (contrast AA), otherwise Filament picks
  the pale 400 for all filled buttons.
- **`ResolveHousehold` runs persistently in Filament** (`authMiddleware(…,
  isPersistent: true)`). Without it Livewire updates lack the
  household, and every create fails on `household_id`.
- **`Household` has no scope** — `HouseholdResource::getEloquentQuery()`
  restricts to the own one. New resources on the tenant itself
  need the same restriction.
- **Timestamp columns in Filament** carry
  `->timezone(fn (): string => HouseholdTimezone::current())`, otherwise they show
  in UTC. **Never** `FilamentTimezone::set()`: That would also shift
  `TimePicker` fields with plain wall-clock times (bell schedule, theme, daily summary).
- **Never show recurrences raw.** Use `RecurrenceRule::describeRule()` for
  output; the form splits `rrule` into helper fields on load and rebuilds the
  rule on save (`dehydrateStateUsing`), never from the browser.
- **Light/dark of the admin** comes from `App\Livewire\ThemeSwitcher`
  (`theme_preference` + `ThemeSchedule`). `localStorage.theme` always holds
  the computed `light`/`dark`, never `auto` — otherwise Filament follows the system.
- **Dates in Filament follow the language** — set once in
  `AdminPanelProvider::boot()` as closures, because the panel is built
  before the request's language is known. No `->date('d.m.Y')` in
  resources; plain `->date()` takes the default.

## LANGUAGES

German and English, plus any language a pack brings (see “Language
packs” below), chosen per person and per tablet. The household sets
the default (`households.locale`), an account (`users.locale`) or a tablet
(`devices.locale`) may differ; `null` follows the household.
`App\Support\Locale::for()` walks that chain, `ApplyLocale` applies it to
every request after authentication, and `config('app.locale')` (`de`) is
the end of it.

```
apps/platform/lang/de.json               backend: English text is the key
app/Support/Locale.php                   chain, supported(), intlTag(), describe(), inEvery(), using()
app/Support/Languages.php                packs from the catalog, checked when first asked
app/Support/PackTexts.php                a pack's texts without broken ones; served(), coverage()
app/Support/LocalDates.php               admin and rule dates; ICU patterns for pack languages
app/Http/Controllers/Api/LanguageController.php   GET /api/language, /api/language/{code}/{hash}.json
packages/plugin-api/src/Languages/       LanguagePack, Coverage (rules, comparison), Reference
packages/plugin-api/resources/reference/ English reference, written by fahoba:language-reference
app/Http/Middleware/ApplyLocale.php      api, web and Filament
frontend/packages/shared/i18n/locales/   de.json, en.json (@nuxtjs/i18n)
frontend/packages/shared/app/composables/useLanguage.ts
frontend/packages/shared/app/plugins/language.client.ts   holds the mount until language and texts
frontend/packages/shared/app/utils/language.ts            pure start rules, with Vitest
frontend/packages/shared/app/utils/intlLocale.ts
```

- **Backend: the English text is the key** (`__('Save')`), German lives in
  `lang/de.json`, English needs no file. `tests/Unit/TranslationsTest`
  fails on a key without a German entry and on German literals outside
  `__()`.
- **Frontend: nested keys** (`$t('tasks.nothing')`, `t(...)` in script),
  because vue-i18n reads dots as paths. `shared/test/translations.test.ts`
  checks: same keys in `de.json` and `en.json`, same placeholders and plural
  forms, every key the code asks for exists, no umlauts left in the code.
  Umlaut-free German (“Heute”) slips through — search for it by word.
- **No `@`, `$`, `|` or stray braces in messages.** vue-i18n reads `@` as a
  linked message and `|` as plural separator: `'Tag | Tage'` with
  `$t(key, n)`.
- **`ApplyLocale` must run after authentication**, placed via
  `appendToPriorityList(after: AuthenticatesRequests::class)` — before it
  there is nobody whose language could apply.
- **The panel's navigation is built once per process.** A test that
  switches the language between two panel requests sees the first
  language's menu. In production every request starts fresh; tests make
  one panel request after the switch (see `AdminProfileTest`).
- **Constants cannot translate.** `__()` in a `const` or a property default
  is a fatal error; option lists became static methods
  (`RecurrenceRule::weekdays()`, `HouseholdForm::holidayRegions()`,
  `HouseholdStarter::routines()`), labels `getModelLabel()` etc. Filament's
  navigation groups are the enum `App\Filament\NavigationGroup`.
- **Whatever reaches several people is built per language.** Push texts go
  through `Locale::inEvery()`, `WebPushService` picks each device's version.
  Mails follow the recipient (`User` implements `HasLocalePreference`).
- **Stored content is written in the household's language, once.** The
  starter tasks of a new household (`HouseholdStarter`) are created inside
  `Locale::using($household->locale, …)` and do not change later.
- **Caches of translated output carry the language in the key** —
  holidays (Yasumi names), plugin widget content.
- **Background jobs run in the default language.** A Google event without a
  title is stored as “(ohne Titel)” regardless of who reads it.
- **The app mounts after language and texts.** `language.client.ts` asks
  `GET /api/language` before the first render. The route is public, behind
  both cookie middlewares, without `auth:sanctum`. It answers a device or
  account by `Locale::for()` and nobody by `Accept-Language` matched on the
  server (Symfony's `getPreferredLanguage()` answers the first supported
  language when nothing matches, so it is not used). An account's household
  is resolved in the controller: without the `household` middleware there
  is no context, and the membership would be missed. Offline, the last
  answer, texts included, comes from `localStorage` (`fahoba-language`);
  the browser decides only without both. `@nuxtjs/i18n` runs without
  `detectBrowserLanguage`.
- **The wall follows every agenda fetch** (`language`, and `locale` for one
  release), the phone `/api/auth/me`; `PUT /api/auth/locale` sets or resets
  (null) the account's choice. `useLanguage().apply()` loads first and
  switches once; it never sets a code whose texts it could not load.
- **Language packs** (`offer('languages', …)`, contract
  `Fahoba\PluginApi\Languages\LanguagePack`):
  - **A pack's files count for its code only.** `PackTranslationLoader`
    wraps Laravel's loader. A pack added as a plain path could bring a
    `de.json` and rewrite the German.
  - **Missing and broken texts fall back to English** (`fallback_locale`,
    vue-i18n `fallbackLocale` in `shared/i18n/i18n.config.ts`). Broken
    means the placeholders differ from the reference: such keys are left
    out on both sides, so the English shows instead of `{count}` (`Coverage`).
  - **`@nuxtjs/i18n` ignores `setLocale()` for a code not in its build
    config.** A pack's code is set with `setLocaleMessage` and
    `locale.value`, after `loadLocaleMessages('en')`: the fallback needs
    English loaded.
  - **A pack's texts are served, not built:** `/api/language/{code}/{hash}.json`,
    `immutable`. A package from Packagist has no sources at build time. A
    plugin layer's own `i18n/locales/<code>.json` is merged in (the
    manifest's `locales`).
  - **The reference lives in `fahoba/plugin-api`** (`resources/reference`)
    so the testkit's `assertLanguagePackComplete()` and the server measure
    the same thing. **After adding or changing a text run
    `php artisan fahoba:language-reference`**; `LanguageReferenceTest`
    fails while it is stale.
  - **Rules and comparison live in `Coverage`** (API package): two
    lower-case letters, never `de`/`en`, a BCP 47 tag starting with the
    code, existing files. A second pack for a code fails its package.
  - **The test fixture is Italian** (`tests/Fixtures/Languages`), because
    the dev-only `fahoba/example-language` brings `fr`. A test that needs
    an unsupported code uses `ja`.
  - **No `getLocale() === 'de'` for formats.** `LocalDates` keeps the
    German and English formats and asks ICU (`IntlDatePatternGenerator`)
    for everyone else. Yasumi gets the pack's tag and falls back to English
    names.
  - Operations → Plugins lists missing and broken texts per pack and the
    pack's `extra.fahoba.language.complete-for`.
- **`Intl` formats with `intlLocale`, never a literal `'de-AT'`.** It is a
  plain ref so that pure utils (`formatIsoDate`, `weekdayName`) can use it
  and computed values format anew after a switch. German stays `de-AT`.
- **Pure utils take texts as parameters** (`dayColumns(…, everyone)`,
  `peopleColumns(…, family)`) — no `useI18n()` where Vitest runs without
  Nuxt.
- **Weekday names come from `Intl`** (`weekdayName()`), not from lists: a
  German list would stay German.
- **`@nuxtjs/i18n` is pinned to 10.5.0.** 10.6.0 pulls a
  `@typescript-eslint` chain that crashes Node 24 with an internal
  assertion (`loadCJSModuleWithModuleLoad`) while Nuxt loads the module —
  no hint at the cause. The layer also needs its own `typescript` 5.9 and
  `vue`; otherwise pnpm resolves the module's peers to TypeScript 7.
- **e2e tests run in German** (`locale: 'de-AT'`, households default to
  `de`) and keep asserting German texts; the language switch has its own
  test.

## PROVIDERS

Every third-party service is a provider behind a contract. The domain logic knows
none of them by name; it asks the registry and checks capabilities.

```
app/Integrations/
├── ProviderRegistry.php      resolve per domain area
├── Contracts/               cross-area: UsesOAuth, FinishesConnection
├── Data/TokenSet.php
├── Google/GoogleOAuth.php   shared consent flow, no domain area
├── Calendar/{Contracts,Data,Builtin,Google}/
├── Lists/{Contracts,Data,GoogleTasks}/
├── Tasks/{Contracts,Data,Builtin,GoogleTasks}/
└── Recipes/{Contracts,Builtin,Tandoor}/
```

**A connection applies to exactly one area.** `provider_accounts` carries
an `area` column; calendar and lists of the same service are two rows with
their own consents, to be disconnected individually. Whoever connects Google Calendar has not
connected Google Tasks along with it.

| Area | Provider | Connection |
|---|---|---|
| `calendar` | `builtin` | created together with the household |
| `calendar` | `google` | consent, scopes: `calendar.events`, `calendarlist.readonly` |
| `lists` | `fahoba/bring` | plugin; email and password per household, via the wizard; sync in both directions |
| `lists` | `google` | consent, scopes: `tasks` |
| `tasks` | `builtin` | none — Fahoba's own tasks |
| `tasks` | `google` | consent, scopes: `tasks` |
| `recipes` | `builtin` | none — table `recipes` |
| `recipes` | `tandoor` | address and token per household, via the wizard |
| `geocoding` | `nominatim` | none — OpenStreetMap without credentials |
| `holidays` | `yasumi` | none — library, computes from `holiday_region` |
| `school_holidays` | `austria` | none — rule set for Austrian federal states |
| `school_holidays` | `ics` | address of a holiday calendar, via the wizard |

Google Tasks appears twice — as a list target and as a task source. Two
areas, two consents, two rows: Whoever pushes their shopping list there
has not thereby pulled in their tasks.

**Every area runs without a third-party service.** Calendar and recipes have a
built-in provider, lists live in Fahoba anyway and only additionally push
outwards. Holidays come from Yasumi (library, no
service), push talks directly to the browsers' endpoints.

Unlike calendar and lists, **several recipe sources can be active at the same
time**: `meal_plan_entries.recipe_provider` says where a planned
recipe comes from. Identifiers are strings — Tandoor counts, Mealie uses
slugs.

**Connecting happens through a wizard**, not through a row of buttons. Under
Connections (“Verbindungen”) there is a single button, behind it three steps: area, service,
setup. First the area, then the service — otherwise “Google Tasks”
would appear twice next to each other and do different things.

Nothing in the wizard names a provider: The selection comes from
`ProviderRegistry::connectableProviders()`, icons and explanations from
`DescribesSetup`, the input fields from `UsesCredentials::credentialFields()`.
A new service remains one class and one entry in
`config/fahoba.php`.

**Two ways into a connection.** `UsesOAuth` leads to the provider and back;
`UsesCredentials` asks for fields, validates them once and stores them encrypted
in `provider_accounts.credentials` (`encrypted:array`, separate from
`settings`, where harmless things live). **Never take credentials from `config/` or `.env`:**
On a server with several households such a fallback would be the
shopping list or recipe collection of a foreign family. Without an account the
provider is simply not connected.

The consent path is `/auth/{bereich}/{anbieter}/redirect|callback`. The
redirect address comes from the route, not from `.env` — a single
setting could only ever serve one area. **Both addresses must be entered
in the Google Cloud Console** if both services are used.

**Calendar: a narrow contract, five capabilities.** `CalendarProvider`
requires only `key()` and `label()`. Everything else a provider brings individually:
`ListsCalendars`, `UsesOAuth`, `SyncsEvents`, `WritesEvents`,
`WatchesChanges`.

| Provider | Base | Listing | OAuth | Delta | Writing | Channels |
|---|---|---|---|---|---|---|
| `builtin` | ✓ | – | – | – | – | – |
| `google` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

The domain services query the capability instead of knowing special cases: If a
provider cannot write, the event only arises locally — that is not an
exception, but simply how the built-in calendar works.

### Photo sources: the frame draws from several folders

```
app/Integrations/Photos/Contracts/PhotoSource.php   key, label, isConfigured, random, file
app/Integrations/Photos/Builtin/                    uploaded photos (table `photos`)
app/Integrations/Photos/Folder/                     a folder on the server, recursive
app/Integrations/Photos/Amazon/                     the same mechanism, own name and instructions
app/Services/Photos/PhotoLibrary.php                mixes all configured sources
```

- **Amazon Photos has no public interface.** The Amazon Drive API
  was shut down in 2023 and not replaced. What is read instead is the folder
  that the Amazon app syncs — a separate entry in the list, because people look for
  “Amazon Photos” and not “Folder on the server” („Ordner am Server“).
- **A folder provider delivers only what lies under its folder.** The
  identifier is the encoded path within it and comes from the device, so it is freely
  invented: Before delivery it is resolved (`realpath`) and checked —
  otherwise the source would be a read path onto the whole server.
- **Every household has its own root folder:**
  `FAHOBA_PHOTO_FOLDERS/<household_id>/`. In the wizard only a
  subfolder within it is entered; an absolute path or `..` does not lead out of it. Otherwise
  a parent could enter the photo folder of another family.
- **Uploaded photos are stored privately** (`storage/app/photos`), not under
  `public`. They are delivered via `/api/wall/photos/...` against the
  device token, in the admin via `route('photo.preview')`.
- **What is stored is a redrawn image** (`PhotoImage`, shared
  `ImageDecoder` with the profile picture): This drops EXIF including location data and
  everything else that otherwise hangs on an image file.
- **The wall fetches a batch, not one image.** A kiosk that asks every 30 seconds
  would make 2,880 requests a day and would stall at every network gap.
- **Photos from the phone** (`PhotoUploadController`, `POST /api/photos` and
  `/api/photos/known`, permission `photos.upload`). The phone downsizes
  (`usePhotoUpload`, `utils/photoPrep.ts`), the server redraws regardless
  — never store an image unchecked. The date must be read
  before downsizing: The canvas loses EXIF. `photos.fingerprint` is the
  hash of the **original**, unique per household; the stored redrawn
  image would never be comparable. The folder button is missing on iOS, which does know
  `webkitdirectory` but only opens the photo picker. e2e uploads
  are cleaned up by the teardown via `photos.user_id`.
- **Deleting a photo is a soft delete** (`photos.deleted_at`,
  `PhotoManageController`, `GET /api/photos`, `/photos/trash`,
  `POST /photos/delete|restore|purge` with `ids`). The trash is emptied by
  `fahoba:purge-photo-trash` after `Photo::TRASH_DAYS`; only `forceDelete`
  removes the files (`Photo::booted`), including the grid's small copy
  (`PhotoImage::thumbnail()`, made on first request under `photos/thumbs/`).
  Upload checks use `withTrashed()` — a trashed fingerprint counts as known.
  Ownership is `photos.user_id` of a `User`, never a device key; e2e teardown
  must `forceDelete` with `withTrashed()`.
- **Grid loads carry a generation.** `usePhotoLibrary::load()` supersedes a
  page still in flight; without it, going back from the trash while its
  reload runs left the grid empty (the new load bailed on `loading`).
- **The frame's look is per device** (`photo_*` preferences,
  `PhotoFrame.vue`, pure rules in `utils/photoFrame.ts`): layout, source,
  interval, crop, overlays; `photo_rest_style` is the tablet's own
  (`DEVICE_ONLY`), `photo_albums` a list of ids per household
  (`FILTERS`, `PER_HOUSEHOLD`). Every frame on screen has its own batch
  (`usePhotos(scope)`): the area and each dashboard tile may draw from
  different albums. The ambient layout always shows the clock; the overlay
  clock elsewhere follows `photo_clock` (always at rest).
- **Favourites and albums exist only for uploaded photos.** A source's
  `Photo::$favorite` is null where it has none; `?from=favorites|albums`
  narrows `PhotoLibrary::random()` to the builtin source, and the household
  rule still picks within it. `photo_album_photo` has no `household_id`
  and is listed in `HouseholdExport::PIVOTS`. Album routes take plain ids
  and look them up inside the scope — route binding would run before the
  household is known.
- **Memories are derived, never stored** (`PhotoMemories`): same days ±3 in
  earlier years (at least 3 pictures), runs of days at most one day apart
  (at least 8). Titles are made on the device from kind and dates.
- **"An der Wand zeigen" is cache, not a table** (`PhotoCast`, ten
  minutes, key `photo-cast:<household>`), delivered in the agenda as
  `shown_photo` to devices only. e2e tests of it run in an own household:
  the overlay covers every wall of the household it is sent to.
- **The library sorts by the day taken** (upload day as fallback, as
  `added_on` in the household's zone). e2e tests find their upload by the
  id from the `POST /api/photos` response, never by position.
- **Rules for the photo frame** (`households.photo_rule`, Household →
  Photo frame): A `PhotoRule` receives all images including their date and selects,
  without reading anything itself. `WeightedRule` divides by periods and
  **counts out** the shares instead of rolling dice per image; what a period
  lacks is filled by the others, finally by the images without a date. Without a rule (or
  with one whose plugin is missing) it stays with the old “evenly per
  source” („gleichmäßig je Quelle“). Sources with `ListsPhotos` deliver their entire stock, others
  their random selection.
- **The capture date comes from EXIF, otherwise from the file name — never from
  the modification time** (`TakenOn`). Downloaded albums would otherwise all carry
  the date of the download. `PhotoDateIndex` reads folder images once
  and remembers them in `photo_dates`, 300 per request, so that a freshly
  connected archive does not hold up the wall.
- **Amazon Photos cannot be synced automatically.** No
  interface, not even via AWS; the desktop app no longer downloads,
  rclone can no longer handle Amazon. Images get into the folder by download in the
  browser.
- **`ProviderArea::finisher()` may be null.** Photos have no consent and
  nothing to fetch.

### Weather: one source, one cache

`WeatherSource` asks with coordinates, not with a place name — the
household has stored them for the sun position anyway. Built in is
Open-Meteo: no key, no login.

- **One hour of cache.** A kiosk constantly redraws itself; without
  it there would be thousands of requests a day for a number that changes
  hourly.
- **Without a location: not an error, but a sentence.** `/api/wall/weather` answers with
  `weather: null` and a `hint` that says where to enter the location. This
  also applies to an outage at the provider.
- **The day's symbol is not Open-Meteo's daily code.** That code is the
  day's worst hour: one cloudy hour at dawn made a sunny day “cloudy”.
  `WmoCodes::forDay()` lets the daylight hours vote (rain in a quarter of
  them wins), and `WmoCodes::bySunshine()` corrects dry skies by the
  sunshine that gets through — high veil clouds count as “overcast” in
  WMO although the sun shines through them. Checked against Wetteronline
  for the same place.
- **Hours come with the same request and cache.** Today's past hours are
  cut at answer time, not at fetch time — the cache holds an hour. At
  night a clear sky shows the moon (`is_day`).
- **Celsius or Fahrenheit follows the place**, via the household's time
  zone (`TemperatureUnit::forTimezone()`, PHP's zone database knows the
  country). No setting; the unit is part of the cache key.
- **The window opens from shared state** (`useWeather().openDay`), drawn
  once in `app.vue` like the clock tools: the tile and the menu's weather
  (`menu_top` preference, `WeatherNow.vue`) open the same one.

### Dashboard: tiles on a grid

```
config/fahoba.php  widgets      key → name, area, default size
app/Dashboard/WidgetRegistry     all widgets; forHousehold() filters by area
app/Dashboard/DashboardDefaults  the first layout on first access
dashboard_widgets                household_id, device_id | user_id, type, x, y, w, h, settings, position
app/app/utils/grid.ts          grid math (12 columns), with Vitest
app/app/components/widgets/      one component per key
```

- **A new widget is an entry and a component.** The key
  connects the two; the mapping in `DashboardView.vue` is static, so that
  a missing counterpart is caught by the type check and not at the wall.
- **Overlaps are prevented, not resolved.** Where something already lies,
  nothing can be dropped — the tile snaps back. A grid that re-sorts itself on
  drop surprises everyone watching. The check happens
  twice: on the wall before sending, on the server before saving.
- **Every tablet and every account has its own dashboard**
  (`dashboard_widgets.device_id` or `user_id`, a check constraint allows
  exactly one; `DashboardWidget::ownedBy()`). The first visit places
  `DashboardDefaults` for that owner. A tile id from another dashboard is
  404 — the household scope alone would let a phone move the wall's tiles.
  Deleting a device or account takes its tiles along. Below `sm` the tiles
  stack in reading order (`utils/grid.ts` `stacked()`), `h` × 72 px tall,
  without the pencil; arranging happens where the grid fits. From `sm` to
  `lg` (a tablet held upright) `reflowed()` sets them on six columns, same
  sizes, reading order, clock and weather at least three rows — display
  only, the pencil is gone there too: what is stored is the landscape grid.
- **Edit mode sits behind the parent PIN** on a tablet (`dashboard.arrange`,
  checked in `WallDashboardController`, not on the route: an account needs
  no permission for its own dashboard). Without a switch every accidental swipe on a wall in the hallway
  would shift half the dashboard.
- **No grid gap, but inner padding per tile.** With `gap` the
  computed cell width when dragging did not match the displayed one.
- **The home page is the start area.** After a restart the wall
  is back there, not in the most recently chosen area — a kiosk occasionally
  loses power, and afterwards everything important should be visible without tapping.
- **A tile for a disabled area disappears only from the
  display.** The row stays: Whoever re-enables the area finds their
  layout from back then.
- **Tiles are small by default** (clock and weather two rows). What
  gets added sits below, but tersely: Date and buttons share one
  row, the forecast sits in three narrow columns. Verification is done with the
  screenshot of the default layout at 1280×800, not only with a large tile.
- **What a tile does beyond itself, it reports upwards**
  (`pick-event`, `person`); `DashboardView` passes it on to `app.vue` only outside
  edit mode. State that has to survive an area switch (timer, task filter)
  lives in `useState`, never in the
  tile.
- **Plugin widgets deliver data, not a component.** A `DataWidget`
  returns a `WidgetContent` — metric, list, table, pairs (at most 20 rows),
  progress, actions (at most 6 buttons) or image (only `https://` or a path
  here) —, and `WidgetGeneric.vue` draws it. No HTML: Texts stay text. Fetched via
  `GET /api/wall/dashboard/{kachel}/content`, cached for one minute per tile and
  setting; an error in the plugin gives 503 and stays
  in its tile. The default layout never creates a plugin widget.

### How to add a provider

1. Create a class under `app/Integrations/<Fachbereich>/<Name>/` that implements the
   base contract and exactly the capabilities the service really has.
   **No dummies** — an empty method that means “not supported” („geht nicht“) belongs
   expressed as a missing capability.
2. Register it in `config/fahoba.php` under `providers.<fachbereich>`.
3. `config/services.php` holds only what belongs to the server — such as the
   OAuth client ID. What belongs to a household (login, token,
   address) enters the account via `UsesCredentials`. For a service with
   consent, `UsesOAuth` is added; `scopes()` names **only** the permissions
   of this area.
4. Done — admin, background runs and wall follow from the registry.
   No intervention in domain services, controllers or Filament.
5. `tests/Feature/Plugins/ProviderContractTest` automatically checks the new provider too:
   contract, identifier, no network and no database in the
   constructor, complete description for the wizard.

### Plugins: providers and widgets from one package

Design: `docs/architecture.md#plugins`. A Composer
package brings providers or dashboard widgets without touching the core.
It depends on **`fahoba/plugin-api`** only (`apps/platform/packages/plugin-api`,
namespace `Fahoba\PluginApi\`, MIT): contracts, data objects, `Plugin`,
`Catalog`, `CurrentHousehold`, `HouseholdScoped`. The core implements them.

```php
// composer.json: "extra": {"fahoba": {"provider": "Acme\\Waste\\WastePlugin"}}
final class WastePlugin extends \Fahoba\PluginApi\Plugins\Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);
        $catalog->offer('calendar', 'acme/nextcloud', NextcloudCalendar::class);
        $catalog->offer('widgets', 'acme/waste', WasteCollectionWidget::class); // DataWidget
        $catalog->offer('photo_rules', 'acme/summer', SummerRule::class); // PhotoRule
    }
}
```

- **The API package never names `App\`** (`PluginApiBoundaryTest`, which
  scans `example-area` too). One core reference would tie every plugin
  built on it to the core's licence. A model a plugin needs travels as an
  interface the model implements: `ConnectedAccount` (`ProviderAccount`),
  `ConnectedCalendar` (`Calendar`), narrowed back with `::of()`.
- **Interface methods never share a name with a column**
  (`accountId()`, `areaKey()`, not `id()`, `area()`): Eloquent resolves
  attributes through methods of the same name and recursed forever.
- **Plugins load through `extra.fahoba.provider`, not `extra.laravel`.**
  `PluginManifest` reads `vendor/composer/installed.json` (cached in
  `bootstrap/cache/fahoba-plugins.php`, keyed by the file's mtime),
  `PluginLoader` registers each provider from `PluginServiceProvider`, the
  first in `bootstrap/providers.php`. The provider extends `Plugin`, whose
  final `register()`/`boot()` wrap `registerPlugin()`/`bootPlugin()`.
- **A broken plugin is skipped, never fatal.** A missing class, a class
  that is no `Plugin`, an exception in register or boot, an invalid
  `offer()` or CSP host: `LoadReport::fail()` withdraws everything the
  package offered and logs `Plugin {package} not loaded: {reason}` once an
  hour. Operations → Plugins (`App\Filament\Pages\Plugins`) lists every
  package with version, licence, state and reason.
  - A provider Laravel discovers itself (still in `extra.laravel`) has no
    package and is reported under its class name instead of throwing.
  - An offer made outside a plugin (a late callback) fails under
    `PluginCatalog::unowned($key)`, never under the key: the key names the
    package that owns it, and that one would be withdrawn.
  - `HouseholdExport` refuses while any plugin failed: its scrub rules left
    with it, and secrets would travel into the copy.
  - The entrypoint migrates before `optimize`: a plugin that fails while the
    route cache is built stays without routes until the next restart.
- **Every package carries its licence file.** Core and bundled plugins:
  PolyForm Shield (a byte copy of the root `LICENSE`); `fahoba/plugin-api`
  and `fahoba/example-area`: MIT, so third parties may build on and copy
  them. `PluginApiBoundaryTest` checks file against `composer.json`.
- **Installed means enabled** — for providers, widgets, photo rules and CSP
  hosts. Whoever does not want one removes the package. `offer()` only
  remembers the class — it is instantiated only when someone needs it.
  **Extensions are the exception** (`offer('extensions', …)`, contract
  `Extension`): they change the server for every household, so the
  operator switches them on under Operations → Extensions
  (`App\Filament\Pages\Extensions`, table `plugin_settings`, read once per
  request by `Extensions`). Off is the default.
- **Plugin identifiers carry a vendor** (`vendor/name`), built-in ones
  never. This way no package can claim `google` and receive its tokens.
  Duplicate identifiers, unknown areas and classes without the contract of the
  area (`ProviderContracts::BASE`, for `widgets` `DataWidget`) skip the
  package at boot (`first_party` in `config/fahoba.php` lists the packages
  allowed the `fahoba/` prefix).
- **No cage.** A plugin runs in the same process with all privileges, including
  `APP_KEY` and thus all credentials. Only packages from a trusted
  source, pin versions in `composer.lock`, run `composer audit`.
  Real isolation would only work via a separate service behind HTTP.
- **The registry instantiates only on demand and once per request**
  (`scoped`). A provider's constructor must therefore do nothing — the
  contract test checks that it touches neither database nor network.
- **Every outbound call has an upper limit**
  (`Http::globalOptions`, 20 s, connection setup 5 s). Providers' own, shorter
  limits take precedence.
- **`PluginCatalog` and the API's `Catalog` are bound via `#[Singleton]`, not in the
  `AppServiceProvider`.** Laravel registers package providers before those of the
  application; a binding there would come too late, and every `offer()` would land in
  a throwaway instance. Tests that call `offer()` only afterwards
  do not see this.
- **An extension configures the panel at boot, on or off**
  (`configurePanel()`): Filament registers its routes then, and
  `php artisan optimize` caches them at container start. Whatever it sets
  up asks `Extensions::enabled()` per request — a switch in the admin
  must not need a restart.
- **Two-factor sign-in is the extension `fahoba/two-factor`** (category
  security). It uses Filament's authenticator-app factor with recovery
  codes; the columns and Filament's interfaces sit on `User` in the core,
  because a package cannot add an interface to the model.
  - Filament asks only on its own login page. Phone sign-in, the app's
    login link and `/login-link` go through `App\Auth\SecondFactors`:
    password or link right → `202 {two_factor: ticket}` (cache, five
    minutes, five tries), `POST /api/auth/two-factor` redeems it with the
    code. `/login-link` sends such accounts to the password login instead.
  - Setting up a factor deletes the account's Sanctum tokens — phones
    signed in without it; the admin session from the app cookie ends too.
  - “Required for” (operators, or everyone with administration rights)
    sends a missing account to Filament's set-up page on every admin page
    (`RequireSecondFactor`) and refuses its phone sign-in. Only accounts
    that reach the admin can be required: the set-up lives in the profile.
    The operator cannot require it without having set it up (`refuse()`).
  - Switched off, a stored secret stays but nobody is asked
    (`SwitchedAppAuthentication`), and the profile hides the section.
- **Social sign-in is the extension `fahoba/social-login`** (category
  security), built on Laravel Socialite.
  - Providers live in the plugin's `config/social-login.php`: a Socialite
    provider class, `client_id`/`client_secret` from the server's `.env`
    (`SOCIAL_GOOGLE_*`), `verified_claim`. A provider without credentials does
    not appear; the operator cannot switch the extension on without one.
    `SocialProviders::driver()` builds from that config with
    `Socialite::buildProvider()` — `services.google` belongs to the calendar
    connection, with other scopes and another redirect.
  - **Existing accounts only.** The first sign-in matches the address only
    if the provider's `verified_claim` is `true` (anyone can claim an address
    at some providers), then stores `social_identities` (provider, subject);
    from then on the subject counts, not the address. It accepts an open
    invitation (`email_verified_at`). The table belongs to the account, so
    `HouseholdExport` lists it under `SYSTEM`.
  - `/social/{provider}/redirect|callback` are web routes, **not** behind
    `FAHOBA_ADMIN_ALLOWED_IPS`. `?to=admin` (the Filament login page's
    button, `AUTH_LOGIN_FORM_AFTER`) ends in the web session like
    `/login-link`; otherwise the app's cookie from `App\Auth\AppToken`, which
    the password login uses too. A second factor still applies: the app gets
    `/?two-factor=<ticket>`, the admin is sent to its password page. Refusals
    come back as `/?social=<reason>`; `LoginScreen` maps the reason to a
    sentence and strips it from the address.
  - Google refuses `fahoba.test` as a redirect; locally the PHPUnit tests
    (`SocialLoginTest`, Socialite mocked) stand in for it.
- **Plugin author conveniences** (guide `docs/plugins.md`):
  - `fahoba:make-plugin vendor/name --widget|--provider=<area>|--area`
    (`MakePluginCommand`, stubs in `stubs/plugin/`). A provider's methods
    are written by reflection of `ProviderContracts::BASE[area]`, so a new
    contract method shows up without touching the generator.
    `MakePluginTest` generates every kind, loads it and runs the package's
    own PHPUnit with only the app's autoloader — the generated test must
    pass outside Fahoba. The package also gets `phpunit.xml.dist`, CI,
    `AGENTS.md` and `.gitignore` (stubs `phpunit`, `ci`, `agents`,
    `gitignore`; the CI stub pins the same action SHAs as `tests.yml`).
  - **`templates/plugin` is generator output**, split to
    `fahoba-plugin-template` (GitHub template repository, no tag).
    `MakePluginTest` fails when they differ: after changing a stub, delete
    the folder and run `fahoba:make-plugin acme/example --widget
    --path=templates/plugin`.
  - **Testkit** `Fahoba\PluginApi\Testing\PluginAssertions` (trait for a
    PHPUnit `TestCase`; the API only *suggests* PHPUnit). A check that
    Fahoba enforces at runtime and an author can test alone belongs there
    (`PluginTestkitTest`).
  - `Http\Outbound` (`CoreOutbound`): `server()` retries 429/5xx and
    connection errors, `householdGet/Send()` go through `OutboundHttp`.
  - `extra.fahoba.csp` is read by `PluginManifest` and checked by
    `ContentSecurityPolicy` like `ContributesToCsp`; a bad entry fails the
    package. The manifest never casts what a package wrote: a warning there
    runs in `PluginServiceProvider::register()` and stopped every request.
    A malformed value becomes `PluginManifest::MALFORMED`, and `PluginLoader`
    fails the package. The manifest cache carries `SHAPE`: raise it when a package
    entry gains a field, or an old cache misses it.
  - Buttons: `HandlesActions`, `POST /api/wall/dashboard/{tile}/action/{name}`;
    the answer replaces this tile's cached content. `guarded()` is asked on
    the server — a device without the PIN gets 423 (`dashboard.arrange`),
    `WidgetGeneric` repeats through `withPin`; an account needs
    `household.manage` (403), or a child could run it from their own phone
    dashboard.
  - Settings: `HasSettings` + `SettingField`, checked by
    `App\Dashboard\WidgetSettings` on store and update **only when the
    request carries `settings`** (the picker adds a tile empty, moving a
    tile sends none — that is how a stored secret survives). Secrets are
    `Crypt::encryptString`, shown to devices as `true`/`false`, decrypted
    for `content()`/`act()`. `TileSettings.vue` draws the form; the tile
    reloads when its settings change. A number must be finite: `1e400`
    passes `numeric` and became INF, which JSON cannot encode.
  - Taps are throttled (`throttle:widget-action`, 30 a minute per device
    or account): every tap runs plugin code that may call a service.
  - `bootPlugin()` may be protected: `Plugin` calls it through a closure
    made inside the class.
- **Split on release** (`.github/workflows/split.yml`,
  `.github/scripts/split-packages.sh`): every `packages/*` and `plugins/*`
  goes to `<SPLIT_OWNER>/fahoba-<name>`, every `templates/*` to
  `fahoba-<name>-template` without a tag (checkout with
  `persist-credentials: false`, or its read token wins over `SPLIT_TOKEN`; variable, defaults to the repo
  owner; secret `SPLIT_TOKEN`), branch `main`, tag `v<version>` from the
  package's `composer.json` when missing there. A new plugin needs its
  repository created first, and Packagist pointed at it. Dry run:
  `SPLIT_OWNER=x SPLIT_DRY_RUN=1 .github/scripts/split-packages.sh`. Raise a
  package's `version` to publish it anew.
- **Community frontends** (design in `docs/architecture.md#designs-and-frontends`,
  guide `docs/frontends.md`):
  - **`routes/api-contract.txt` is the HTTP contract** — every core route
    under `/api` plus `/broadcasting/auth` (`App\Support\ApiContract`,
    plugins' routes left out). `ApiContractTest` fails on a missing and on
    a new route: after adding one run `php artisan fahoba:api-contract`;
    removing or renaming one waits for a major release. Realtime area names
    and the meaning of 401/402/403/404/422/423/429 belong to it too.
  - **`@fahoba/client`** (`frontend/packages/client`, MIT, no Vue or Nuxt):
    `createClient()` (fetch, `credentials: 'same-origin'`, `FahobaError`
    with `kind`), the entry calls, `connectRealtime()`, and the pure rules
    (`rules/`: task keys and ticks, day completion, theme alarm, designs,
    notices, language, announcements). The layer's `utils/<rule>.ts` are
    named re-exports — Nuxt's auto-import scanner cannot resolve
    `export *` from a package. Source imports carry `.ts`
    (`rewriteRelativeImportExtensions` builds `.js` for npm); `exports`
    points at `src/` in the workspace, `publishConfig.exports` at `dist/`.
    Published by the `npm` job in `split.yml` when its `version` is new,
    via npm's trusted publishing (OIDC, no token; the trusted publisher on
    npmjs.com names `fahoba/fahoba` and `split.yml` — renaming the
    file breaks publishing).
  - **`FAHOBA_FRONTEND`** installs a package with the plugins and
    `deploy/install-frontend.sh` points `/var/www/app` (a symlink; the
    official app is `/var/www/fahoba-app`) at its `extra.fahoba.app`
    directory after `php artisan fahoba:frontend` (`FrontendPackage`)
    checked it: `index.html` inside the package, no inline script, import
    map or foreign script — the CSP would leave it blank, so the start
    stops instead. nginx serves `/assets/` from it (prod and DDEV).
  - **`templates/frontend`** is split to `fahoba-frontend-template`:
    Vite + TypeScript, `dist/` committed (Composer installs it as it is;
    its CI fails on a stale build). Build it against the local client:
    copy it out, point `@fahoba/client` at `link:<checkout>/apps/platform/frontend/packages/client`,
    `pnpm install --ignore-workspace && pnpm build`, copy `dist/` back.
- **Community files**: `.github/ISSUE_TEMPLATE/` (bug, plugin API; blank
  issues off, questions to Discussions), `pull_request_template.md`,
  `llms.txt` at the root (links for coding assistants — keep it in step
  when guides move). The plugin directory and the “good first plugin” list
  live in `docs/plugins.md`; the API's stability promise too (“What stays
  stable”) — a change to `plugin-api` that breaks it is a major release
  from 1.0.0 on.
- **Own plugins live in `apps/platform/plugins/`**, the API in
  `apps/platform/packages/`, both as
  Composer path packages (`repositories` in `composer.json`, `symlink`). The
  Dockerfile copies `plugins/` and `packages/` before `composer install`, otherwise
  Composer does not find the package. Tests for them live in `tests/` of the application.
- **Lean by design: the image installs no plugin.** Fahoba's own are
  `require-dev` (so DDEV and CI always have them) and their sources ride
  along in the image. `FAHOBA_PLUGINS` names what to install;
  `deploy/install-plugins.sh` runs `composer require --update-no-dev` in the
  entrypoint before migrating — own plugins from the path at the image's
  version, their dependencies as the lock pins them, others from Packagist.
  - A name it cannot install stops the start (fail closed): running on
    without two-factor would quietly drop it.
  - `composer require` resolves `require-dev` too, so every dev package's
    path must stay in the image — `example-area` included, or every install
    fails with “not in remote repositories”.
  - A plugin with a frontend layer cannot arrive at runtime; the script
    refuses one that is not in `composer.built.lock`, the lock the image's
    app was built from.
  - The image has no Tinker: it is `require-dev`. Inspect a container with
    `php -r` and the app's kernel.

### Plugin areas: menu, routes, admin, events

A
plugin brings a whole area — menu entry, API, admin pages, export rules —
and switches off per household like a built-in one. Only the operator
installs plugins, at build time.

```php
$catalog->offer('areas', 'acme/board', BoardArea::class);    // Fahoba\PluginApi\Areas\Area
$catalog->offer('export', 'acme/board', BoardExport::class);  // ExportRules: systemTables(), scrubbed()
$catalog->offer('panel', 'acme/board', BoardPanel::class);    // ConfiguresPanel: resources, pages
PluginRoutes::api('acme/board', fn () => require __DIR__.'/../routes/api.php'); // /api/acme/board/…
```

- **`App\Areas\Areas` is the one list of areas**: the `Feature` cases
  first, then plugin areas by key. `Household::has()` takes a string; a key
  no installed package offers counts as off, so a removed plugin leaves
  nothing behind. `Feature` stays for the built-in ones.
- **Interfaces are opt-in** (`Fahoba\PluginApi\Areas\OptIn`): API access
  (core `ApiArea`, key `api`), ICS feed, webhooks, MCP. Off until a parent
  ticks them under Household → Interfaces; stored the other way round, in
  `households.opted_in_features`. A new household opens nothing to the
  outside, and an installed package hands out no door by itself. Anything
  that answers another program or an address without a sign-in is `OptIn`.
  `RequiresArea` names a prerequisite (MCP needs `api`). `ApiTokenScope`
  answers 404 for an API token whose household has `api` off — checked on
  the token's `household_id`, not the context — and throttles every API
  token (`PER_MINUTE`, an MCP call counts twice). Tests switch them on with
  `$this->optIn($household, …)`; e2e only for as long as it needs (the
  admin 390 px test).
- **`inMenu()` false** means switchable but without a view (webhooks, ICS,
  MCP): absent from the agenda's `areas` and `Preferences::wallModes()`.
- **`PluginRoutes::api()` carries the app's middleware list**, so a
  plugin does not copy one the core changes later; `feature:<key>` closes
  the routes with the area (404).
- **Admin pages use `BelongsToArea`** (with `NeedsHousehold`): gone from
  navigation and access when the area is off. Registered via
  `offer('panel')`, not `Panel::configureUsing` — `Panel::make` never calls
  `configure()`, so that hook never fires for the admin panel. They run
  **after `id('admin')`**: outside the console, `resources()` asks the id
  for Filament's component cache, and before it every admin page was a
  500 — PHPUnit runs in the console and never saw it (`PanelPluginsTest`
  switches the console flag off).
- **Translations load in `register()`** (`loadJsonTranslationsFrom`). In
  `boot()` the German texts are already loaded and a later path is ignored.
- **The frontend is a Nuxt layer** in `plugins/<name>/frontend` with
  `extra.fahoba.frontend` in its `composer.json`. `plugin-layers.ts`
  reads `vendor/composer/installed.json` (falling back to `composer.lock`)
  and extends the app with every installed one; a dev-only package
  (`fahoba/example-area`) is therefore missing in the production bundle.
  The view registers itself with `registerAreaView(key, component)`;
  `AppShell` draws it. `main.css` carries `@source` for
  `plugins/*/frontend`. The Dockerfile copies `composer.lock` and
  `plugins/` into the frontend stage for this.
- **Domain events are one class**, `App\Events\DomainEvent` (`name`,
  `householdId`, `payload`), dispatched from model events in
  `DomainEvents::register()`. Names are the constants (`list_item.added`,
  `task.completed`, …) — a contract other services rely on: add, never
  rename. `created` and `updated` are listened to separately, because
  `wasRecentlyCreated` stays true on the instance for every later save.
- **`OutboundHttp::send()`** for any method, without redirects; null
  means refused address or failure. Household addresses only.

### Fahoba's own plugins

None is installed in the image; `FAHOBA_PLUGINS` picks them.

| Package | What | Notes |
|---|---|---|
| core | API tokens (Integrations → API access, `App\Auth\ApiTokens`, area `api`) | `api` + `read` (+ `write`) abilities, one household. `ApiTokenScope`: safe methods need `read`, others `write`; app and device tokens pass. |
| `fahoba/openapi` | `/api/openapi.json` (Scramble) | public, throttled. `OpenApiTest` fails on an undescribed api route. |
| `fahoba/ics-feed` | secret calendar address per family or person | `/ics/{token}`, only the hash stored, −1/+12 months; all-day as DATE in the household zone. |
| `fahoba/webhooks` | signed POSTs on chosen `DomainEvent`s | `OutboundHttp::send`, `X-Fahoba-Signature: sha256=…`, 3 tries; the job catches everything (see outage rule), sync queue = last try. 50 deliveries kept per hook. |
| `fahoba/mcp` | `/mcp` for AI assistants (laravel/mcp) | API tokens only (`RequireApiToken`). Tools call the app's own API (`Fahoba\Mcp\Api`, kernel sub-request with the caller's token): permissions, PIN rules, areas and validation stay in one place. |
| `fahoba/caldav` | calendar provider, both ways | Nextcloud, iCloud, mailbox.org … via `OutboundHttp` (no home-network servers). |
| `fahoba/webdav-backup` | extension: nightly dump + uploads to WebDAV | operator's address, so plain `Http` — a NAS at home is allowed. |
| `fahoba/example-area` | dev only, the reference for a plugin area and a tile | menu entry “Beispiel”, tile “Zähler” (settings, buttons, a guarded reset), e2e `plugins.spec.ts`. |
| `fahoba/example-theme` | dev only, the reference for a design pack | “Fjord”: cool colours, rounder corners, its own glass gradient. |
| `fahoba/example-language` | dev only, the reference for a language pack | French for the first screens, English for the rest; e2e `languages.spec.ts`. |

- **An MCP route is all POST.** `ApiTokenScope` would demand `write` for
  every call; the route carries the action `checks_token_scope`
  (`ApiTokenScope::SELF`), and writing tools set `$writes` — offered only
  to a token with `write` (`shouldRegister`). The sub-request then meets
  `ApiTokenScope` again with its real method. After it the outer request is
  bound back into the container; the MCP answer belongs to it.
- **CalDAV has no common delta.** A sync reads −3/+12 months whole, but
  only when the calendar's CTag (or `sync-token`) moved — that is the
  cursor. What the window held here but not there is reported as
  cancelled. Series are expanded with sabre/vobject (server-side expansion
  is optional and missing at some servers): one row per date, id
  `href#RECURRENCE-ID`, `recurring_event_id` the href. A single date of a
  series is refused for writing (422) — rewriting the series object from a
  wall would be a guess. Plain events: `PUT` with `If-None-Match: *` or
  `If-Match`, 412 → `EventConflict`. People and our revision ride along as
  `X-FAHOBA-PEOPLE`/`X-FAHOBA-REVISION`, like Google's private
  properties. 401/403 in the background → `InvalidGrant` + `AuthorizationLost`.
- **The backup is scheduled every quarter hour, not at its time.** The time
  is a setting in the database, and the schedule is built before anything
  may read it; the command checks the time and runs once a day (cache
  `webdav-backup:done-on`, set on failure too, so a broken folder does not
  fail 90 times). The password is stored encrypted inside
  `plugin_settings.settings`; an empty field keeps it. The image ships
  `postgresql16-client` for `pg_dump` — keep it at the server's major.
  The `.env` is not backed up: whoever has the backup must not have the key.

### Test guards

- **Migrations are append-only.** `database/migrations.sha256` seals every
  migration, plugins' included; `MigrationsAppendOnlyTest` fails on a
  changed or removed one. `php artisan fahoba:seal-migrations` adds new
  ones — run it at release, after which they are history. A migration that
  must change after release is a new migration (rollout rule above).
- **The history before the first public release is one schema dump**
  (`database/schema/pgsql-schema.sql`, sealed too). A fresh database loads
  it with `psql` (DDEV, CI runner and image all carry the client), then
  runs `database/migrations` and the plugins' own. It holds only core
  tables: dump from a database migrated with
  `--path=database/migrations`, never one where plugins migrated, or a
  plugin installed later would meet its own table. Strip pg_dump's
  `\restrict` lines — their key changes on every dump, and an older `psql`
  does not know them.
- **CI** (`.github/workflows/tests.yml`): PHPUnit against PostgreSQL 16,
  Pint, Vitest, type check — not Playwright, which needs DDEV. The job
  writes a `.env` with only `APP_KEY`, like DDEV's has no `REVERB_*`: a copied
  `.env.example` leaked its Reverb values into `RealtimeTest`. Actions are
  pinned to a commit SHA with the version as a comment; update both
  together. `image.yml` reuses the tests (`workflow_call`) before it
  pushes — a release tag `v*` only, the arm64 half compiles PHP extensions
  under QEMU.
- **`queue:work` in a test needs `--memory`.** The worker stops after any
  job once the process exceeds it (128 MB by default), and the test
  process does after `OpenApiTest` — red only in a clean environment, DDEV
  stayed just below (`WebhooksTest`).
- **Accessibility** (`a11y.spec.ts`, axe): start page and every wall area,
  serious and critical rules fail. Exceptions name their reason in the
  file. A day cell that is a click target carries an inner `sr-only`
  button (`day-add`) instead of `role="button"` on a cell with buttons.

### Lists in both directions (Bring)

`fahoba/bring` talks directly to the REST interface of the Bring apps
(`plugins/bring/src/BringClient.php`, endpoints after the Python library
`bring-api`). Bring has no official interface and no OAuth:
email and password are stored encrypted in the account, the token alongside
(`access_token`, `token_expires_at`, `settings.bring`).

- **Capability `SyncsItems`** in addition to pushing: `items()` fetches open and
  ticked-off items, `markDone()`/`remove()` mirror what happens
  on the wall and phone (`MirrorListItemJob` from `WallListController`).
- **Matching is done by name**, case-insensitive. Alexa
  knows no other key. That is why a rename is mirrored as
  “remove old, create new” — otherwise the next sync would bring the old
  name back.
- **`fahoba:sync-lists` every five minutes** (`ListSyncService::pull`): What
  Bring has open is open here; what is ticked off there is ticked off here too. New items
  come only from the open list — Bring's history (“recently”) is not
  our import. What Bring does not know at all stays untouched.
- **Two minutes of grace** for freshly changed items: A tick that
  is still sitting in the job would otherwise be reverted by the sync.
- **The target list:** identifier from the selection, otherwise a list of the same
  name, otherwise Bring's default list (“Zuhause”, i.e. “Home”).
- **`fahoba:clear-done-items` clears completed items hourly** once they have
  been ticked off for twelve hours — a time span rather than “at night”, because households live in
  different time zones. Bring keeps its history; the sync
  fetches only open items.
- **Rejected login in the background → `InvalidGrant`** and
  `AuthorizationLost`; an expired token gets a new login.

### Recipes: own book, catalogs, Markdown

```
recipes                 own recipes per household (steps, servings, photo_path, source…)
catalog_recipes         server-wide mirror of a public collection (`source` = provider key)
recipe_favorites        household, provider, recipe_id
app/Support/Recipes/    Ingredient (parse, scale, format), RecipeSearch, RecipeInput
shared/app/utils/markdown.ts, TwMarkdown.vue, TwMarkdownEditor.vue
```

- **Ingredients are `{amount, amount_max, unit, name, note, group}`.**
  `Ingredient::parse()` reads typed lines ("200 g Mehl, gesiebt"),
  `fromArray()` also the old `{name, spec}`. Units are normalised for summing
  (`Esslöffel` → `EL`). The provider contract still hands out `{name, spec}`;
  structured data travels through the capability `DescribesRecipes`
  (`browse`, `recipe`, `categories`).
- **The server scales and formats amounts** (`?servings=`), never the
  device: "1 ½ TL" must read the same on wall, phone and shopping list.
  `spec(round: false)` keeps what was typed for the edit form.
- **Recipe text is Markdown, drawn without `v-html`.** `TwMarkdown` renders the
  subset of `utils/markdown.ts` with `h()`; unknown syntax and HTML stay
  text, links survive only for `https:`/`mailto:`. `steps` turns numbered
  lists into steps to tick off (display only). The admin uses Filament's
  `MarkdownEditor` with the same buttons (`RecipeForm::TOOLBAR`).
- **Search: one expression, one index.** `RecipeSearch::VECTOR` is the GIN
  index's expression; a changed expression needs a new index, otherwise the
  query scans. No generated column — `HouseholdExport` copies every column,
  and an import would fail on a generated one. Every word is a prefix
  (`word:*`), letters and digits only, plus `title ilike %term%` for compounds.
- **A provider key may carry a slash** (`fahoba/kochwiki`): the recipe-book
  routes match `{provider}` with `WallRecipeController::PROVIDER`, and the
  `builtin/{recipe}/photo` route is registered before them.
- **Recipe photos live in `recipes/` on the private disk**, redrawn by
  `PhotoImage::store(…, 'recipes')`, served by `/api/wall/recipe-book/builtin/{id}/photo`.
  A replaced or deleted recipe takes its file along (`Recipe::booted`).
  Catalog photos are not downloaded: `catalog_recipes.photo` holds the
  source's addresses, shown with `referrerpolicy="no-referrer"`.
- **A recipe may be photographed instead of typed** (`recipes.pages`, up to
  `Recipe::MAX_PAGES`, `POST|DELETE /api/wall/recipe-book/builtin/{id}/pages`).
  Only the title is required; the detail then shows the pictures and leaves
  out the empty ingredient list. Pages are addressed by their file name, not
  their position, and leave with the row like the photo. The collection
  export does not carry them yet.
- **“Selbst erstellt” is `?list=own`**: the built-in book alone, with the
  usual filters — catalogs and connected sources stay out.
- **`recipes.edit`** is not in the wall's defaults: writing a recipe on the
  wall asks for the PIN. The buttons ask `useActor().can()`, never the
  response's `can.edit` (an account's view): a tablet offers them. Forms stay
  at `z-40` — `PinDialog` is `z-50` and must open above them.

### Meal plan: a calendar week, the meals the household cooks

- **The plan pages by calendar weeks**, Monday to Sunday (`utils/mealWeek.ts`,
  plain dates in UTC like `isoWeek`). `MealsView` loads exactly the week on
  screen; the household's today is passed in as `today`, also to
  `RecipesView`.
- **`households.meal_kinds`** (Household → Meals, `CheckboxList`, at least
  one): `null` means lunch and dinner, so a household that never chose keeps
  its plan. `Household::mealKinds()` returns the enum cases in the day's
  order; `/api/wall/meals` delivers them as `kinds`, and `store` accepts only
  those — an old tablet cannot plan a lunch nobody cooks. A kind switched off
  keeps its planned entries visible (and removable), it only offers no “+”.
- **A planned recipe opens from the plan** (picture `meal-photo` or
  `meal-recipe`, “Anleitung”). `MealsView` lives in the layer and only emits
  `recipe`; `PlannedRecipe` in the app shows `RecipeDetail` with `planned`
  (no plan or edit button), and only there the ingredients go to the list.
  At the planned portions it uses the meal's own path (exact eaters'
  portions), after changing the portions the recipe's. The wall counts it as
  an open dialog (`cooking` in `rest()`), so it does not rest while someone
  cooks. `/api/wall/meals` carries `photo` from `RecipeBook::cards()` — one
  lookup per source, and an outage costs the pictures, never the plan.
- **Planning from the recipe** (`recipe-plan` in `RecipeDetail`, behind
  `meals.edit`) opens `MealSlotPicker`: a week from the current one onwards,
  past days left out, one button per kind showing what is planned there
  already. It fetches its week itself instead of `useMeals().load()`, whose
  state belongs to the meal plan and the dashboard tile; the entry is the
  same `POST /api/wall/meals` the plan makes.

### Portions: computed from who eats

- **`PortionFactor`** relates a person to an adult man (1.0) by the DGE/ÖGE
  reference energy at PAL 1.4, per age band and `people.gender`. Without a
  gender the mean of both, without a birthday the adult mean. The age is
  taken on the day of the meal.
- **`meal_plan_entries.eaters`**: person ids, `null` = everyone. Choosing all
  people stores `null`, so a person added later eats along. The entry
  carries `servings` (sum of factors, one decimal, at least 1); `/api/wall/meals`
  also returns `people` for the chooser.
- **Recipes are scaled on the server** by `servings / recipe.servings` —
  nobody enters portions by hand.

### Recipe book: favourites, history, ideas

`App\Services\Recipes\RecipeBook` knows what the household itself knows,
across every source: a recipe is addressed as `provider:id`, like a planned
meal.

- **Favourites are per household** (`recipe_favorites`), set by anyone without
  a permission or PIN — liking a recipe changes nothing anyone relies on. The
  row keeps the title, because a source without `DescribesRecipes` cannot look
  a recipe up by id.
- **“Oft gekocht” and “Lange nicht mehr” are derived from the meal plan**
  (`meal_plan_entries` up to today): at least two times, and nothing for
  `FORGOTTEN_DAYS`. `?list=` on `/api/wall/recipe-book`; the filters then
  narrow what the cards know, in PHP — the list is short.
- **Suggestions draw by weight, not by score**: favourite 3, often +2, a
  seeded random page of each source 1; anything planned within
  `RECENT_DAYS` before or after today is out. The same `seed` draws the same
  (`md5(id || seed)` in `RecipeSearch`, `Mt19937` in PHP) — “Andere” sends a
  new one. Shown only on the plain book: whoever searches has a plan.

### From recipe to shopping list

`App\Services\Recipes\RecipeShopping` is the one way recipes reach the
list — recipe book (`servings` from the detail), planned meal (its eaters'
portions) and `POST /api/wall/meals/shopping` (`from`/`until`, at most a
month; the meals view sends what is still ahead in the week on screen).

- **Summed by name and unit, one line per name.** “500 g Mehl” and
  “2 EL Mehl” become “500 g + 2 EL”; two lines would make
  `IngredientTransfer` drop the second as already on the list.
- **Pantry staples** (`households.pantry_staples`, Household → Always at
  home) never go on the list. `null` means the defaults in the household's
  language (`Household::defaultStaples()`); the form shows them filled, so
  saving stores them. A staple also covers what ends in it — “Öl” covers
  “Olivenöl”; German compounds put the kind last.
- **Only staples is not an error:** `added: 0`, “already there”.
- **A source without `DescribesRecipes` does not say its servings** — its
  amounts are parsed but never scaled.
- **The open recipe keeps the screen on** (Wake Lock in `RecipeDetail`);
  steps tick off via `TwMarkdown steps`.

### Recipes in and out: links and collections

`App\Services\Recipes\RecipeImport`; admin: Recipes → Collection (from a
link, import, export); wall and phone: the editor of a new recipe
(`POST /api/wall/recipe-book/link`, `recipes.edit`, `throttle:recipe-link`).

- **A link is read as schema.org JSON-LD** (`App\Support\Recipes\LinkedRecipe`),
  through `OutboundHttp` — page at most 2 MB, photo at most 15 MB, both
  checked against private addresses. HTML in the fields is stripped;
  `HowToSection`s become `###` headings over numbered steps. Page and author
  land in `source_url`/`license`, so the credit line shows where it came
  from. The recipe is saved at once and opened; correcting happens in the
  editor, not in a preview.
- **A collection is `recipes.json` plus `photos/<id>.webp` in a ZIP**,
  `format: fahoba-recipes`, `version` (`RecipeImport::VERSION`). Raise the
  version only for a change an older import would misread; an import refuses
  a newer version. Only the photo names the export writes are read from the
  archive.
- **Importing passes `RecipeInput::rules()`**, a recipe that fails is
  skipped, not the file. A title the book already has (case-insensitive) is
  skipped too — the same file twice changes nothing. Photos are redrawn
  (`PhotoImage`); one that is no picture costs the photo, not the recipe.
- **Large collections via the command**: `fahoba:export-recipes <household>
  <file>`, `fahoba:import-recipes <household> <file>`. The admin upload is
  bound by Livewire's temporary upload limit.

### Koch-Wiki (`fahoba/kochwiki`)

```
plugins/kochwiki/src/KochwikiClient.php     MediaWiki API, one request a second
plugins/kochwiki/src/Wikitext.php           {{Rezept}}, Zutaten, Zubereitung → columns + Markdown
plugins/kochwiki/src/KochwikiSync.php       catalog_recipes, only changed revisions
tests/Fixtures/kochwiki/                    real pages: carbonara, Sachertorte, main page
```

- **Mirrored once per server, connected per household.** The provider has
  `UsesCredentials` with no fields (the contract test allows that only when
  `verifyCredentials([])` passes): the wizard's last step is empty, and the
  account row is what makes it `isConfigured()` for that household.
- **The schedule runs daily but syncs rarely**: only when some household is
  connected and the mirror is empty or 28 days old (`kochwiki:synced_at` in
  the cache). `--force` syncs regardless. A volunteer wiki is not asked
  anything on a server that does not use it.
- **Be polite to the wiki:** one request at a time with `Sleep` in between,
  `maxlag=5` with retries, a User-Agent with `APP_URL` and the mail sender.
  `Sleep::fake()` in tests. Pages without `{{Rezept}}` are remembered with
  their revision (`kochwiki:not_recipes`) so they are not fetched again.
- **Steps are bullets on Koch-Wiki** and become a numbered list — only those
  can be ticked off. Templates other than `{{Grad}}` are dropped, files,
  galleries, tables and references removed, external links kept only for
  `https`. Vegetarian is `true` from a category, otherwise `null` (unknown,
  not "with meat").
- **Photos stay on kochwiki.org** (`KochwikiImages` adds it to `img-src`);
  400 px thumbnails for cards, 800 px by replacing the width in the URL.
  Author and licence come from the file page's `{{Information}}` and are
  shown with the recipe (`license.photo_*`) — CC BY-SA requires it.
- **The category filter shows the 40 most used** Koch-Wiki categories; the
  wiki has thousands.


### Pitfalls

- **Provider knowledge belongs in the provider.** Translation happens there: A cancellation
  is reported by Google as `status: cancelled`, by CalDAV with `404` — the sync sees
  only `RemoteEvent::cancelled()`.
- **`personIds: null` means “no information”**, not “nobody”. The sync
  then leaves an existing assignment untouched, so that an event created by hand
  does not lose it.
- **`revision_marker` is the echo protection.** Without it every own
  write triggers another one via the notification.
- **`calendars.is_active` is called “Show” („Anzeigen“) in the admin.** Only
  displayed calendars are available for selection by accounts and walls. Every account (app,
  Settings → My calendars) and every device (device form) hides
  individual ones below that: `hidden_calendar_ids` on `users`/`devices`, stored as an
  exception, so that a newly displayed calendar appears
  everywhere. The agenda omits hidden calendars along with their events.
- **The calendar colour belongs to the household.** The sync takes over the
  Google colour only on first creation (`CalendarImportService`).
- **Column names are neutral** (`external_id`, `sync_cursor`, `push_*`,
  `external_version`). Google's own `etag` in the JSON is its protocol and
  keeps that name.
- **No contract without an implementation.** A task provider (Microsoft To Do)
  gets an interface only once there is something against which to check
  whether it is right.
- **Never `include_granted_scopes`.** Otherwise a token for the
  task lists would also carry the calendar permissions, and the separation of
  connections would exist only in the display.
- **The area travels along in the session.** Without this check a
  callback from the calendar path could be bent into the list path, and a
  calendar token would end up in the table as a list connection.
- **An install band asks until the app is installed** (`InstallOffer`,
  `useInstallOffer`, pure rules in `utils/installOffer.ts`). Chromium's
  `beforeinstallprompt` fires once and before the app has mounted — the
  layer plugin `install.client.ts` holds it back for the button. iOS has no
  prompt, so the band explains Safari's share menu; other browsers (Firefox)
  get no band. Never shown when running installed (`display-mode`
  standalone/fullscreen, `navigator.standalone`) or in Fully. “Later” stores
  a timestamp in `localStorage` (`fahoba-install-snoozed`) for seven days —
  a per-device convenience. On the phone it waits for the push sheet; on
  the wall it hides at rest. Chrome only reports installability with PNG
  icons, which is why the wall's manifest lists `icon-192.png`/`icon-512.png`.
  e2e dispatches the event after the app has started: Nuxt mounts after
  `load`, an earlier fake event goes unheard.
- **Push is offered once per phone after sign-in** (`PushOffer`, `usePush().offered`):
  only when push is configured, the browser has no subscription and
  `Notification.permission` is still `default`. The answer is remembered in
  `localStorage` (`fahoba-push-asked`) — a per-phone convenience, not state.
  `requestPermission()` needs a tap, so the offer is a sheet, never a call on start.
- **Switching push runs behind `busy` and ends in `status`** (`usePush().run`),
  shared by offer and settings; errors land in `status`, never as unhandled
  rejections. Only `register(subscription, confirm: true)` asks the server for
  a sample (`ConfirmPushJob`). `TwSpinner` keeps turning with animations off —
  its override sits in `@layer base`, because important declarations of an
  earlier layer beat any later one.
- **The settings are one overview plus topic pages for both** (`SettingsSheet`,
  `data-testid="settings-sheet"`, pages `settings-page` with `data-value`
  `appearance`/`language`/`start`/`views`/`calendars`/`rest`). The sign-in
  decides the topics: `language`, `calendars`, push and `sign-out` for an
  account, `rest` and `unpair` for a tablet. e2e opens them with
  `deviceSettings(page, topic)` in `wall.spec.ts` and `openSettings(page,
  topic)`/`closeSettings(page)` in `mobile.spec.ts`. The page state lives
  in the component and has its own `useEscape`, so Escape leads back before
  it closes. Switches and chips are `data-testid="preference"` with
  `data-cell`; switches carry `role="switch"` + `aria-checked`, chips
  (`ChoiceChips`) `aria-pressed`.
- **The phone re-registers its push subscription on every start**
  (`usePush().check()`). The browser keeps a subscription the server has
  long dropped (expired endpoint → columns cleared); judged by the browser
  alone the button says “on” while nothing arrives. `SendPushNotificationJob`
  logs “Notification reached no phone.” when nothing was delivered.
- **e2e delivers push through the DevTools protocol**
  (`ServiceWorker.deliverPushMessage` in `pwa.spec.ts`), no push service or
  VAPID keys. Headless Chrome now and then drops a notification when several
  pushes follow each other quickly — one push per test, plus at most a
  follow-up after the first has shown.
- **Push (`WebPushService`) deliberately has no provider contract** — there is
  only one implementation, and a contract without a second is guessed, not verified.
- **Holidays and school holidays are provider areas.** `HolidayService`
  keeps cache and period and asks `holidaySource($region)` —
  the first source that knows the region, plugins first. Holiday suggestions
  collect **all** `school_holidays` sources: built-in ones without an account, connected ones
  with their account; identical periods appear once.
- **The ICS holiday calendar reads only `http(s)`**, at most 2 MB, cached for a day
  and only when someone views suggestions. The address
  is set by a parent — a child or device cannot get at the wizard.
- **Addresses that a household enters go through `App\Support\OutboundHttp`.**
  The server is publicly reachable; without a check it would query
  `127.0.0.1`, the internal network or metadata services for any parent.
  `OutboundHttp` resolves the name once, rejects private addresses (`IpUtils::isPrivateIp`,
  incl. CGNAT) and pins the connection via `CURLOPT_RESOLVE` to exactly that
  address — otherwise a second DNS answer would win. It follows redirects
  itself, at most three, each one checked. Tests set `Tests\Support\FakeResolver`.
- **The address search (`Geocoder`) queries only while someone is typing.** In the
  household form, “Find address” sits above the address fields; it queries
  from three characters onwards and only 700 ms after the last keystroke, because
  Nominatim allows one request per second. Nothing runs in the background.
- **The household's address is stored in parts** (`street`, `postal_code`,
  `city`, `country_code`, all required in the form) plus the one-line
  `address` for tables. A suggestion fills the parts; each part stays
  editable, and a change looks the place up again via `Geocoder::locate()`
  (Nominatim's structured search) — first with the street, then without,
  so a house missing from the map keeps its village's location. The whole
  find travels in the suggestion's key as JSON, because Filament passes
  only the key to the listener.
- **Coordinates are rounded to two decimals (about a kilometre), not one.**
  A tenth of a degree is up to eleven kilometres — another valley's
  weather. The address is stored anyway, so coarser would hide nothing.
- **Gamification is display, not a second source of truth.** Tasks and
  rewards carry an emoji (`tasks.icon`, `rewards.icon`), because a
  toothbrush icon is there faster than “Zähne putzen” (“brush teeth”) — for children who
  cannot read yet. The daily wrap-up (“All done!” („Geschafft!“) including confetti) computes
  nothing but reads off what is there anyway: `dayCompletion.ts` in
  `shared` answers whether a day is complete and whether it has just now
  become so. Only the transition is celebrated — otherwise confetti would fall on every
  reload.
- **Animations are switchable per tablet** (`animations` preference). `app.vue`
  mirrors it to `<html data-motion>`, and one rule in `fahoba.css` stills
  every animation and transition — no component checks it itself. Use the
  `tw-pop`/`tw-tada`/`tw-fade-down`/`tw-press` utilities rather than new
  keyframes per component. **No endless loops on big emojis:** they trembled
  on the Android wall tablet even on their own compositor layer. The face of
  a finished day (`CelebrationFace`) jumps in once (`tw-tada`) and then
  stands still — and stays: with done tasks hidden it is all the column
  shows. The praise (`celebration().praise`, `celebrate.praise.*`) stays too.
- **Wall and phone share the look helpers** in `shared/app/utils`:
  `areaEmoji`, `celebration`, `points` (`share`, `affordable`, reward DTOs).
  The layer never imports from `app/`.
- **The device's `animations` switch decides, not the system.** Kiosk
  tablets often have Android's animations off for speed, and
  `prefers-reduced-motion` then silently took the tick's stars while the
  word card still showed. `utils/motion.ts` (`isMotionStill`) and the CSS
  follow the system only until `<html data-motion>` is set (sign-in,
  pairing). With the switch off `TwConfetti` and `TwCheer` draw nothing —
  for some people such a thing is not fun but nausea.
- **Tasks are also created on the device, not only in the admin.**
  `POST/PATCH/DELETE /api/wall/tasks` on wall and phone. `TaskDialog` lives
  in `shared` and receives everything via props — the two UIs
  fetch and save differently, the form is the same. It is
  deliberately smaller than the one in the admin: title, icon, coins, who,
  and tapped weekdays become `FREQ=WEEKLY;BYDAY=…`. Folded under “Mehr
  Optionen”: times of day and taking turns (the tap order of the people is
  the turn order, `rotation`). Monthly rules, intervals and notes stay in
  Filament. The occurrence carries the rule's `weekdays` and `due_on` for
  the form; `weekdays: null` means the form cannot express the rule, sends
  none, and the server keeps rule and start. `dayparts` and `rotation` left
  out of a request also stay — an older form cannot clear them. The
  weekdays and the times of day come from the response of
  `GET /api/wall/tasks` (`options`), so that they are not maintained in two
  places. Icons come from the emoji catalog (see “Icons are Fluent Emoji”);
  without a search, icons matching the title come first
  (`utils/iconChoices.ts`), and all of them show at once.
- **The parent PIN applies on the wall, not on the phone.** It replaces exactly
  what the wall lacks: a login, and gives the device all permissions for a few minutes.
  On the phone the person's permissions count — see PERMISSIONS.
  **Every parent has their own** (`users.pin_hash`), set in the
  profile (`App\Filament\Pages\Auth\EditProfile`, the field never comes back
  filled). `ParentPin::parents()` counts only accounts with `household.manage`.
  Without a PIN everything is allowed on the wall — hints such as “with parent PIN”
  should therefore be shown only when `pinRequired`. In tests use `$this->parentWithPin()`.
- **Timetable only for children:** selection lists via `Person::children()`,
  the bell schedule in the person form only for the role child.
- **Always order people via `Person::ordered()`**, never `orderBy('name')`: children
  by age, the oldest first, then adults by `position` (draggable in
  Filament). Wherever people appear side by side, the family expects the same
  order everywhere.
- **The wall creates, changes and removes tasks behind the parent PIN.** The
  PIN belongs to one parent's account, so this is no less accountable than
  the phone; removing retires (history and stars stay). Tapping a task opens
  `TaskDetails`, whose button ticks off or takes back. e2e tasks a run
  leaves behind are cleaned up by the teardown (`E2E %`).
- **The day bonus is settled, never accumulated** (`DayBonus::settle`,
  `households.day_bonus`, `point_entries.bonus_on`). After every tick and
  undo it checks the occurrence owner's day — whose day it is, not who
  ticked it — and books or removes the one entry. Only own occurrences
  count; none means no bonus. The column's “+n” keys it by `bonus_on`, not
  by `created_at`. Tick and undo answer with `day_bonus` (`{person_id,
  points}`, negative when taken back, null when unchanged): `useTasks` sets
  it in `bonus` at once — the row with `day_bonus: true`, replaced, never
  added (`withDayBonus`/`keepDayBonus`, kept over loads like ticks) and fires the big cheer (`useCheer().dayBonus`,
  fireworks plus confetti in `TwCheer`). Undo therefore answers 200, not 204.
- **An account with `points.award` redeems at once.** `request` approves
  straight away for it (an open request of the child included, no push);
  `PointsView` arms the card first (`useTwoStep`). The wall keeps request
  and approval.
- **Only the admin changes `point_entries`** (star history). Extra stars
  are a separate row with a required reason (`BonusDialog`, ⭐+ per child:
  wall after the parent PIN, phone with `points.award`), so that a child can
  trace what happened when. There are no reasonless +1/−1 buttons anymore.
  The same dialog sits on each child's column in the tasks' day view
  (`column-bonus`, `TasksView` prop `points` = area on); there the PIN comes
  on saving, via `useActor().write`, and the typed reason survives it.
- **A gift is two rows with one `gift_id`** (`PointLedger::gift`,
  `POST /api/wall/points/gift`, `points.award`, the giver locked and never
  below zero). Anything that counts what was *earned* leaves `gift_id` out
  (`Gamification::pointsBetween`/`collected`, `WallTaskController::bonus`);
  the balance counts it. Deleting one row deletes its partner
  (`PointEntry::booted`); the admin hides Edit for them. The ⭐+ dialog
  offers it only where the caller passes `givers` with their balances —
  the stars area, not the task columns.
- **A task with history is soft-deleted, never hard-deleted.** The credit
  hangs on the completion. On the device and in Filament (single and
  bulk delete) `Task::retire()` therefore runs: with completions it sets
  `deleted_at` (gone everywhere, restorable via the admin's trash filter),
  without them `forceDelete()`. `active = false` is pausing, not removing —
  a paused task stays in the admin list. `TaskCompletion::task()` is
  `withTrashed()` so the history keeps the title, and the import skips a
  trashed task instead of reviving it. The database additionally enforces this
  — `task_completions.task_id` is `NO ACTION`, not `RESTRICT`, so that
  deleting an entire household still goes through in one statement.
- **Every PIN-protected action remembers itself.** `useTasks` wraps its
  writes in `withPin` (`usePinGate`): on 423 the wall's PIN dialog opens and
  the call repeats after unlocking. The form closes only once the call went
  through — so nobody taps the same button twice. Only a 423 asks for the
  PIN: `usePoints().pinRequired` merely says the household has one, `locked`
  says the last write failed on it. Opening the PIN on any failure hid a
  refused reward request behind a PIN prompt the children could not answer.
- **A wall tablet pairs via code or QR.** The window in the
  device admin shows both: the code to type in and a QR that points to
  the wall and appends the code. The wall takes it from the address
  (`codeFromUrl`) and strips it there immediately — a code is valid once,
  and a reload would otherwise try a used one.
- **The time zone is not chosen but follows the location.** Nominatim delivers
  the country code, `TimezoneAtLocation` turns it into the zone: PHP ships the
  time zone database. Where a country has several zones, the
  nearest wins — time zone boundaries mostly follow country borders anyway.
  A separate time zone field therefore no longer exists.

### Tasks: imported and enriched

A foreign task is an ordinary `tasks` row with an origin
(`provider`, `task_account_id`, `external_id`, `external_version`).

| Belongs to the provider | Belongs to Fahoba |
|---|---|
| `title`, `notes`, `due_on` | `points`, `person_id`, `rotation`, `rrule`, `position`, `active` |

**The sync never touches the right-hand column.** That is the enrichment the
household has layered on top — overwriting it would mean resetting it after every
round. That is why `TaskImportService` writes only the left column, using `forceFill`.

- **`external_version` saves write load.** Unchanged means untouched.
- **Ticked off outside counts here, but without points.** To whom they would be credited
  the third-party service does not know; guessing would be worse than booking
  nothing.
- **Only the completion travels outwards** (`WritesTasks`), never the task
  itself: A copy at the third-party service would be a second source of truth about what
  is still open.
- **Rotation and points are irreplaceable.** No service knows them — precisely
  why a full replacement of the tasks area is no option but a
  loss.

### Tasks on the device: two views, one rule

The wall shows tasks as a **list** (all days of the period) or as a
**day view** (one column per person, `utils/dayColumns.ts`). The
setting is in the device preferences (`task_view`), the default is **day**.

- **No checkbox: tap, then confirm.** A task row is one button that opens
  `TaskDetails`; its “Erledigt”/“Zurücknehmen” is the only way to tick. A
  done task shows `done-mark` — an icon, never a control — so a brush in
  passing on the hallway wall neither ticks nor loses stars. e2e finds open
  rows via `hasNot: done-mark`, column rows by `aria-label` (“title” or
  “title, erledigt”). The button is `disabled` for 250 ms after opening, so
  a double tap on the row cannot tick through the dialog; disabled rather
  than ignored, because Playwright then waits instead of clicking into
  nothing.
- **Done tasks are hidden by default** (`task_done` preference, toggled by
  `show-done` in both views). The empty-column face still keys on *all*
  entries, so a column whose tasks are all done shows the praise, not
  “nothing to do”. e2e tests that follow a done row switch it on first.
- **Column stars are what was booked, not what the tasks are worth.** The
  occurrence carries `done_by_id` and `earned` (the `point_entries` row of
  its completion); the header shows the column's own stars plus, as “+n”,
  what that person earned in other columns (“Für alle”) and their extra
  stars of that day — so it matches the balance and the history. Extra
  stars come with `GET /api/wall/tasks` as `bonus` (person, household day,
  sum): `point_entries` without completion or redemption. `created_at` has
  no zone and holds UTC, so the range is compared in UTC; spending on a
  reward is not counted as earned.
- **Confirm first, then ask.** Whoever confirms has done the task — the
  question “who was that?” („wer war das?“) comes afterwards (`CompletionDialog.vue`). If a
  responsible person is set, the answer is one tap (“Did Lena do that?” („Hat das Lena gemacht?“));
  otherwise the list of names appears right away. “Tick off without a name” („Ohne Namen abhaken“) remains possible
  and earns no stars. In a person column the question is omitted: The
  column already names the person. The column **“For everyone” („Für alle“)** belongs to nobody
  and therefore asks like the list.
- **A past day is read only on the wall, an account catches up.**
  `WallTaskCompletionController::ensureOpen()` refuses a device's ticks and
  undos before the household's today (422) — no PIN way around it; an
  account (`User`) may tick and undo back to `HISTORY_DAYS` (28), with stars
  and day bonus booked for that day. The day view pages back as far and
  fetches a past day on its own, never through `useTasks().load()` — that
  range is today onwards and every sync resets it; after a late tick
  `TasksView` fetches the day again. PHPUnit tests that tick a fixed date
  `travelTo()` that day first.
- **An account ticks for whose task it is, not for itself.** `TasksView`
  sends the column's person, otherwise `for_person_id ?? person_id`, and the
  actor's person only for a task for nobody — a parent catching up on a
  child's task means the child's stars.
- **A load never undoes a tick of this device.** `useTasks` keeps its ticks
  until a load that started after the server's confirmation
  (`keepTicks` in `utils/task.ts`, a step counter, not a clock) and drops
  answers of older loads. Otherwise ticking in a row let the first tick's
  realtime reload show a later one open again. Before the request and after
  the response, write to the row found by `occurrenceKey`, not to the tapped
  object: a load may have replaced it — the previous tick's held reload
  often lands while the next task's dialog is open, and the tick then
  showed only with the reload after it.
- **Undoing never needs a name.** It deletes a completion instead of
  crediting anyone — and its stars go with it: `point_entries.task_completion_id`
  cascades on delete (`WallPointsTest::test_undoing_takes_the_stars_back`).
  `TaskDetails` says so before the tap.
- **The day view is built twice over: wall and phone.** The wall fits the
  screen (`h-full`, columns scroll inside, “Für alle” a strip below). Below
  `sm` the page scrolls instead, “Für alle” is the last swipe column, and
  the toolbar is one row: `< day >`, filter, `+`. View, done tasks and
  people sit in a sheet behind the filter (`task-filter-sheet`); a dot on
  the button (`data-active`) says something is filtered. Structure, not
  only classes, differs — so `useNarrow()` decides with `v-if`; hidden CSS
  duplicates would leave two of every test id in the page. Fitted to the
  screen on a phone, the old toolbar left the columns some 40 pixels.
- **The column stays even if nobody has anything to do.** A
  vanishing column would read like an error; “🌟 Super!” appears only with
  at least one task, all of which are done.
- **On the phone the PIN is omitted:** Someone is logged in there, and their
  permissions apply (`tasks.complete_own` or `_all`). The wall keeps asking.
- **Wall and phone share the views** of tasks, lists and meals
  (`TasksView`, `ListsBoard`, `MealsView` in `shared`). They ask
  `useActor()` (`kind`, `personId`, `can`, `write`), never “wall or phone?”:
  a device may try everything (`actorCan` — the PIN decides on the server),
  an account only what its `permissions` hold. The wall sets the actor from
  its agenda, the phone from `useAuth`'s user. An account checks off
  without “who was that?”; `can.complete` hides the button in
  `TaskDetails`. Test ids are therefore the same on both. The same holds in
  `packages/app` for `EventDialog` and `RecipesView`: the phone calendar
  opens the wall's dialog (edit only where the event's `can.edit` says so),
  and the recipe tab is the wall's view, a grid from `sm`, a list with a
  bottom sheet below. Below `sm` only — the tablet layout stays as it was.
- **One app, one shell** (`AppShell`, design in
  `docs/architecture.md#one-app-two-ways-in`). `app.vue`
  asks `GET /api/session` once (`useEntry`, pure rules in `utils/entry.ts`):
  a device gets `AppShell` at once, an account after `useAuth().check()`,
  nobody the start page. A link already decides (`?code=` → device;
  `?login=`, `?reset=`, `?invite=` → account); 401 means nobody, which is
  the `PairingScreen` — the start page, whose “Am Handy anmelden” switches
  to `LoginScreen` and back. Without a network the last answer
  (`localStorage` `fahoba-entry`, a convenience, not a token) holds, so a
  kiosk comes up as a kiosk. `<html data-entry>` carries the entry;
  kiosk-only CSS hangs on it.
- **Whether something appears follows the sign-in, how it is laid out the
  width — never mixed.** `device` (from `useEntry`) and `can()` decide what
  is offered: PIN, resting mode, unpairing only for a device; push, language,
  “Meine Kalender”, signing out, the admin link only for an account.
  `useNarrow()` (below `sm`) decides the layout: the bar becomes a menu
  sliding in from the left (`data-testid="menu"`, entries `tab`), the header
  one row (`menu-open`, title, refresh), settings a bottom sheet.
  With `phone_menu: bottom` the areas also sit in a row at the bottom
  (`bottom-menu`, entries `bottom-tab`); the drawer stays for settings. A tablet
  on a phone-sized screen gets the phone layout and keeps its PIN; an
  account on a laptop gets the bar. `responsive.spec.ts` runs exactly these
  two crossings (projects `narrow-device`, `wide-account`).
- **One state per area for both.** `useAgenda`, `useLists`, `usePoints` (with
  rewards) serve device and account alike; there is no phone copy any more.
  `useAgenda` sets a device's actor only when the agenda says
  `viewer: device` — an account's actor comes from its sign-in, with its
  permissions. A 401 for an account clears `useAuth().user` and so leads
  back to the sign-in. `GET /api/wall/pin` answers an account with
  `required: false`: the PIN stands in for a sign-in, an account has one.
- **An account remembers its area for the session** (`sessionStorage`
  `fahoba-tab`), a device never: after a power cut the wall shows its start
  area. e2e waits for the menu's slide via `getAnimations()` before tapping
  (`menu()` in `mobile.spec.ts`) — mid-slide taps land on moving elements.
- **An account reads an event before changing it** (`EventSheet`, edit
  only with `event.can.edit`); a device opens `EventDialog` at once and
  the PIN guards it. The calendar's toolbar is one component
  (`CalendarToolbar`, `compact` below `sm`): there one row like the
  tasks' — paging, `calendar-filter`, `+` — with view, school band and the
  people (slot) in `calendar-filter-sheet`.
- **Photos: the frame is the area, the library a task behind it.**
  `photos-manage` (with `photos.upload`) opens `PhotoManager`/`PhotoLibrary`
  full screen over the frame, on a tablet too.
- **Stars are drawn by `TwStar`, never as ⭐ emoji** — the device's
  `star_filled` and `star_color` decide outline or filled, plain or gold
  (`--star` token). The area's menu emoji (`areaEmoji`) is the exception.
- **Stars are just the language.** Tables and fields are still called
  `point_entries`, `points`, `weekly_goal` — what was renamed is what the household
  reads, a ⭐ is shown instead of a number with “P”.
- **Removing means `Task::retire()`.** Soft delete with history, never
  `forceDelete()`; the database refuses hard deletion of a task with
  completions.
- **“Everyone” is not “each person”.** `person_id = null` means: one
  occurrence that anybody ticks off. `tasks.per_person` means: one occurrence
  per person (`per_person_ids`, empty = all children, including ones created later),
  each ticks off separately and earns their own stars. Rotation and responsible person
  then do not apply.
  The device dialog sends `person_ids`: two or more make the task
  per person, one is an ordinary task. Choosing every child on an existing
  per-person task keeps `per_person_ids` empty, so later children still join.
- **`tasks.skip_holidays` pauses a repeating task in `school_closures`.**
  A closure with `person_id` null pauses it for everyone, one with a person
  only that child's occurrence. Evaluated in `TaskOccurrenceService`, never
  in the frontend.
- **Dayparts multiply the occurrence** (`tasks.dayparts`, enum
  `Daypart`): “Zähne putzen” (“brush teeth”) morning and evening is one task, to be ticked off twice
  a day. Empty means “sometime during the day”.
- **An occurrence is task + day + `for_person_id` + `daypart`.** The
  completion carries both fields, unique via an index with `NULLS NOT
  DISTINCT` — otherwise an ordinary task would again have two completions
  per day. The controller accepts only what the task really has.
  In the frontend therefore use `occurrenceKey()`/`occurrenceSlot()` from
  `shared/utils/task.ts`, never `task_id-date` as a key.
- **Icon picker** (`filament.forms.icon-picker`): the same catalog as the
  devices, fetched by Alpine; any other emoji can still be typed in.
- **Pickers draw text, displays draw pictures.** Admin picker and
  `TaskDialog` show all ~1,600 icons at once as plain emoji in the system
  font; 1,600 SVG requests kept a tablet busy for seconds. Only showing a
  chosen icon (`TwEmoji`) loads its SVG.
- **Icons are Fluent Emoji** (Microsoft, MIT), drawn by `TwEmoji` from
  `public/emoji/flat|mono/<hex>.svg` — coloured or grey by the device's
  `colored_emoji` (default on). `colored_icons` is a separate switch: emoji
  instead of line icons in the menu, always coloured (`<TwEmoji colored>`;
  without the prop the preference decides — Vue keeps an absent boolean
  `undefined` only because the default is set to `undefined`). The stored value stays the plain emoji; the file name is
  its code points without FE0F (`utils/emoji.ts`), and every comparison goes
  through it, because stored icons come with and without the selector.
  What the set lacks (Fluent has no family sequences) falls back to the
  system's emoji on the 404. Grey is High Contrast as `<img>` with
  `dark:invert`, not a CSS mask: a mask would fill its white cut-outs.
  `public/emoji` is generated, never edited: `frontend/scripts/emoji` is a
  package outside the pnpm workspace (svgo and emojibase-data there would
  reshuffle the app's lockfile); `node build.mjs <fluentui-emoji checkout>`
  writes the SVGs and `catalog.json` with German and English CLDR words.
  `HouseholdStarterTest` fails on a starter icon without a picture.

### Device preferences: what a tablet configures for itself

`GET|PUT /api/preferences` — the same route for wall and phone, stored
on the authenticated model: `devices.preferences` on the wall,
`users.preferences` on the phone. The allowed keys and values live in
**one** place, `App\Support\Preferences::allowed()`; the first value per
key is the default. The values for `start_area` come from
`Feature::wallMode()`.

- **One key per meaning for tablet and account**: `start_area`,
  `area_order`, `hidden_areas`, `calendar_view` (`three`, `day`, `week`,
  `month`, `list`), `task_view` (`day`, `list`), `school_band` (`off`,
  `on`).
- **An account's default may differ** (`Preferences::USER_DEFAULTS`):
  `calendar_view` is `day` for an account, `three` for a tablet.
  `merged($stored, account: true)` applies it.
- **The width may not show what is stored.** `utils/calendarView.ts`
  `shownView()` draws `day` where a week or month does not fit (below
  `sm`: day, three days, list) — without changing the preference, so the
  same account keeps its week on the laptop. Narrow, the day view is one
  column, not one per person.
- **`screensaver_*` belong to a tablet** (`Preferences::DEVICE_ONLY`): an
  account writing one gets 422.
- **On the server, not in `localStorage`.** A kiosk whose browser data
  someone clears would otherwise stand differently from how it was set up — and in the
  admin it would not be visible how a tablet is configured.
- **Only the most recent agenda request draws** (`useAgenda`), and a
  response for other days (`coversRange`) does not replace a displayed agenda.
  The service worker deliberately stores the agenda without the period
  in the key; without this check a brief network dropout would show the
  events of the period last paged to.
- **The start area takes effect once at start**, not on every sync:
  `applyPreferences()` runs in `onMounted` and after pairing, not on the
  five-minute cycle.
- **`load()` does not blindly trust the response.** The service worker
  sometimes answers the first fetch from its store; a response
  without `preferences` would otherwise set the preferences to `undefined`.

### Resting mode of the wall

After `screensaver_idle` minutes without a touch the wall shows the areas from
`screensaver_areas` in rotation (`screensaver_interval` seconds) — dark,
without bar and buttons. Logic in `app/app/utils/screensaver.ts`, flow in
`app.vue` (`rest`/`wake`).

- **Display only, no state.** Neither rest nor carousel position is
  stored; a restart comes up awake. The theme is set directly on
  `<html>`, not via `useTheme` — the device's choice stays.
- **The wake tap lands on a cover layer** (`data-testid="screensaver"`),
  never on the button beneath it. Ending happens on `click`, not `pointerdown`:
  Otherwise the subsequent click would go to the uncovered element.
- **Dimming is done twice, from one value:** `screensaver_dim` by day and
  `screensaver_dim_night` while the household's theme is dark
  (`restingDim()`, by `themeState`, not by the resting wall's own dark
  `data-theme`), 0–90 % in steps of five (`Preferences::darkening()`),
  sliders in the device settings and the device form. If the theme turns
  during rest, Fully's brightness follows from the brightness before
  resting. The value sets the opacity of
  the black cover layer (`veilOpacity`, photos alike) and
  lowers Fully's real brightness by the same share
  (`dimScreen`/`restoreScreen`). Without Fully only the layer remains — a
  browser cannot set the brightness. The slider stores on release
  (`change`), not on every step of the drag. While it moves and 1.5 s
  after release, the wall previews the result (`dim-preview` under the
  device dialog, `pointer-events-none`); with Fully, `previewScreen()`
  counts every step from the brightness before the drag, so steps do not
  dim on top of each other.
- **The school area pages through the children:** Its slide lasts one interval
  per child; on waking, the previously selected child comes back.
- **Open dialogs prevent resting** — half-typed input is not
  inactivity.
- **Allowed areas** are in `Preferences::SCREENSAVER_AREAS` and
  `RESTING_AREAS`; lists and recipes are for working, not for looking at.
- **Filament option keys `'10'` come back as a number** (PHP arrays).
  `EditDevice` therefore converts the resting values into text, otherwise
  `Preferences::merged()` silently discards them.
- **e2e with `page.clock`** instead of real minutes.

### Calendar: one day, one column per person

`view === 'day'` shows `dayGrid()` — one day whose columns are the
people (`utils/calendarPeople.ts`, `TimeGrid` with the prop
`columns`). To whom an event belongs is determined first by `event.people`, otherwise by the
people of the calendar (`calendar_person`, a calendar can belong to several
people); if it belongs to nobody, it appears
in the column **“Family” („Familie“)** — which only appears if something lies there.

Above the calendar there is **one** bar (`PeopleBar.vue`) with two
jobs: progress per person (`GET /api/wall/tasks/progress`, relative to
the displayed period) and showing/hiding calendars. The filter is
stored with the device (`calendar_people`, the tasks' bar likewise in
`task_people`): a tablet in a child's room keeps showing that child after a
restart. An empty selection shows everything.

### Timetable: the rule stays a rule

`lessons` stores weekday and cadence, not events. What actually takes place on a day
is expanded at runtime by `LessonOccurrenceService::between()` — like with the task routines. Holidays, public holidays and exceptions
are already factored in.

```
lesson_periods    bell schedule per child (number → time of day)
lessons           rule: weekday, period_number OR starts_at/ends_at
lesson_changes    cancellation or substitution on a date
school_closures   holidays and school-autonomous days; person_id null = all children
```

- **Grid or free time of day, never both.** A check constraint in the
  database enforces this, not just the form.
- **Two cadences.** `cadence = calendar` counts calendar weeks and runs straight through the
  holidays; `school_weeks` counts only teaching weeks. Holidays entered later
  therefore shift the school-week cadence retroactively — an exception
  on a then-empty day is **ignored, not deleted**.
- **Only plain dates in the calculation service.** `anchor_on` is an `immutable_date`
  (UTC midnight), `CarbonImmutable::now($zone)` is not — comparing the two
  tips the cadence over by one day. In the service therefore compute with
  `CarbonImmutable::parse($x->toDateString())`, and `diffInWeeks`
  (float in Carbon 3) needs `(int) round(...)`.
- **Public holiday ≠ school-free.** Only `HolidayEntry::official` cancels lessons.
- **Holiday suggestions come from the `school_holidays` providers**
  (built in: Austria from `households.holiday_region`; connected: an
  ICS calendar for any other country) — created only on button press.
- **One write path for exceptions** (`useLessonException`): timetable view
  and calendar band use the same dialog.
- **The admin opens the timetable as a grid** (`TimetableGrid`, resource page
  `index`; the table is `list`). Filters live in the URL (`#[Url]`). An empty
  cell links to `create?person=…&slot=day-period`; `CreateLesson::afterFill()`
  takes both only for a child of the household and a free slot.
- **Creating via the week grid** (`lesson-grid.blade.php`): one subject, all
  tapped cells → one `lessons` row per cell in `CreateLesson`. Occupied
  cells are locked and checked again on save.
- **Colour belongs to the subject, not the lesson.** `Lesson::booted` passes a
  changed `color` on to all lessons with the same child and title; a
  new subject takes it over from existing ones. A substitute lesson shows no colour
  (different subject). Without a colour the wall falls back to the child's colour.
- **Back to the list after saving** — panel-wide via
  `resourceCreatePageRedirect`/`resourceEditPageRedirect('index')`.

## AREAS

A household switches areas off individually: `households.disabled_features`
(JSON list). The exception is stored — empty means “everything on”, so that an
area added later is available on its own.

```
app/Enums/Feature.php            calendar, tasks, lists, points, meals, recipes, timetable
Household::has(Feature)          does the area apply?
Household::features()            the enabled ones, for wall and phone
routes/api.php                   ->middleware('feature:points')
```

**Switched off also means switched off for the API** (404, not 403: For
this household the area does not exist). If it were only a matter of the bar,
a tablet with an old state would keep writing.

- **Data is retained.** Whoever re-enables the points half a year later
  finds the state from back then.
- **`Feature::requires()` records dependencies.** Recipes without a meal plan
  would have no place where they are needed — this also holds if someone
  sets the setting bypassing Filament.
- **The wall's bar comes from the server.** `agenda.household.areas` delivers
  the enabled areas in enum order with view
  (`wallMode()`), short name (`shortLabel()`) and icon (`icon()`); the wall
  has no list of its own. A new area is a case in `Feature` plus
  its view in `app.vue`. If the displayed area is switched off,
  `validMode()` switches away — otherwise a view would remain whose
  API gives 404.

## PERMISSIONS

What someone may change is defined in `App\Enums\Permission` — a fixed list,
per area “own” and “all”. Reading is not a permission: The wall shows
everything anyway.

```
app/Enums/Permission.php          list, defaults per role and for wall devices
app/Support/Abilities.php          effective permissions of an account or device
app/Http/Middleware/EnsurePermission.php   ->middleware('permission:lists.edit')
people.permissions / devices.permissions   null = default, otherwise the whole list
```

- **“Own” means: The acting person is involved.** For events
  `Event::resolvedPeople()` (assigned, otherwise the people of the calendar),
  for tasks the responsible person, for the timetable the child. An event without
  people is a family event and needs `calendar.edit_all`. A
  task for nobody counts as own when ticking off.
- **Events are checked before and after** (`WallEventController`). Otherwise
  a child could simply push their event to a sibling.
- **The route does not know the record.** `permission:calendar.edit_own`
  lets through anyone with “own” or “all”; whether it is really theirs
  is decided by the controller via `Abilities::authorizeOn()` or
  `authorizeCompletion()`.
- **No Laravel policies.** Filament queries policies on its own and
  denies everything for which a method is missing — any policy on `Task` or
  `Event` would have made resources disappear there. The admin hangs
  on the permission `household.manage`, the API on `Abilities`.
- **The UI does not recompute “own”.** Events, tasks and
  lessons carry `can` in the payload, `/auth/me` and `/devices/me` the
  general `permissions`. The phone hides things; the wall offers everything
  and asks for the PIN on 423 (`usePinGate`, included once in `app.vue`,
  repeats the call after unlocking).
- **Devices: 423, accounts: 403.** On the wall the PIN helps, on the phone nothing does.
- **The wall's default is exactly what it was allowed to do without a PIN before permissions
  existed** (`Permission::deviceDefaults()`).
- **`Abilities` remembers the permissions per request**, keyed by
  actor and household. After unlocking or locking the PIN,
  `WallPinController` empties the store (`forget()`).
- **The last account with `household.manage` cannot take it away from itself**
  (`EditPerson::mutateFormDataBeforeSave`).
- **Guests (`PersonRole::Guest`) are not family columns.** Defaults without
  `household.manage` and `tasks.manage`; `dayColumns()`/`peopleColumns()`
  drop a guest without an entry that day, `Person::ordered()` puts guests
  last, and “everyone eats” (`eaters: null`) means everyone but guests.

## MEMBERSHIPS

One account, several households (design in
`docs/architecture.md#one-account-several-households`).

```
memberships                      user_id, household_id, person_id, preferences, accepted_at
personal_access_tokens.household_id   the household this token looks at
app/Auth/ActiveHousehold.php     which membership a request uses; switch()
app/Services/Memberships.php     invite, accept, remove, isLastManager
```

- **An account names no household; its memberships do.** Read
  `$user->membership()` (the one in `HouseholdContext`), `currentPersonId()`,
  `membershipIn($id)`; the request's household is `HouseholdContext`, never
  something on the account. Creating an account means creating its
  membership too (`Memberships`, `HouseholdOnboarding`). In tests
  `User::factory()->create(['household_id' => …, 'person_id' => …])` is the
  short way: `UserFactory` turns the two into an accepted membership.
- **`Membership` has no `BelongsToHousehold`** — signing in reads it before
  any context. Query it from the account or with an explicit `household_id`.
- **The active household is the token's, the admin's is the session's**
  (`ActiveHousehold::SESSION_KEY`). A stored id counts only as an accepted
  membership; otherwise the last used one applies and is written back. The
  admin (session, no token) accepts only households the account manages — a
  guest there would otherwise open the family's admin, since `canAccessPanel`
  asks for *any* managed household. A request without session and without a
  `PersonalAccessToken` (tests' `actingAs` against the API) counts as the app.
- **Permissions per membership** (`Abilities::ofMembership`, `ofIn`). The
  PIN stays `users.pin_hash`; `ParentPin::parents()` asks `ofIn()` for the
  wall's household.
- **Preferences:** `Preferences::PER_HOUSEHOLD` (start area, menu, people
  filters) live in `memberships.preferences`, the rest on `users`.
- **Push rows are per endpoint and household**; `WebPushService::payloadFor`
  adds `household_id`, `?household=` to the url and the household's name to
  the title for accounts with two memberships. An expired endpoint clears all
  its rows.
- **The admin's accounts page lists memberships.** Deleting removes the
  membership (`Memberships::remove`: push rows, token household, the account
  itself once no membership is left). An account in two households keeps
  address and password there (`AccountForm::isShared`).
- **Inviting answers the same for every address**; a known one gets a
  pending membership and accepts in the app (`/api/auth/invitations/{id}`),
  the operator's address gets nothing (`Membership::booted` refuses one).
- **The accounts page edits memberships** — address it with the
  membership's key, never the user's: the ids only coincide while every
  account has exactly one membership, and sequences survive rollbacks.
- **`migrate:fresh --env=testing` wipes the development database** — there
  is no `.env.testing`; `phpunit.xml` names `fahoba_test` only for PHPUnit,
  which migrates it itself.

## OPERATIONS

One server, several households, public via HTTPS. Everything for this lives under
`deploy/` (`compose.yaml` with `app` and `db`; Reverb as profile
`realtime`). The comments in `compose.yaml` and `.env.example`
describe the procedures.

- **Renamed from Tagwerk (0.3.0); the old name that remains is deliberate.**
  Never “clean it up”:
  - The Compose project, its network and the database defaults stay
    `tagwerk`: volumes are named after the project, a new name starts a
    server on an empty database.
  - `RenamedCookies` and `theme.js` carry cookies and storage keys over;
    Google's `tagwerk_*` and CalDAV's `X-TAGWERK-*` are still read;
    webhooks also send `X-Tagwerk-*`.
  - Old exports import through `RenamedFromTagwerk` (also `tagwerk-recipes`
    and `tagwerk_plain_secret`).
  - The entrypoint bridges `TAGWERK_*` to `FAHOBA_*`.
- **One panel, `/admin`.** Whoever has `household.manage` may enter
  (`User::canAccessPanel`), and every operator (`users.is_operator`). The latter
  additionally sees **Operations → All households** („Betrieb → Alle Haushalte“) (`ManagedHouseholdResource`):
  create, suspend, open support access. `is_operator` is not fillable
  and arises only with `fahoba:create-operator` or via `forceFill`.
- **The app's sign-in opens the admin** (`AdminFromAppCookie`, in the panel
  group after `StartSession`). It reads `fahoba_user_token`, never the
  device cookie, signs the web guard in only if `canAccessPanel` agrees,
  and stores the token id in the session (`AdminFromAppCookie::SESSION`):
  once that token is gone the admin session ends. A password session has
  no such key and is left alone. Signing out in the admin therefore signs
  the browser out of the app too (`AdminLogoutResponse`: token deleted,
  cookie cleared, back to `/`) — otherwise the cookie signed the admin
  straight back in on the way to the login page. The phone menu's “Verwaltung” is a plain
  link to `/admin` (`household.manage`), the admin's “Zur App” a
  `USER_MENU_BEFORE` render hook. e2e checks every admin page at 390 px for
  sideways scrolling — a new page belongs in that list.
- **New households do not start empty.** `HouseholdOnboarding` calls
  `HouseholdStarter`: setup steps (0 stars) for the first
  parent, child routines per child (`per_person`, no selection = all children),
  rewards and a shopping list. Only on creation, never retroactively; the
  sample data of development (seeder) does not run through it.
- **The operator belongs to no household** (`household_id` and
  `person_id` null). Without a chosen household they see only Operations: The
  family areas carry `NeedsHousehold` and are hidden without a
  context instead of showing empty lists whose “Create” fails on
  `household_id`. New family resources need the trait
  too; widgets check the context in `canView()`.
- **`ResolveHousehold` clears the context at the start of every request.**
  `HouseholdContext` is a singleton; an operator without a household would
  otherwise inherit the previous request's in long-lived processes (and in tests).
- **Support access:** The operator switches into a foreign household via the header bar or the
  table action (`SupportAccessService`).
  - The choice lives in the session; `ResolveHousehold` accepts it only for
    operators. Phone and wall log in via token and never see it.
  - In the foreign household the operator has all permissions, even in suspended ones.
  - A bar at the top shows the household; every switch is recorded in
    `support_accesses`, and the family sees the latest one in their
    settings.
- **The own account** (name, email, password) is maintained by everyone via the
  user menu → Profile (`->profile()`, Filament's `EditProfile`). Every
  change demands the current password; a new one logs out all phones.
- **Mail goes via SMTP and the queue** (`MAIL_*` in `deploy/.env`). In
  development it lands in Mailpit (`https://fahoba.test:8026`); DDEV has
  no worker, so run `php artisan queue:work --stop-when-empty` by hand.
  Texts of the Laravel mail template live in `lang/de.json`.
- **`FAHOBA_MAIL=off` runs the server without mail** (`OutgoingMail::enabled()`).
  The mail routes answer 404 (`mail` middleware, placed before the throttle
  in the priority list — the routes share the login limiter), Filament's
  password reset is not registered (panel built at boot, so a change needs
  a restart), and accounts and first parents get a password in the admin
  — set there, the address counts as confirmed. Not derived from
  `MAIL_MAILER`: `log` is the development default and must keep the mail
  paths. Tests switch it in the environment before `parent::setUp()`
  (`MailOffTest`).
- **Forgot password leads into the app** (`/?reset=…`,
  `PasswordResetController`), not to `/admin`: The admin can be locked for the family via
  `FAHOBA_ADMIN_ALLOWED_IPS`, the app never.
  Filament's own `->passwordReset()` remains for the login in `/admin`.
- **Accounts are invited, not created with a password** (`Invitations`).
  Until acceptance they carry a random password and no
  `email_verified_at` — that is “Invited” („Eingeladen“). Separate broker `invites`
  (7 days) in the same token table; a new link invalidates the old one.
  Every password setting via link fires `PasswordReset`, and the listener in
  `AppServiceProvider` sets `email_verified_at` — also for Filament's reset.
- **A new email address only applies after confirmation**
  (`->emailChangeVerification()`); the old one receives a link to stop it.
  The confirmation link lives under `/admin` and requires login.
  Filament's mails use Laravel's default texts — German via `lang/de.json`.
- **A new password notifies by mail** (`PasswordChanged`, in the
  `updated` hook of `User`), except when accepting an invitation.
- **Login link by email** (`LoginLinks`), in the app and in `/admin`.
  A link on the phone opens in the browser, not in the installed
  app — separate cookies. The app mail therefore additionally carries a
  code (6 characters out of 32, not a plain numeric pad: with codes requested
  over and over, a million would be too few). Stored in the cache, 15
  minutes, single-use, expired after 5 failed attempts. The `/admin` mail has
  no code; its link goes via `/login-link` (web guard).
- **e2e tests of the mail paths use an unknown address.** The response is
  the same, and the login throttle (5 per minute, email and address) of the
  test account stays free for the tests that follow.
- **Further accounts come via Family → Accounts** („Familie → Konten“) in `/admin`
  (`AccountResource`). An account is attached to a person; what it may do is set
  on the person. The own account cannot be deleted there.
- **A password change or deleting an account revokes its
  Sanctum tokens** (`User::booted`). Otherwise a lost phone would stay
  logged in.
- **A suspended household (`suspended_at`) is gone immediately:**
  - `ResolveHousehold` answers with 403.
  - Sync, push renewal and daily summary skip it via
    `Household::active()`.
  - New background commands that run across all households need
    the same filter.
- **A read-only household (`read_only_since`) reads but never writes**
  (Operations → All households; the hosted service's trial sets it too).
  - `EnsureWritable` sits in the whole `api` group and answers 402 to
    every unsafe request of a route that carries `household` — before
    validation. What must keep working (sign-in/out, PIN, push,
    preferences, the own dashboard's layout) sits in a group with
    `EnsureWritable::SELF`; `ReadOnlyHouseholdTest` lists those routes and
    the public writing ones, so either list grows only on purpose.
  - The admin opens only `ReadOnlyHousehold`, the profile and pages that
    implement `AvailableWhileReadOnly` (plugin-api) — `RedirectReadOnlyAdmin`,
    persistent; `NeedsHousehold` drops the rest from the navigation. The
    operator in support access is exempt (`ReadOnlyAdmin`).
  - Syncs, push renewal and Google's notifications skip it via
    `Household::writable()`; the daily summary only reads and keeps going.
  - The app shows the server's 402 message once in `ReadOnlyNotice`
    (`read-only.client.ts` wraps `$fetch`); the call still fails, so
    nothing shows as saved.
- **Notices are states, messages are events.** A notice is computed on
  asking and gone when the state is (trial, read-only, a lost connection);
  a message is stored because something happened (export ready, reward
  requested). Something that resolves itself is a notice, something one
  reads once is a message.
- **Household notices** (`offer('notices')`, `HouseholdNotice` → `Notice`
  with text, level, optional link, `key`, `NoticeAudience`) are gathered by
  `HouseholdNotices::all()`: plugins, lost connections
  (`reconnect:<id>:<updated_at>`), and for a read-only household without a
  plugin's blocking one the core's sentence (`read-only`); most severe
  first, cached a minute per household and language (`forget()` on a
  read-only change). `for($id, $viewer)` filters by audience (a device:
  household; an account: plus managers with `household.manage`, plus
  operator) and the membership's `dismissed_notices`. `blocking()` explains
  the 402 (`message`, `url`, `action`). Delivered as `household.notices` in
  the agenda and `notices` in `/api/auth/me`, in the admin by the Livewire
  `HouseholdNoticeBar` (`BODY_START`), which adds `forOperator()` —
  plugins that failed to load. Only a keyed, non-blocking notice closes for
  an account (`POST /api/notices/dismiss`, 404 for a key not showing); the
  app's `HouseholdNoticeBar` closes a wall's once a day
  (`fahoba-notice-seen`, a JSON list of today's, per device).
- **Messages are Laravel notifications extending `HouseholdMessage`**
  (`title()`, `body()`, `link()`, `push()` tag, optional `toMail()`). Always
  `HouseholdDatabaseChannel` (sets `notifications.household_id`, announces
  area `notifications`), `PushChannel` only with a tag (the account's
  phones in that household; catches everything, see the outage rule), mail
  only while `OutgoingMail::enabled()`. Stored in Filament's format, so the
  admin's bell (`databaseNotifications()`, polled) draws it; the app reads
  title, body and the first action's url (`NotificationController`, account
  only, SELF group). `User::notifications()` is overridden to the
  household in view plus household-less rows — Filament and the API read
  through it. `App\Models\Notification` adds zoned timestamps; deleting an
  account deletes its rows (a morph has no foreign key). `notifications` is
  in `HouseholdExport::SYSTEM`. Tests use `Notification::fake()`; a
  household-wide broadcast without recipients (the daily summary) stays a
  `SendPushNotificationJob`.
- **A message leads to where it is handled**: `link()` (or a push payload's
  `url`) names the area as `/?area=<wallMode>` — without one a tap lands
  on the start page. `useAuth().followNotification()` strips `?area=` and
  `?household=` from the address and writes the area into the session's
  remembered area of that household (`modeKey()`, `utils/entry.ts`), so the
  shell starts there, after a household switch's reload too; `validMode`
  drops an area the household has off. Accounts only — a tablet always
  starts in its start area.
- **Deleting goes through `HouseholdDeletion`** — the operator's action, the
  account's self-deletion (`AccountDeletion`, `GET|DELETE /api/auth/account`,
  allowed while read-only) and the hosted service's purge alike. It
  dispatches `HouseholdDeleting` first (a throwing listener stops it),
  deletes the row in one statement (the keys cascade), then accounts left
  without a household (never the operator), then files after the commit —
  uploads and the server photo folder (`FolderPhotoSource::forget`, which
  unlinks a symlink instead of following it).
  A new file column belongs in `HouseholdDeletion` and in
  `HouseholdImport::FILE_COLUMNS`.
- **Two imports, one export.** `HouseholdExport::import()` (same ids, empty
  database, superuser) stays for `pull-household.sh`. A move uses
  `HouseholdImport`: new ids, foreign keys from `pg_constraint`, tables in
  dependency order, a nullable column per cycle filled in a second pass.
  **A new JSON column must be classified** in `EMBEDDED_IDS` or `ID_FREE`
  (`HouseholdImportTest`). `HouseholdArchive` packs `household.json` and the
  rows' files and extracts only paths its rows name. Admin: Setup → Export &
  move (`HouseholdTransfer`, open while read-only), queued in
  `BuildHouseholdArchiveJob`/`ImportHouseholdArchiveJob`, signed download
  `/export/{household}/{file}`, purged after a week
  (`fahoba:purge-household-archives`).
  - **Passwords only travel when asked** (`export($household, secrets:
    true)`, page toggle, `--with-secrets`): `ExportSecrets` spares the
    movable columns from scrubbing (`provider_accounts` credentials and
    tokens, plugins' `MovesSecrets`, tile `SettingField::secret`), wraps the
    plain value as `{fahoba_plain_secret: …}`, and both imports `seal()` it
    with the target's `APP_KEY`. Server-bound columns (push subscriptions,
    Google channels, remember tokens, second factors) stay scrubbed. A new
    encrypted column that works elsewhere belongs in `ExportSecrets::COLUMNS`
    or the plugin's `MovesSecrets`.
- **`FAHOBA_ADMIN_ALLOWED_IPS` restricts the admin to networks.** Affected
  are `/admin`, the household switch `/support/…`, the consent paths
  `/auth/…` and the photo preview. Requests from outside get 404, not 403, because otherwise
  the response itself would reveal that something is there. The API stays
  public, because wall and phone are on the move.
- **`FAHOBA_TRUSTED_PROXIES` must be right, otherwise the list is worthless.** Without
  an entry Laravel sees only the proxy's address. With an entry that is too generous
  anyone can choose their own address via `X-Forwarded-For`.
  - It is read at request time in `TrustConfiguredProxies`. `env()` in
    `bootstrap/app.php` returns only `null` under `config:cache`.
  - `compose.yaml` sets the private ranges as default
    (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`). Docker picks the subnet
    itself; a fixed one collided on servers with many Compose projects
    (“Pool overlaps”). Safe only because `app` has no host port — only
    containers in `fahoba` and in the Traefik network can reach it.
- **The server runs behind Traefik**, labels directly in `compose.yaml`; host,
  network, entrypoint and certresolver come from `deploy/.env`
  (`FAHOBA_HOST`, `TRAEFIK_*`). Reverb needs no labels of its own: the
  nginx in the `app` container passes `/app` and `/apps` on. **Never put Basic Auth in front:** The
  browser's `Authorization` header displaces the token from the cookie
  (`DeviceTokenFromCookie`), wall and phone would no longer get in.
- **Pairing is throttled** (`RateLimiter::for('pair')`, 10 failed attempts per
  minute and address). Only what fails is counted, via `Limit::after()`:
  The e2e tests pair 17 tablets in under a minute, and an operator
  setting up several tablets is not guessing.
- **Two setups, one image.** `compose.yaml` builds on the server behind
  Traefik; `compose.standalone.yaml` (the default in `.env.example`) runs
  `ghcr.io/fahoba/fahoba` behind Caddy (`deploy/Caddyfile`,
  `FAHOBA_SITE` a host name for Let's Encrypt or `:80` behind another
  proxy). `COMPOSE_FILE` in `deploy/.env` picks one, so `backup.sh` and
  `rollout.sh` need no flag; `rollout.sh` pulls an image from `ghcr.io` and
  builds anything else. The app never gets a host port: two of it must run
  side by side during a rollout. Caddy finds them by `dynamic a` with a
  one-second `dial_timeout` — a removed container's address otherwise
  hangs a request until it times out.
- **The image knows its commit** (`FAHOBA_BUILD`, build arg
  `FAHOBA_REVISION`); `config('app.version')` uses it when `APP_VERSION`
  is empty, so walls reload on a new image by themselves. The frontend
  stage runs on `$BUILDPLATFORM`: its output is static, and Node under
  QEMU made arm64 builds crawl.
- **The licence is PolyForm Shield 1.0.0**, not open source: anyone may
  run it for themselves, offering it to others as a hosted service or a
  competing product stays with the licensor. The `Licensor Line of
  Business:` line in `LICENSE` keeps that so even while the licensor offers
  no hosting. The PolyForm text itself stays untouched (a changed one may
  not carry the name); “not even free of charge” is the licensor's
  clarification below it. Package metadata (`composer.json` of the app and every
  plugin, the image label) names `PolyForm-Shield-1.0.0`; a new plugin
  does too.
  `CONTRIBUTING.md` carries the contributors' extra grant — without it
  their code would bar commercial use.
- **Redeploy with `deploy/rollout.sh`, not `up -d --build`.** The latter
  stops the old app container before the new one is healthy, and Traefik
  answers 404 meanwhile. The script starts the new container beside the old
  (`--scale app=2 --no-recreate`), waits for `healthy`, then removes the old.
  - **Migrations must work for the previous release too.** They run in the
    new container's entrypoint while the old code still serves: add first,
    drop or rename one release later.
  - **Every scheduled task carries `->onOneServer()`** — two schedulers run
    side by side for a few seconds. `ScheduleTest` fails on a task without it.
  - **nginx and PHP-FPM stop last** (`priority=100` in `supervisord.conf`),
    so the old container answers until it dies and Traefik drops it.
  - **Queue and scheduler carry `autorestart=true`.** `queue:work
    --max-time` ends with exit code 0, and supervisord's default
    (`unexpected`) leaves such a program stopped. The queue then stood still
    an hour after every start — no realtime, push, list sync or mail, and
    nothing in the log. `supervisorctl` does not work in the container;
    `ps aux | grep queue:work` shows whether the worker runs.
  - The healthcheck checks every two seconds while starting
    (`start_interval`, Docker Engine 25+); the image's 30-second interval
    alone kept a ready container out of Traefik for half a minute.
  - The entrypoint chowns only what is not `www-data`'s yet — a recursive
    chown over `storage` grew with every uploaded photo.
- **PHP 8.5 ships OPcache built in.** `docker-php-ext-install opcache`
  fails there with “can't stat 'modules/*'”. It is now configured only
  in `deploy/php.ini`.
- **nginx forgets inherited `add_header` as soon as a `location` sets its own.**
  Every such block therefore includes `security-headers.conf` again. Otherwise
  HSTS is missing precisely at `sw.js` or the build artefacts.
- **Content Security Policy from one place** (`App\Security\ContentSecurityPolicy`,
  `FAHOBA_CSP` = `report-only` (default) | `enforce` | `off`).
  - The app runs **no inline script**: `modules/external-scripts.ts` moves
    Nuxt's inline scripts into `/_nuxt/inline-<hash>.js` at generate time
    and fails the build if one remains; the import map is off
    (`experimental.entryImportMap`) because it can only be inline. The
    theme line is `shared/public/theme.js` — inline in `app.head`, unhead
    inserted it again at runtime and the policy blocked it. So the app's
    header is `script-src 'self'` and does not change per build.
  - nginx sends the app's header: the entrypoint writes `fahoba:csp` to
    `/etc/nginx/csp.conf`, included in `location = /`; DDEV's post-start
    hook writes `storage/framework/csp-app.conf` (glob include, so nginx
    starts without it). After adding or removing a plugin restart.
  - Laravel's responses (the admin) get `forAdmin()` from
    `SetContentSecurityPolicy`: Filament writes inline scripts without a
    nonce and Alpine evaluates expressions, so `'unsafe-inline'
    'unsafe-eval'` there. No foreign host: the font is local
    (`LocalFontProvider` in `FahobaBrand`), and `User::getFilamentAvatarUrl()`
    returns the person's face or an initial as a `data:` URI — Filament's
    default would ask ui-avatars.com with the initials on every admin page.
  - Plugins add hosts via `offer('csp', 'vendor/name', ContributesToCsp)`:
    only `img-src`, `media-src`, `connect-src`, `font-src`, only `https://`.
    `WidgetContent::image()` accepts only hosts the policy allows.
  - Reports go to `POST /api/csp-report` (throttled, a few fields logged as
    “Content security policy violation”). `security.spec.ts` fails on any
    violation in the console — browsers log them in report-only mode too.
- **The image build keeps npm and pnpm downloads in BuildKit cache mounts**
  (`fahoba-npm`, `fahoba-pnpm`), installing with `--prefer-offline`. The
  frontend step reruns on every code change; from the server the registry
  was slow enough that downloading all packages again took eight minutes.
  `docker builder prune` empties the store — the next build is slow once.
- **`composer audit --locked --no-dev` stops the image build**;
  `FAHOBA_AUDIT=warn` only reports. **`pnpm audit --prod` stops CI, not the
  image**: the frontend stage holds build tools only (static output), and a
  fresh advisory against nitropack once blocked a rollout that changed
  nothing they ship. The image build still prints it. Advisories without a fixed version that only touch build
  tools are listed in `frontend/package.json` (`pnpm.auditConfig`), each
  checked by hand. A fixed patch of a transitive package goes into
  `pnpm.overrides` instead (`source-map-js`,
  `serialize-javascript`, `vue-i18n` with its `@intlify/*` core — lift
  those together, they pin each other exactly; drop them once
  `@nuxtjs/i18n` pulls the fixed version itself). Ignoring is
  the last resort, only where no fixed version exists; today that is
  `deepmerge` (nitropack → `@rollup/plugin-node-resolve`). Overrides lift
  `node-forge` (listhen, dev certificates), `glob` and `minimatch`
  (`@vercel/nft`, `archiver`) — drop each once Nuxt pulls the fix itself.
  Check them again on every Nuxt update.
- **`@nuxt/devtools` is off and removed** (`devtools: { enabled: false }`
  plus the override `"@nuxt/devtools": "-"`). Devtools 3 needs a
  `simple-git` with open advisories, and its fixed major drops the export
  devtools 3 imports. Turning devtools on again needs the override gone
  and a version without that chain.
- **App, layer and Nuxt must share one Vue version.** Nuxt 4.6 brought Vue
  3.5.43 while the packages asked for 3.5.42; the i18n augmentation landed
  on the other copy and the type check reported `$t` missing in some 540
  places. On a Nuxt update raise `vue` in `packages/app` and
  `packages/shared` to what Nuxt pulls.
- **Backup:** `deploy/backup.sh <Ziel>` writes `pg_dump -Fc` and
  `storage/app`. The `.env` with `APP_KEY` must be kept separately alongside, because
  without it the providers' credentials are no longer readable.
- **One household locally:** `deploy/pull-household.sh <ssh-host> <id>`
  streams `fahoba:export-household` through ssh and imports it with
  `fahoba:import-household` into a database of its own,
  `fahoba_household_<id>` (`DB_DATABASE` overrides `.env`). For looking at a
  family's data, never as a backup.
  - `HouseholdExport` reads the tables with `household_id` from the schema;
    pivots without one are listed in `PIVOTS`, server tables in `SYSTEM`. A
    new table in neither stops the export — add it to one of the lists.
  - Tokens, credentials, push subscriptions and Google channels are cleared
    (`SCRUBBED`): a local copy must not sync with or push to the family.
  - The import refuses a database with households and an export from a
    migration it does not know; foreign keys are off for its transaction
    (`session_replication_role`, superuser — DDEV's `db` is one), and
    sequences continue after the highest id.

```bash
# first account on an empty server, password is prompted;
# households afterwards in /admin under Operations
docker compose exec app su-exec www-data php artisan fahoba:create-operator Betrieb betrieb@example.org
```

## COMMANDS

```bash
ddev start                                          # bring up the environment
ddev exec 'cd apps/platform && php artisan test'    # PHPUnit
ddev exec 'cd apps/platform && php artisan migrate:fresh --seed'
ddev launch /admin                                  # Filament administration
ddev exec 'cd apps/platform && ./vendor/bin/pint --dirty'   # style, changed files only
ddev exec 'cd apps/platform && php artisan fahoba:sync-calendars'
ddev exec 'cd apps/platform && php artisan fahoba:renew-push-channels'
ddev exec 'cd apps/platform && php artisan fahoba:sync-lists'   # pull Bring & co.

ddev exec 'cd apps/platform/frontend && pnpm install'
ddev exec 'cd apps/platform/frontend && pnpm dev'   # app, https://fahoba.test:3000
ddev exec 'cd apps/platform/frontend && pnpm build'   # static; nginx: app at /
ddev exec 'cd apps/platform && php artisan fahoba:vapid-keys'   # web push keys
ddev exec 'cd apps/platform && php artisan reverb:start'   # WebSocket, port 8080
ddev exec 'cd apps/platform/frontend && pnpm test'  # Vitest
ddev exec 'cd apps/platform/frontend && pnpm typecheck'  # vue-tsc

cd apps/platform/frontend/packages/e2e && pnpm run e2e   # Playwright, FROM THE HOST
```

**Playwright runs from the host, not from the container:** Browsers and
runner are already there; in the web container it would be 400 MB extra. `@playwright/test`
is therefore pinned to the host's version — if it deviates, the runner looks for
browsers that are not present there under that version. What is tested is the built
state behind nginx, not the development server — run `pnpm build` beforehand, otherwise
the test runs against the previous build. Throwaway accounts and paired devices
“E2E” are removed by the teardown.

**Serving:** nginx rules in `.ddev/nginx/fahoba-apps.conf` — the app under
`/`, everything else Laravel. Not in `nginx_full/`: That directory
is generated by DDEV and ignored by Git. After changes run
`ddev exec 'sudo nginx -t && sudo nginx -s reload'`.

**Frontend:** Nuxt 4 instead of the version 3 named in the specification — for
a new project the current major version, as already with Laravel and Filament.
**What wall and phone share lives in the layer `packages/shared`** — colour tokens
and fonts, `useTheme`, `useRealtime`, `useTwoStep`, `TwIcon`,
`ThemeSwitch` —, included via `extends: ['../shared']`. Turborepo is still
not worth it: `pnpm -r` handles building and testing, and a task graph
without branches only manages itself.

The browser talks exclusively to the development server; `/api` runs through
its proxy to the web server in the same container. This is not a convenience:
The device token lives in an httpOnly cookie, which would not even be sent along
across a foreign origin.

All Artisan calls need `cd apps/platform`, because DDEV's `composer_root` does
lie there, but `ddev exec` starts in the repo root directory.

**Pitfalls:**

- **`ddev exec` aborts at the first command with exit ≠ 0.** Whoever chains several
  check runs writes `if …; then echo ok; else echo fehler; fi` —
  otherwise all further output is missing after the first red run.
- **TypeScript stays on 5.9.** TypeScript 7 breaks `vue-tsc` 3.3
  (`ERR_PACKAGE_PATH_NOT_EXPORTED ./lib/tsc`), the type check then fails without
  a single TS error.
- **Every app needs its `tsconfig.json`** with the references to `.nuxt/`,
  otherwise `nuxi typecheck` only reports “Cannot find matching tsconfig.json”.
- **Type check, build and development server write the same `.nuxt/`** — do not
  run them at the same time.
- **After a pull, also migrate the development database**
  (`php artisan migrate`). The tests migrate `fahoba_test` themselves; without a migration the wall
  gets 500s, for instance from `/api/theme`.
- **App and admin share one origin.** The service worker applies to `/` and
  thus to every page below it; it handles only navigations to `/` itself,
  otherwise it would store `/admin` as an offline shell.
- **Every `$fetch` to Laravel carries `baseURL: '/'`.** Nuxt's `$fetch` prepends
  `app.baseURL` to every path. The app runs at `/` now, but a base path
  would turn `/api/theme` into `/x/api/theme`, and nginx would answer with
  the `index.html` — status 200, so no visible error.

## TESTS

- Backend: PHPUnit feature tests against real PostgreSQL
- Frontend: Vitest (unit), Playwright (E2E)
- After EVERY code change run the relevant suites before saying “done”

## DOCUMENTATION

Five files, five audiences. Whoever completes a release checks all of them:

| File | For whom | When to update |
|---|---|---|
| `README.md` + `README.de.md` | Newcomers | When the entry point, commands or the state change |
| `CHANGELOG.md` | Retrospective | With every change under `[Unreleased]`; at a release, including “Known limitations” („Bekannte Einschränkungen“) |
| `docs/installation.md` + `docs/installation.de.md` | Operators | When `deploy/`, `.env.example` or an update step changes |
| `docs/kiosk.md` + `docs/kiosk.de.md` | Operation at the wall | When pairing or kiosk behaviour changes |
| `AGENTS.md` | Agents and developers | On new pitfalls or conventions |
| `docs/screenshots/` | README readers | After a visible change: `pnpm run screenshots` in `packages/e2e` (after `pnpm build`; borrows a location for the demo household and gives it back) |

**README, installation guide and kiosk guide exist in English and German.** Whoever
changes one changes the other in the same commit — families setting up a
wall tablet read the German one. `AGENTS.md` and `CHANGELOG.md` are
English only.

Beyond that, documentation is created only on explicit request.

The changelog records **causes**, not file lists — those are in the
history. What belongs in an entry is what someone without the history could not
reconstruct: why a decision was made the way it was and what
a bug was caused by.

**Releases follow SemVer from 1.0.0 on.** A release turns `[Unreleased]`
into `## [x.y.z] — date` and updates the status in both READMEs. A change
that breaks a running installation (a migration the previous release cannot
run with, a request an older tablet sends that is no longer understood)
raises the major version; until then (`0.x`, the history started anew at
0.1.0 on 2026-11-04) the minor one, named in the CHANGELOG. `APP_VERSION` is not the release number: it is
the marker the wall reloads on when it changes (`app_version` in the
agenda), set in `deploy/.env` — a new value per rollout, a commit hash will do. Bundled plugins keep their own version in their `composer.json`.

## SELF-IMPROVEMENT

This document is alive. On new project-wide patterns, add them here and update the date
in the comment above. Do not remove entries without consultation.
