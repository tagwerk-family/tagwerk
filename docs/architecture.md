# Architecture

Why Fahoba is shaped the way it is. This is the overview with the reasons;
the detailed rulebook (pitfalls, exact names, test hints) is
[`AGENTS.md`](../AGENTS.md). Plugin authoring has its own guide:
[`docs/plugins.md`](plugins.md). Where this page and the code disagree, the
code wins.

Fahoba is a self-hosted family calendar and household organiser for a
wall-mounted Android tablet, with phones as second screens. It belongs to the
category of commercial wall calendars, but runs on your own server, talks to
the services a family already uses and does not need a cloud account.

## Contents

- [The shape of the system](#the-shape-of-the-system)
- [Design principles](#design-principles)
- [Domain model at a glance](#domain-model-at-a-glance)
- [Tenancy: households and memberships](#tenancy-households-and-memberships)
- [Signing in: the wall and the account](#signing-in-the-wall-and-the-account)
- [Permissions and the parent PIN](#permissions-and-the-parent-pin)
- [Providers and areas](#providers-and-areas)
- [Calendar sync](#calendar-sync)
- [Tasks, stars and rewards](#tasks-stars-and-rewards)
- [Timetable](#timetable)
- [Recipes, meals and lists](#recipes-meals-and-lists)
- [Photos](#photos)
- [The dashboard](#the-dashboard)
- [One app, two ways in](#one-app-two-ways-in)
- [Realtime](#realtime)
- [Plugins](#plugins)
- [Languages](#languages)
- [Operations](#operations)

## The shape of the system

```
apps/platform/
  app/                 Laravel 13 API + Filament 5 admin (/admin)
  plugins/             bundled plugins (Composer path packages)
  packages/plugin-api  the MIT-licensed contract plugins build on
  frontend/
    packages/shared    Nuxt layer: design tokens, theme, realtime, i18n
    packages/app       Nuxt 4 app, served at /  (wall and phone)
    packages/e2e       Playwright, runs from the host
deploy/                one image, compose files, backup, rollout
```

- **Backend:** Laravel API plus Filament as the admin. PostgreSQL is the only
  supported database (tests run against it too, never SQLite).
- **Frontend:** one Nuxt app for tablet and phone. A Nuxt *layer*
  (`shared`) carries what must be identical everywhere: colours, theme,
  realtime, translations.
- **Admin:** everything that is *setup* (people, accounts, devices,
  connections, settings) lives in Filament. Everything that is *daily use*
  (ticking off tasks, shopping lists, calendar) lives in the app.
- **Realtime:** Laravel Reverb, optional. Without it the app still works; it
  just refreshes on its own schedule.
- **Cache, queue, session:** Valkey in development; a production install may
  use the database for all three to run one service less.

## Design principles

The decisions below recur everywhere. Most "why" questions in the code
reduce to one of them.

- **Build the core, borrow the edges.** No existing open-source project
  covered calendar sync, routines, a stars ledger, meals and a kiosk UI
  together. A third-party component is worth it only when what it stores is
  hard to build. The calendar sync, the task logic, the stars and the wall UI
  are Fahoba's own; Google, Bring, Tandoor and similar services sit behind
  providers.
- **Every external service is a provider.** Domain logic never names Google
  or Bring. It asks a registry and checks capabilities. Every area works
  without any third-party service (a built-in provider is always the base).
- **The wall must keep working.** A kiosk on a hallway wall must never show an
  error nobody can fix. An outage at a provider costs a convenience, never
  the core function: failures are caught and logged in the job itself (so the
  promise does not depend on the queue driver), an empty setup shows a
  sentence instead of a stack trace, and no font, script or image is loaded
  from a foreign server at runtime.
- **Fahoba stays authoritative.** Outward integrations push or mirror; the
  household's own data remains the source of truth, so a provider outage or
  protocol change never takes the family's list or calendar with it.
- **Derived, not stored.** Task occurrences, whose turn it is, lessons on a
  given day, photo memories and the wall's theme are computed from a *rule*
  plus a small set of stored *deviations*. Fixing a rule fixes the past and
  future at once; there is no pile of generated rows to repair.
- **The backend is authoritative.** The UI hides what you may not do; the
  server decides. A feature that is switched off answers 404 for the API too.
- **The UI asks "may I?", never "am I a wall or a phone?".** What appears
  depends on who is signed in; how it is laid out depends on the width.
- **Safe by default for a public server.** Anything that answers another
  program or an unauthenticated address (API, ICS, webhooks, MCP) is opt-in
  per household. Any address a household types in goes through a guarded
  outbound client that refuses private networks.
- **No dependency where a hundred lines do.** Dashboard drag and drop,
  confetti, and the grid maths are written in-house.
- **English code, localised UI.** Identifiers, comments and stored values are
  English; everything a person reads goes through translations.

## Domain model at a glance

```
Household (tenant)
 ├─ people          adult | child | guest; colour, avatar, permissions
 ├─ users           sign-in accounts, linked to a person through a membership
 ├─ devices         paired wall tablets; own preferences and dashboard
 ├─ calendars       from provider accounts; events with an origin
 ├─ tasks           routine rule + completions + assignment deviations
 ├─ point_entries   the stars ledger (append-only in spirit)
 ├─ rewards         redeemable with stars
 ├─ lists           shopping and other lists, items
 ├─ recipes, meal_plan_entries
 ├─ lessons         per child; periods, changes, school closures
 ├─ photos          uploads, albums, favourites
 ├─ provider_accounts   one connection = one area + one provider
 └─ dashboard_widgets   tiles, owned by a device or an account
```

- **Everything carries `household_id`.** A shared trait applies a global
  scope and fills the column on create. There is no tenancy package: the
  single rule is small enough to own, and the plugin API ships the same
  implementation for plugin tables.
- **People are not users.** A *person* is a member of the family on the wall
  (a colour, an avatar, a role). A *user* is a sign-in account. Small children
  have a person and no account. Permissions hang on the person.
- **Events record where they came from** (`origin`: remote or made here).
  Without it, writing an event from the tablet would echo back through the
  provider's change notification forever.
- **Routines are tasks with a recurrence rule**, not a second table. Shopping
  items are not tasks: a litre of milk has no due date, owner or value.
- **Stars are a ledger.** A completed task books an entry that points to the
  completion. Corrections are additional entries, never edits, so a child can
  trace what happened. Tables keep the historical name `point_entries`;
  "stars" is what the household reads.
- **Column names are provider-neutral** (`external_id`, `sync_cursor`,
  `push_*`, `external_version`). Google's own `etag` in a JSON payload keeps
  its protocol name.
- **Migrations are append-only.** A sealed checksum list fails the test suite
  on a changed or removed migration. They must also run while the previous
  release still serves traffic (add first, drop one release later), because
  rollouts start the new container beside the old. The history before the
  first public release is a single schema dump, sealed the same way.

## Tenancy: households and memberships

One server hosts many households, strictly separated. The operator creates
them in the admin; there is no self-registration. Depth:
[AGENTS.md, Critical rules](../AGENTS.md#critical-rules-always-loaded) and
[Memberships](../AGENTS.md#memberships).

### Households

- **No context, no data.** Nothing is visible without a `HouseholdContext`.
  This is deliberate: a forgotten context yields empty results instead of
  someone else's rows.
- **Background work sets the context on purpose** (explicitly freeing the
  root query is not enough, because relations carry the scope along).
- **Households can be suspended.** A suspended household is gone immediately:
  API requests get 403 and sync, push renewal and summaries skip it.
- **Households can be read-only.** Everything stays visible, nothing can be
  changed (402), syncs pause. The admin shows a notice instead of its
  pages. Written for a hosted trial that ran out, useful to any operator.
- **Plugins speak to a household through notices**, not through their own
  banners: every notice, the most severe first, to its audience (household,
  managers, operator), on phone, wall (once a day) and admin, and the
  blocking one carried in the 402. The core never names a price. An
  account closes a keyed notice for itself; the key names the state, so a
  state that returns is news again.
- **States and events are two things.** A notice is computed and vanishes
  with its state — storing it would need someone to clear it, and a
  forgotten clear would nag forever. A message is stored, because what
  happened stays true: Laravel's notifications, one list for the admin's
  bell and the app's, with push and mail as further channels of the same
  message instead of separate code paths per way out.
- **Deleting is one service** for the operator, an account deleting itself
  and a plugin's purge, announced before (`HouseholdDeleting`) so billing
  can refuse.
- **A household moves with new ids, not with its database.** One database
  per household would make moving trivial but cost every migration, job and
  the operator panel a loop over all of them, and break accounts in several
  households. The import rewrites foreign keys from the catalog and ids in
  JSON from a list a test keeps complete.
- **Areas can be switched off per household** (calendar, tasks, lists,
  stars, meals, recipes, timetable, photos, plus plugin areas). The *exception*
  is stored, so an area added later is on by default. Switched off means off
  for the API as well (404, not 403). Data is kept; re-enabling brings it back.
- **Interfaces are opt-in** per household (API tokens, ICS feed, webhooks,
  MCP). A new household opens nothing to the outside.

### One account, several households

A grandparent may help two families, so an account is not owned by one
household.

- **A membership joins account, household and the person the account is in
  that household.** The account holds credentials, language and look; the
  person holds the permissions. Each membership points to its own person, so
  a guest in one family and a parent in another needs no special rules.
- **Every request still sees exactly one household.** The isolation model is
  unchanged; memberships only decide *which* household a request resolves to.
- **The active household belongs to the token, not the account.** The phone
  can show family A while the laptop shows family B. The admin's choice lives
  in the session. A stored id only counts if it is an accepted membership;
  otherwise the last used one applies, never a foreign household.
- **Switching reloads the app.** All state belongs to one household; a reload
  guarantees nothing of the other one lingers, and the service worker caches
  per household. A rare action, so simplicity wins.
- **Preferences are split by meaning.** Account-wide ones (language, look)
  stay on the account; household-dependent ones (start area, menu order,
  people filters) live on the membership.
- **Invitations answer the same for every address**, so no household learns
  which addresses have an account. A known address gets a pending membership
  that the person must accept; nobody appears in another family without
  consent.
- **Operators have no membership.** Looking into a household is always a
  logged support access, never a side effect of belonging to it.
- **Guests are a role, not a mechanism.** Grandparents who are in the family
  without being a column in every view: a `guest` person has a subset of
  adult permissions (adjustable like anyone's), does not appear as a task or
  calendar column unless something belongs to them, and does not count in
  portions unless selected.

## Signing in: the wall and the account

There is one app with two ways in. This was once two apps (wall and phone);
they were merged because every change otherwise had to be built twice.

| | Device (wall tablet) | Account (person) |
|---|---|---|
| Gets in with | one-time pairing code or QR | password, login link or code, optional second factor |
| Credential | long-lived device token in an httpOnly cookie | account token in an httpOnly cookie |
| Rights | device defaults; the parent PIN lifts them for a few minutes | the person's permissions |
| Only here | resting mode, PIN, unpairing, "who was that?" on tick | push, sign-out, personal calendars, admin link |

- **A tablet is a device, not a user.** Nobody logs in at the wall; children
  must be able to use it. Pairing happens once.
- **Tokens never live in `localStorage`.** Only httpOnly cookies. A second
  cookie middleware sets the Authorization header only if it is missing, and
  the device cookie wins when both exist: a browser holding both *is* the
  tablet. Unpairing or signing out is the way from one to the other.
- **Wall and phone share endpoints.** Because the household comes from the
  authenticated model, one set of routes serves both; a second API for the
  phone would double the work on every change.
- **The shell decides by sign-in, the layout by width.** A tablet on a
  phone-sized screen gets the phone layout and keeps its PIN; an account on a
  laptop gets the side bar.
- **The admin opens from the app sign-in.** An account allowed into
  `/admin` is signed in there without a second login. Signing out in either
  place signs out of both.
- **The admin can be restricted by network** and answers 404 (not 403) from
  outside, so the response does not reveal that something is there. The API
  stays public because tablets and phones are on the move.

## Permissions and the parent PIN

Depth: [AGENTS.md, Permissions](../AGENTS.md#permissions).

- **One fixed list in code**, per area with "own" and "all" variants. Families
  do not need custom roles, and a fixed list can be tested.
- **Reading is not a permission.** Everybody in a household sees everything;
  the wall shows it anyway.
- **Defaults come from the role; deviations are stored as a complete list**
  on the person or device (`null` = defaults). One list instead of an
  allow/deny delta means what applies is in one place.
- **"Own" means involved.** An event is yours when you are among its people
  (or, if it has none, the people of its calendar). A family event with no
  person needs "all", otherwise any child could delete it. Checks happen
  before *and* after a change, so a child cannot push an event to a sibling.
- **No Laravel policies.** Filament queries policies on its own and denies
  everything a model lacks a method for; one policy would have made admin
  resources vanish. The admin hangs on `household.manage`, the API on a
  central `Abilities` class.
- **The UI does not recompute "own".** The payload carries `can` flags; the
  phone hides, the wall offers and asks for the PIN.
- **Devices get 423, accounts get 403.** On the wall a PIN helps; on a phone
  nothing does.
- **A device's defaults equal what the wall could do before permissions
  existed**, so introducing permissions changed nothing for tablets.
- **The parent PIN is per account.** Every parent sets their own, and it
  unlocks a wall only if that account manages *that* household. Without any
  PIN set, everything is allowed on the wall.
- **The last manager cannot remove their own management right.**

### Support access

- An operator enters a family's household from the admin (no separate panel).
  The choice lives in the session; phone and wall never see it.
- A bar names the household on every page; every switch is recorded, and the
  family sees the latest access in its settings.

## Providers and areas

Depth: [AGENTS.md, Providers](../AGENTS.md#providers).

- **An *area* is a kind of thing a family does** (calendar, lists, tasks,
  recipes, photos, holidays, geocoding, …). A *provider* is one way to serve
  it. Integration code lives under `app/Integrations`; what Fahoba computes
  itself lives in `app/Services`.
- **Capabilities instead of one big contract.** The base contract is tiny
  (key, label). Everything else is an optional capability a provider brings:
  listing, OAuth, delta sync, writing, change channels. A built-in provider
  with dummy methods for "not supported" would be a lie; a missing capability
  says it honestly. Domain services ask for the capability:
  - cannot write → the event only exists locally (that is simply how the
    built-in calendar works);
  - no delta → skipped in the sync;
  - no channels → nothing to renew.
- **Value objects cross the boundary, not raw API replies.** The sync core is
  free of provider knowledge. Provider quirks are translated *in the
  provider*: Google reports a cancellation as a status, CalDAV as a 404; the
  sync only sees "cancelled".
- **A connection applies to exactly one area.** A calendar connection and a
  task connection to the same service are two rows with two consents. Whoever
  connects a calendar has not handed over their task list. For the same
  reason, consent never requests previously granted scopes.
- **Credentials belong to the household.** Never read from `.env` or config:
  on a multi-household server such a fallback would hand one family another
  family's shopping list. A provider without an account is simply not
  connected. Server-wide settings (an OAuth client id) are the exception.
- **No contract without an implementation.** An interface is added only once
  there is something to check it against.
- **Connecting is a wizard** (area, then service, then setup) driven entirely
  by what providers declare about themselves, so adding a service does not
  touch the UI.
- **Registration is one class and one config entry.** A contract test checks
  every provider (no network or database in the constructor, complete
  description for the wizard).
- **Outbound calls are bounded.** Global time limits apply to every HTTP call;
  addresses entered by a household go through a client that resolves the host
  once, refuses private ranges, pins the connection to the checked address and
  validates each redirect.

## Calendar sync

- **Google is one provider among several**, not the system. The built-in
  calendar lives entirely in Fahoba, so an installation with no connection is
  fully usable.
- **Pipeline:** change notification (an empty trigger) → queued job →
  incremental fetch with a sync cursor → upsert with `origin = remote` →
  broadcast to open screens.
- **Robustness rules that explain much of the code:**
  - change channels are renewed by a scheduled job, never assumed to last;
  - a periodic fallback fetch exists because notifications are not fully
    reliable, and it is cheap because an unchanged cursor returns nothing;
  - an expired cursor is a normal path (clear the calendar, resync), not an
    error;
  - writes use an optimistic version check; a conflict is a named exception;
  - every own write carries a revision marker, which is the echo protection;
  - a sync never fails silently, and a rejected login in the background
    becomes a visible "authorization lost" state.
- **Who an event belongs to is resolved in steps:** explicit people on the
  event, else the people of its calendar (a calendar can belong to several),
  else nobody (a family event). Google has no concept of this, so Fahoba
  mirrors it into a private extended property on the remote event so that a
  full resync can restore it.
- **`personIds: null` means "no information"**, not "nobody": the sync then
  leaves an existing assignment alone.
- **Minimal scopes.** Event access plus a read-only calendar list; never full
  calendar access.
- **CalDAV** (a bundled plugin) has no common delta mechanism, so it re-reads a
  bounded window whenever the collection's tag moved, expands series locally,
  and refuses to rewrite a single date of a series.

## Tasks, stars and rewards

- **A routine is a rule; occurrences are derived.** There is no row for "next
  Tuesday". What is stored: the rule, and *facts* about an occurrence
  (a completion, a changed assignment), addressed by task and calendar day.
- **Rotation is arithmetic.** Whose turn it is follows from the running number
  of the occurrence since the start date, modulo the rotation list. Counting
  starts from the start date, not from the requested period, so paging does
  not restart the sequence.
- **An occurrence is task + day + person + daypart.** "Brush teeth" morning
  and evening is one task ticked twice a day. "Everyone" (one occurrence
  anybody ticks) is different from "each person" (one occurrence per person,
  each earning their own stars).
- **Tick, then ask.** Confirming means the task is done; "who was that?"
  comes afterwards, and is skipped when the column or the responsible person
  already answers it. Ticking without a name earns no stars.
- **A past day is read-only on the wall; an account may catch up** for a
  bounded number of days.
- **Undo removes the stars with it** (the ledger row cascades).
- **A task with history is soft-deleted.** The credit hangs on the completion,
  so removing a task never deletes what was earned. Pausing is separate.
- **Stars are booked only once per completion**, even when someone taps twice
  on a tablet.
- **The day bonus is settled, not accumulated.** After every tick or undo the
  owner's day is checked and the single bonus entry is created or removed.
- **A gift is two rows with one id.** Gifting stars between children books a
  debit and a credit in one transaction; the giver can never go below zero.
  Gifts are not *earned*, so goals and badges ignore them while the balance
  counts them. Gifting needs the same permission as awarding extra stars, so
  adults supervise it.
- **Only the admin edits the ledger.** Extra stars are a separate entry with a
  required reason; there are no reasonless plus/minus buttons.
- **Gamification is display.** Celebration, confetti and the "all done" face
  read what exists; they never compute separate truth. Animations are a
  per-device switch that wins over the system setting, because kiosk tablets
  often have system animations off for speed.

## Timetable

Depth: [AGENTS.md, Timetable](../AGENTS.md#timetable-the-rule-stays-a-rule).

- **A lesson is a rule, not a set of events.** It stores weekday and cadence;
  a service expands it on demand, the same way tasks work. A holiday entered
  later is then one row, not a repair job.
- **A period grid per child, or free times, never both** (a database check
  enforces it). Typing 30 clock times once a year is what nobody does twice.
- **Two cadences.** `calendar` counts calendar weeks straight through the
  holidays; `school_weeks` counts only weeks with teaching. Late-entered
  holidays shift the second one retroactively, which is why a deviation on a
  now-empty day is ignored, not deleted.
- **Cancellations are delivered, marked, not dropped.** Otherwise the wall
  would not show that today differs from usual.
- **Only official public holidays cancel lessons.** Memorial days are
  not school-free.
- **Holidays are entered by hand, with suggestions.** Austria's school
  holidays are largely rule-based and are suggested into a form someone
  reviews. A wrong rule is wrong in a suggestion, not silently in the
  database. Other countries enter them by hand or via an ICS calendar
  provider.
- **Only plain times and dates inside the service**, combined with the
  household's zone at expansion, which sidesteps the time-zone pitfalls of
  timestamp columns.
- **Children only.** Selection lists use children; the school band in the
  calendar is off by default so eight lessons do not bury a dentist
  appointment.

## Recipes, meals and lists

- **Recipes: own book, catalogs, connected sources.** The built-in book is
  always available; public catalogs are mirrored once per server; Tandoor is a
  per-household connection. Several recipe sources can be active at once, so
  a planned meal addresses its recipe as `provider:id`.
- **Fahoba owns the meal plan.** A recipe service may have its own plan, but
  two answers to "what is for dinner on Monday?" would be worse than one.
- **Plans page by calendar week** and use the household's enabled meal kinds.
- **Portions are computed from who eats**, using age and gender reference
  values; nobody enters portions by hand. The server scales and formats
  amounts, so "1 1/2 tsp" reads the same on the wall, the phone and the
  shopping list.
- **Recipe text is Markdown rendered without `v-html`.** Unknown syntax and
  HTML stay text.
- **Recipes come in by link (schema.org), collection (ZIP), or photo of a page.**
  Fetching goes through the guarded outbound client.
- **Shopping list from recipes** sums by name and unit, skips configured pantry
  staples, and is one code path for the recipe book, planned meals and a
  whole week.
- **Lists are not tasks and not an outward mirror.** Lists live in Fahoba;
  providers (Bring, Google Tasks) add a two-way sync on top. Matching is by
  name because the services offer no better key. Fresh local changes get a
  short grace period so an in-flight tick is not reverted by the next pull.
- **Bring is a plugin.** It has no official API; the setup is email and
  password stored encrypted, and a login that stops working is a visible
  state rather than a crash.

## Photos

Depth: [AGENTS.md, Photo sources](../AGENTS.md#photo-sources-the-frame-draws-from-several-folders).

- **Photo sources are providers** with a small contract (list, random, file).
  The built-in source stores uploads privately; folder sources read a
  directory on the server.
- **A folder provider delivers only what lies below its root.** The
  identifier comes from the device and is freely invented, so it is resolved
  and checked before use. Each household has its own root, so one family can
  never enter another's folder. This is the most important test of the area.
- **Amazon Photos has no public API.** What Fahoba reads is the folder the
  Amazon app (or any sync tool) maintains; it is a separate list entry so
  people find it.
- **Stored images are redrawn**, which drops EXIF including location data and
  everything else that hangs on a file. Uploads are never stored unchecked.
- **The wall fetches a batch, not one image,** and flips through locally.
  This survives network gaps and avoids thousands of requests a day.
- **Frame style is per device;** the household decides rules for choosing
  pictures. A rule receives all images with dates and returns a selection; it
  does not read files itself. Plugins can add rules.
- **Date taken comes from EXIF, then the file name, never from the
  modification time,** so a downloaded album does not all carry the download
  date.
- **Memories are derived** ("this day in earlier years", runs of consecutive
  days), never stored. "Show on the wall" is a short-lived cache entry, not a
  table.
- **Deleting is soft** with a trash that is emptied after a period; only a
  permanent delete removes files.

## The dashboard

Depth: [AGENTS.md, Dashboard](../AGENTS.md#dashboard-tiles-on-a-grid).

- **Tiles on a 12-column grid.** A tile has position and size in grid cells.
  Overlaps are *prevented*, not resolved: where something lies, nothing can be
  dropped. A grid that re-sorts itself on drop surprises everyone watching.
  The check runs on the client and again on the server.
- **Every device and every account has its own dashboard.** A tablet in the
  hallway and one in a child's room differ; an account on a phone has a third.
  First visit places sensible defaults rather than an empty surface.
- **Edit mode sits behind the parent PIN on a tablet,** so an accidental swipe
  on a hallway wall does not rearrange it. An account arranges its own layout
  without a permission, because nobody else relies on it.
- **Below the `sm` breakpoint tiles stack** in reading order; arranging
  happens where the grid fits.
- **A new tile is a registry entry plus a component.** The mapping is static
  so a missing counterpart is a type-check error, not a blank spot on a wall.
- **Tiles for disabled areas disappear from display only;** rows stay so a
  re-enabled area finds its layout.
- **Plugin tiles deliver data, not components.** A widget returns a metric,
  list, table, pairs, progress, actions or image; one generic component draws
  it. No HTML, texts stay text. Cached for a minute per tile; an error shows
  in its tile only.
- **Weather** comes from a source behind a contract, asked with coordinates.
  A one-hour cache stops a kiosk from making thousands of requests.
  Without a location the tile shows an explanatory sentence, not an error.

## One app, two ways in

Depth: [AGENTS.md, Tasks on the device](../AGENTS.md#tasks-on-the-device-two-views-one-rule).

- **One shell, one component per area, one state per data type.** The same
  views run on the wall and the phone; they ask a small composable for the
  actor's kind, person and abilities rather than checking "wall or phone".
- **Structure differs, so the narrow layout uses `v-if`,** not hidden CSS
  duplicates (which would double every test id). Classes differ with
  breakpoints.
- **Preferences have one key per meaning,** with a possibly different default
  for devices and accounts (the calendar defaults to three days on a tablet,
  one day on a phone). The width may change what is *shown* without changing
  what is *stored*.
- **Preferences live on the server,** not in `localStorage`, so a cleared
  kiosk browser comes back as configured, and the admin can see how a tablet
  is set up.
- **Resting mode is display only.** After a quiet period the wall rotates
  chosen areas, dimmed. Neither the resting state nor the carousel position is
  stored; a restart comes up awake. Open dialogs prevent resting.
- **Admin on the phone:** every admin page is checked at phone width for
  sideways scrolling; the app and admin share one origin and one service
  worker scope that leaves `/admin` alone.
- **Offline:** a service worker keeps the shell and the last agenda. The
  agenda cache is keyed per household, and only the most recent request draws,
  so a brief network drop cannot show another period's events.

## Realtime

- **Announcements are per household and area, never per row.** Every saved
  domain model schedules an announcement; a job deduplicates by household and
  area, delayed by a couple of seconds.
- **Clients gather announcements** and refresh only the areas named, so a
  burst becomes one refresh. While a device is itself writing (ticking off
  tasks), announcements wait until it has been quiet.
- **Why this exists:** a calendar sync that saved hundreds of events once sent
  hundreds of announcements; browsers ran out of connections.
- **A reconnecting socket announces "everything"** because a sleeping phone
  missed what happened.
- **Reading never saves.** A save on an unchanged model still fires events.
- **Channel authorisation is per household,** checked against the token's
  active household. Tests must configure the broadcaster before boot, because
  the null driver authorises every channel and would test nothing.

## Plugins

Depth: [AGENTS.md, Plugins](../AGENTS.md#plugins-providers-and-widgets-from-one-package),
[Plugin areas](../AGENTS.md#plugin-areas-menu-routes-admin-events),
and the author guide [`docs/plugins.md`](plugins.md).

### What a plugin can offer

- a **provider** for any area (calendar, lists, recipes, holidays, school
  holidays, photos, weather, geocoding, tasks),
- a **dashboard widget** (data only),
- a **photo rule**,
- an **area** with its own menu entry, routes, tables, admin pages and
  export rules,
- an **extension** (server-wide behaviour, switched on by the operator),
- **CSP hosts** it needs to load images or media from,
- a **language pack** or a **design pack** (tokens only, see below).

### Why it is built this way

- **The licensing boundary is a package.** The core is under PolyForm Shield;
  third parties should not have to build on Shield-licensed classes that
  change with every core release. `fahoba/plugin-api` (MIT) holds the
  contracts, data objects, catalog and the household scope. The core
  implements it; a third-party plugin depends on it only. A test fails on any
  `App\` reference inside the API package.
- **Interfaces stand in for models.** Where a plugin needs a core model
  (account, calendar), the API defines a narrow interface that the model
  implements. Interface method names never equal a column name (Eloquent
  would resolve them as attributes).
- **Loaded through the core, not Laravel discovery.** A plugin names its
  provider in `extra.fahoba.provider`. The core registers it inside a
  try/catch.
- **A broken plugin never stops the app.** A failed package is skipped,
  everything it offered is withdrawn, the reason is logged and shown to the
  operator under Operations. Stored rows stay, so fixing the plugin brings
  everything back. Honest limit: what PHP cannot catch still takes the
  process down.
- **Installed means enabled,** except extensions, which change the server for
  every household and so need an explicit operator switch.
- **Identifiers carry a vendor** (`vendor/name`); built-in ones never do. No
  package can claim `google` or `fahoba/bring` and receive their stored data.
  Offer keys are stored data and never change, even if a package is renamed.
- **There is no cage.** A plugin runs in the same process with all privileges.
  Install only trusted packages, pin versions, run `composer audit`. Real
  isolation would need a separate service behind HTTP.
- **Plugin areas behave like built-in areas:** switchable per household, 404
  when off, absent from the menu when they have no view, and opt-in when they
  open a door to the outside. A removed plugin leaves nothing behind but dormant
  rows.
- **Plugins know the core, never each other.** Reacting to something happens
  through domain events and core contracts. Domain event names are a contract:
  add, never rename.
- **Frontend plugins are Nuxt layers** discovered at build time from installed
  packages; a view registers itself and the shell draws it.
- **Author ergonomics are part of the design:** a generator
  (`fahoba:make-plugin`) writes a working package incl. tests and CI, a
  testkit lets authors verify what the core enforces at runtime, and the
  output is published as a template repository.

### Fahoba's own plugins

- **Lean by design: the image installs none.** Their sources ride along in
  the image; `FAHOBA_PLUGINS` names what the container installs when it
  starts, at the image's version. A plugin that cannot be installed stops
  the start, because running on without it would quietly drop what it does
  (two-factor sign-in, for one). A plugin with its own screens is part of
  the app build and needs an image built with it.
- API tokens (core) with `fahoba/openapi`, `fahoba/ics-feed`,
  `fahoba/webhooks`, `fahoba/mcp`: ways to let other programs in. All are
  opt-in per household. API tokens can never exceed their account's rights;
  MCP tools call the app's own API with the caller's token, so permissions,
  PIN rules and validation stay in one place.
- `fahoba/caldav`, `fahoba/bring`, `fahoba/kochwiki`: providers.
- `fahoba/webdav-backup`: an extension for the operator.
- `fahoba/two-factor` and `fahoba/social-login`: sign-in extensions.
  Social sign-in matches *existing* accounts only, and only on a verified
  address claim; afterwards the provider's subject counts, not the address.
- `fahoba/example-area`: the reference for plugin authors and a development
  test fixture; absent from the production image.

## Designs and frontends

Three depths, each with its own promise. Guides:
[design packs](plugins.md#design-packs), [frontends](frontends.md).

- **A design changes tokens, never components.** Colours, font, corners and
  the glass gradient are custom properties in `fahoba.css`; a design pack
  is one stylesheet that sets them, checked and served by the server. A
  stylesheet that styled components would freeze every class name of the
  app into the API. Tokens are few and can be promised: their list ships in
  `plugin-api` and only grows.
- **Replacing components is not offered.** A Nuxt layer could override one
  technically, but every internal component would become public interface.
  Whoever wants a different interface builds a frontend.
- **A frontend is a static app against a fixed route list.** The server
  already decides permissions, occurrences, rotation, the theme schedule and
  notices, so another interface does not rebuild rules; what little the
  screen must apply the same way (keys of occurrences, keeping a fresh tick
  over a load, celebrating once) lives in `@fahoba/client`, which the
  official app uses too — otherwise it would drift. The routes are a file
  and a test (`api-contract.txt`): adding is free, removing waits for a
  major release.
- **Same origin, by design.** The sign-in stays an httpOnly cookie; a
  frontend on another origin would need tokens in JavaScript. So the server
  serves the chosen frontend itself (`FAHOBA_FRONTEND`), checked at start
  against the app's Content Security Policy — refused rather than shown
  blank.

## Languages

Depth: [AGENTS.md, Languages](../AGENTS.md#languages).

- **German and English, chosen per person and per tablet,** falling back along
  a chain: device or account, then household, then the server default.
- **Further languages come as packs** (`offer('languages', …)`). Missing or
  broken texts fall back to English. A pack never replaces `de`/`en`.
- **No flash of another language.** The app asks `GET /api/language` before
  it mounts. The server answers for a device, an account and nobody (the
  browser's languages, matched on the server). A pack's texts come from the
  server under a content hash, because a package from Packagist has no
  sources at build time. The device keeps the last answer for starting
  without a network.
- **Backend: the English text is the key;** German lives in a JSON file.
  **Frontend: nested keys.** Tests fail on a key missing in either language,
  a placeholder mismatch, or German literals outside translation calls.
- **Whatever reaches several people is built per language.** Push texts are
  produced per recipient language; mails follow the recipient.
- **Stored content is written once** in the household's language and does not
  change later. Background jobs run in the default language.
- **Dates and numbers use `Intl` with the active language,** never a hard-coded
  locale string. Business logic computes in the household's time zone, never the
  device's.

## Operations

Depth: [AGENTS.md, Operations](../AGENTS.md#operations),
[`docs/installation.md`](installation.md), [`docs/kiosk.md`](kiosk.md).

- **One image, several households.** A single container image serves wall,
  phone and admin; a second process type runs the queue and scheduler.
  Compose files cover a Traefik setup and a standalone Caddy setup.
- **The operator is an account with no household,** created from the command
  line. Their admin shows an extra Operations group: all households (create,
  suspend, open support access), plugins, extensions.
- **New households do not start empty.** Onboarding creates setup steps for
  the first parent and routines, rewards and a shopping list. Only on
  creation, never retroactively.
- **Accounts are invited, not created with a password.** Mail can be
  turned off entirely (`FAHOBA_MAIL=off`), in which case accounts get
  passwords set in the admin and the mail routes answer 404.
- **Zero-downtime rollouts.** The rollout script starts the new container
  beside the old, waits for health, then removes the old one. That is why
  migrations must be backward compatible, why every scheduled task runs on
  one server only, and why web servers stop last.
- **Content security policy from one place,** with the app running no inline
  scripts so its header never changes per build. Plugins add hosts through a
  narrow, validated path.
- **Dependency audits stop the image build.** Exceptions are listed with a
  reason.
- **Proxies must be configured correctly,** otherwise an IP restriction is
  worthless: either nothing is trusted (the proxy's address is seen) or too
  much (anyone chooses their address).
- **A household can be copied out.** An export/import pair carries one family
  into its own database for inspection, scrubbing tokens, credentials and push
  subscriptions so a local copy cannot sync with or push to the real family. It
  is not a backup; `deploy/backup.sh` is, and the application key must be kept
  separately because credentials are unreadable without it.
- **Licence:** PolyForm Shield 1.0.0 for the core and bundled plugins
  (anyone may run it for themselves; offering it as a competing hosted product
  is reserved to the licensor), MIT for `fahoba/plugin-api`, the example plugin
  and generated templates. Contact: CopeX GmbH, office@copex.io.
