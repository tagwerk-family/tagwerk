# Writing a Fahoba plugin

A plugin is a Composer package that brings Fahoba something new without
touching the core. It can add a dashboard tile, a provider (a calendar,
shopping lists, recipes, weather …), a rule for the photo frame, a whole area
with its own menu entry, routes and tables, or a server extension.

A plugin builds on **`fahoba/plugin-api`** and nothing else of Fahoba. The
API is MIT licensed: a plugin built only on it may carry any licence you
choose. Fahoba itself is under PolyForm Shield 1.0.0. Classes under `App\`
belong to the core, change without notice and fall under its licence. Do not
use them.

## Start in one command

Without a Fahoba checkout: open
[`fahoba-plugin-template`](https://github.com/fahoba/fahoba-plugin-template),
press **Use this template**, rename `acme/example` and the `Acme\Example`
namespace to your own, and run `composer install && vendor/bin/phpunit`.

From a Fahoba checkout (`apps/platform`, inside DDEV):

```bash
php artisan fahoba:make-plugin acme/waste --widget
php artisan fahoba:make-plugin acme/nextcloud --provider=calendar
php artisan fahoba:make-plugin acme/board --area
php artisan fahoba:make-plugin acme/violet --theme
composer require acme/waste
```

This writes `plugins/waste/`: `composer.json`, the plugin class, one widget,
provider or area, a PHPUnit test, a CI workflow, an `AGENTS.md` for coding
assistants and a README. `plugins/*` is a Composer path
repository, so `composer require` finds the package there. Choose a licence
and put it into `LICENSE` and `composer.json`. The generator leaves it open
(`proprietary`).

`--provider` takes one of `calendar`, `lists`, `tasks`, `recipes`, `photos`,
`geocoding`, `weather`, `holidays`, `school_holidays`. The stub implements
the area's base contract. Every method except `key()`, `label()` and
`isConfigured()` throws until you write it.

## The package

```json
{
    "name": "acme/waste",
    "require": { "php": "^8.5", "fahoba/plugin-api": "^0.1" },
    "autoload": { "psr-4": { "Acme\\Waste\\": "src/" } },
    "extra": {
        "fahoba": {
            "provider": "Acme\\Waste\\WastePlugin",
            "csp": { "img-src": ["https://cdn.acme.example"] },
            "frontend": "frontend"
        }
    }
}
```

- **`provider`** names your plugin class. Fahoba loads it itself. Do not list
  it in `extra.laravel`: Laravel's discovery would register it outside
  Fahoba, and the plugin is then reported as not loaded.
- **`csp`** names hosts the wall's browser may load from: `img-src`,
  `media-src`, `connect-src`, `font-src`, only `https://` hosts (`*.` for
  subdomains). Scripts and styles from elsewhere are never allowed. A wrong
  entry leaves your plugin out. Hosts known only at runtime are offered with
  `offer('csp', …)` and a `ContributesToCsp` class.
- **`frontend`** (optional) is a Nuxt layer for an area's view, see *Areas*.

## The plugin class

```php
final class WastePlugin extends \Fahoba\PluginApi\Plugins\Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(\Fahoba\PluginApi\Plugins\Catalog::class);
        $catalog->offer('widgets', 'acme/waste', WasteWidget::class);
    }

    protected function bootPlugin(): void
    {
        // routes, listeners, migrations
    }
}
```

`registerPlugin()` and `bootPlugin()` take the place of Laravel's `register()`
and `boot()`. `bootPlugin()` gets its dependencies injected, like `boot()`.

**A broken plugin never stops Fahoba.** A missing class, an exception in
either method or an invalid offer leaves your plugin out. Everything it
offered is withdrawn, and the log says `Plugin acme/waste not loaded: …` once
an hour. The operator sees the reason under Operations → Plugins.

Gate what you register in `bootPlugin()` on your area or offer, not on
success: routes or listeners registered before an exception stay registered
until the next deploy.

### What can be offered

| `offer($area, …)` | Contract | |
|---|---|---|
| `widgets` | `Dashboard\DataWidget` | a dashboard tile |
| `calendar`, `lists`, `tasks`, `recipes`, `photos`, `geocoding`, `weather`, `holidays`, `school_holidays` | the area's base contract, e.g. `Calendar\CalendarProvider` | a provider |
| `photo_rules` | `Photos\PhotoRule` | how the frame picks pictures |
| `areas` | `Areas\Area` | a household area with menu entry |
| `panel` | `Plugins\ConfiguresPanel` | admin pages and resources |
| `export` | `Plugins\ExportRules` | your tables in the household export |
| `extensions` | `Plugins\Extension` | a server feature the operator switches on |
| `csp` | `Security\ContributesToCsp` | hosts known only at runtime |
| `languages` | `Languages\LanguagePack` | a language beyond German and English |
| `themes` | `Themes\Theme` | a design: colours, font and corners for wall and phone |
| `notices` | `Households\HouseholdNotice` | a sentence for one household on wall, phone and admin; `key` lets an account close it, `NoticeAudience` names who sees it (household, managers, operator) |

**Secrets on a move.** An `ExportRules` class may also implement
`Plugins\MovesSecrets`: encrypted columns that still work on another server
(a webhook's signing secret). When a family moves with its passwords they
travel in plain text and are encrypted again there; everything else in
`scrubbed()` stays behind.

**Household events.** `Households\HouseholdDeleting` is dispatched before a
household and everything it owns are deleted — throw to stop it.
`Households\HouseholdImported` follows a household that moved in from
another server with new ids. An admin page that must stay open while the
household is read-only implements `Households\AvailableWhileReadOnly`.

**Keys carry your vendor** (`acme/waste`), and built-in ones never do. The
`fahoba/` prefix is reserved. A key is stored in households' data, so never
change it once released. `offer()` only records the class; Fahoba creates
it when needed, once per request. **A constructor must do nothing:** no
database, no network.

## Dashboard tiles

A widget returns data, and the wall draws it in its own look. You ship no
Vue and no HTML; texts stay text.

```php
final class WasteWidget implements DataWidget, HasSettings, HandlesActions
{
    public function label(): string { return __('Waste collection'); }
    public function description(): string { return __('Which bin goes out next.'); }
    public function area(): ?string { return null; } // or an area key, e.g. 'meals'

    public function settings(): array
    {
        return [
            SettingField::text('street', __('Street'), required: true),
            SettingField::select('days', __('Days ahead'), ['7' => '7', '14' => '14']),
            SettingField::toggle('paper', __('Show paper')),
            SettingField::secret('token', __('API key')),
        ];
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        return WidgetContent::table(['Bin', 'Day'], [['Paper', 'Mon'], ['Residual', 'Thu']]);
    }

    public function act(WidgetContext $context, array $settings, string $action): WidgetContent
    {
        // 'done' was tapped
        return $this->content($context, $settings);
    }

    public function guarded(string $action): bool
    {
        return $action !== 'done'; // guard whatever you did not offer
    }
}
```

**Content shapes** (`WidgetContent::`):

| Shape | Arguments | |
|---|---|---|
| `metric` | value, caption | one big figure |
| `list` | rows `{text, detail}` | at most 20 |
| `table` | header, rows | at most 20, cut or padded to the header |
| `pairs` | rows `{label, value}` | at most 20 |
| `progress` | value, max, caption | a bar |
| `image` | URL, caption | a path here or a host from `csp` |
| `actions` | buttons `{name, label}`, caption | at most 6, names `a-z0-9_-` |

- **`content()` is asked at most once a minute per tile.** A slow source
  caches longer itself. If it throws, the tile shows a sentence and the error
  goes to the log.
- **`WidgetContext`** carries the household's id, time zone, language and
  enabled areas (`has('meals')`). Compute days in its time zone, never the
  server's.
- **Settings** (`HasSettings`) become the tile's form. The server checks the
  answer against your fields. A secret is stored encrypted, never sent back
  to a device, and reaches `content()` decrypted. A widget without
  `HasSettings` shows no form.
- **Buttons** (`HandlesActions`): anyone who sees the tile may tap them. On a
  hallway tablet that is everyone walking past. Mark anything a parent should
  decide as `guarded`: a tablet then asks for the parent PIN, a phone account
  needs household management. `act()` receives any well-formed name, not only
  the ones you offered, so guard or ignore unknown names.
- **Settings may be empty.** A tile from the picker starts without them; the
  form follows. `content()` must cope with missing fields, e.g. by asking for
  them in a metric.
- **`server()` throws after three failed connections.** Inside `content()`
  or `act()` that costs the tile, which shows a sentence.

## Providers

A provider implements its area's base contract and **only the capabilities
the service really has**: `ListsCalendars`, `SyncsEvents`, `WritesEvents`,
`WatchesChanges` for calendars, `SyncsItems` for lists, and so on. Fahoba
asks for the capability; a method that means "not supported" is a missing
interface, never an empty method.

- **Connecting**: `UsesCredentials` asks for fields
  (`credentialFields()`) and checks them once (`verifyCredentials()`). Fahoba
  stores them encrypted per household. `UsesOAuth` leads through a consent
  screen. `DescribesSetup` supplies the wizard's icon and explanation.
- **The account** arrives as `Connections\ConnectedAccount`: credentials,
  settings, token, `storeToken()`, `markInvalid()`. Throw
  `AuthorizationLost` when the service refuses the login in the background.
- **Never read credentials from `config/` or `.env`.** On a server with
  several households that would hand one family's account to another.

## Areas

```php
$catalog->offer('areas', 'acme/board', BoardArea::class);
PluginRoutes::api('acme/board', fn () => require __DIR__.'/../routes/api.php'); // in bootPlugin()
```

- An area switches off per household like a built-in one. Its routes answer
  404 then. `PluginRoutes::api()` puts them under `/api/acme/board/…` behind
  sign-in, the household and the area's switch.
- **The current household** comes from `Households\CurrentHousehold`: `id()`,
  `name()`, `timezone()`, `locale()`.
- **Own tables** carry `household_id` and `use HouseholdScoped;`. Without a
  household nothing is visible, which is intended: a forgotten context then
  shows nothing instead of another family's data. Background jobs set the
  household deliberately. Load migrations with `loadMigrationsFrom()` and
  name your tables in `ExportRules` if they hold server data or secrets.
- **Interfaces to the outside** (a feed, a webhook, anything answering
  another program) implement `Areas\OptIn`: off until a parent ticks it.
- **Admin pages** come through `offer('panel', …)` with `ConfiguresPanel`.
- **A view on the wall** is a Nuxt layer in `frontend/`; it registers itself
  with `registerAreaView(key, component)`. See `fahoba/example-area`.

## Language packs

```bash
php artisan fahoba:make-plugin acme/fahoba-fr --language=fr
```

A pack brings a language beyond German and English. It is texts only, so the
generator sets MIT. It starts as the English reference: translate the values
and leave the keys alone.

```
lang/fr.json              server texts; the English text is the key
lang/fr/validation.php    Laravel's groups (validation, auth)
frontend/fr.json          wall and phone, nested keys like the core's en.json
src/AcmeFahobaFrLanguage.php   code, name in the language itself, BCP 47 tag
```

- **Installed means offered.** Households, accounts and tablets can choose
  the language as soon as the operator installs the pack.
- **What is missing shows in English.** That also applies to a text whose
  placeholders differ from the English one (`:name`, `{count}`): a broken
  placeholder would swallow a name. Operations → Plugins lists every such
  text per pack.
- **Dates and numbers come from ICU** via `intlTag()`. A pack has no
  formats.
- **The first text is already in the language.** The app asks
  `/api/language` before it shows anything, and loads a pack's texts from
  the server. A pack therefore needs no frontend build and may come from
  Packagist.
- **A pack never replaces `de` or `en`,** and two packages offering one code
  leave the second out. Corrections to German or English go into Fahoba.
- **Test:** `assertLanguagePackComplete(new AcmeFahobaFrLanguage)` lists every
  text that would show in English. After a Fahoba update it tells you what
  is new. Set `extra.fahoba.language.complete-for` in `composer.json` to the
  Fahoba version you translated against; the Plugins page shows it next to
  the running one.
- The reference lives in `fahoba/plugin-api` (`resources/reference`).
  Inside Fahoba, `php artisan fahoba:language-reference` rewrites it after
  texts change; a test fails while it is stale.
- `fahoba/example-language` (development only) is a deliberately incomplete
  French pack.
- Right-to-left languages are not supported yet.

## Design packs

```bash
php artisan fahoba:make-plugin acme/violet --theme
```

A design changes how wall and phone look — colours, font, corners, the glass
gradient — without touching a component. It is one stylesheet that sets
Fahoba's design tokens, so the generator sets MIT like for a language.

```
resources/theme.css         the tokens, light and dark
src/AcmeVioletTheme.php     name() and stylesheet()
```

```css
:root[data-design] {                       /* light */
  --accent: #6d28d9;
  --corner-2xl: 1.25rem;                   /* said once: applies to both */
  --font-body: Hand, sans-serif;
}
:root[data-design][data-theme="dark"] {    /* dark, required */
  --accent: #c4b5fd;
}
:root[data-design][data-look="glass"][data-backdrop] {
  --backdrop: radial-gradient(at 10% 10%, #c4b5fd 0, transparent 55%);
}
@font-face { font-family: Hand; src: url(hand.woff2) format("woff2"); }
```

- **Only tokens.** Selectors are `:root[data-design]`, narrowed by
  `data-theme`, `data-look` and `data-backdrop`; properties are the tokens in
  `fahoba/plugin-api`'s `resources/reference/design.json` (with the core's
  values). No classes, no `@import`, no `@media`, no `!important`. A design
  that styled components would break with the next release; a token is a
  promise (see “What stays stable”).
- **Light and dark are both required**, and text must stay readable on its
  background in both: ink on canvas and surface, `--accent-ink` on
  `--accent`, `--today-ink` on `--today` at WCAG AA (4.5:1).
- **A rule wins where it narrows as far as the core's.** The served file
  weighs each rule exactly like the core's rule with the same attributes:
  light colours give way to the core's dark ones, plain surfaces to the
  glass look's translucent ones. The household's gradient is
  `[data-look="glass"][data-backdrop]`.
- **Fonts and pictures lie beside the stylesheet** (`woff2`, `webp`, `png`,
  `jpg`, `avif`) and are named by file name. Nothing loads from elsewhere —
  the wall must start without network, and the server's Content Security
  Policy would refuse it anyway.
- **Served, not built.** The server checks the file and sends it from
  `/api/design/<vendor>/<name>/<hash>.css`, so a design may come from
  Packagist. A stylesheet that breaks a rule withdraws the package
  (Operations → Plugins says why).
- **Installed means offered.** The household picks one under Household →
  Light and dark theme; a tablet or account may pick its own or Fahoba's (Settings →
  Appearance). A removed package falls back to Fahoba's own.
- **Test:** `assertThemeValid(new AcmeVioletTheme)` checks what the server
  checks.
- `fahoba/example-theme` (development only) is “Fjord”.

## HTTP, caching, translations

- **`Http\Outbound`** (inject it): `server()` for an API with a fixed host
  (limits, a `Fahoba/<version>` User-Agent, retries on 429 and 5xx);
  `householdGet()`/`householdSend()` for **any address a household typed
  in**. Those refuse the server's private network, which a plain `Http::`
  call would happily reach.
- **Cache** with Laravel's `Cache`. Keys carry what makes the answer
  differ: `"acme-waste:{$context->householdId}:{$context->locale}:…"`.
- **Texts** go through `__()`. Load your `lang/` in `registerPlugin()`:
  `$this->loadJsonTranslationsFrom(__DIR__.'/../lang')`. In `bootPlugin()`
  it comes too late.

## Testing

- **In your own repository**, `use Fahoba\PluginApi\Testing\PluginAssertions;`
  in a PHPUnit `TestCase`. It needs no Fahoba checkout and checks what would
  otherwise only show on a wall:
  - `assertWidgetWorks($widget, $settings = [])`: a label, a settings form
    without a key twice, content for **empty** settings (a new tile starts
    without any) and for yours, content JSON can carry, and an unknown
    button name that is guarded or refused.
  - `assertLanguagePackComplete($pack)`, `assertThemeValid($theme)`: see
    the sections above.
  - `assertPluginKey('acme/waste')`: vendor/name, lower case, not `fahoba/`.
  - `widgetContext(timezone: …, locale: …, areas: [...])` builds the
    household a tile sees.
- The generated `.github/workflows/tests.yml` runs them on every push.
- `WidgetContent::image()` without Fahoba allows only local paths.
- **Inside a Fahoba checkout**, feature tests live in the app's `tests/`.
  `ProviderContractTest` checks every installed provider: contract, key, a
  constructor that does nothing, a complete wizard description.

## Security

A plugin runs in Fahoba's process with every right, including `APP_KEY` and
so every household's credentials. There is no sandbox. Operators install
only plugins from sources they trust and pin versions in `composer.lock`.

## What stays stable

- **The contract is `fahoba/plugin-api` and `extra.fahoba`, nothing else.**
  Anything under `App\`, database tables, routes and the frontend's
  internals may change in any release.
- **`0.x` until plugins outside this repository have been built against
  it.** Until then a minor release (`0.1` → `0.2`) may change a contract,
  and the CHANGELOG says what to change. Require `^0.1`, not `*`.
- **From `1.0.0` on, SemVer.** Adding a method to an interface, removing
  or renaming anything, or narrowing what a method accepts is a major
  release. New interfaces, new `WidgetContent` shapes and new optional
  parameters are minor.
- **A deprecated part stays for at least one minor release**, marked
  `@deprecated` with what replaces it.
- **Keys and stored values never change meaning.** Domain event names are
  added, never renamed.
- **Design tokens are part of the contract** (`resources/reference/design.json`):
  added in a minor release, renamed or removed only in a major one.

## Installing a plugin

The image ships with no plugin enabled. An operator names the ones wanted in
`FAHOBA_PLUGINS` (`deploy/.env`), separated by spaces:

```bash
FAHOBA_PLUGINS="fahoba/caldav fahoba/two-factor acme/waste:^1.2"
```

- The container runs `composer require` for them on every start, before it
  migrates (`deploy/install-plugins.sh`). Fahoba's own come from the
  sources in the image, at its version; their dependencies at the versions
  `composer.lock` pins. Anything else comes from Packagist; a private
  repository needs `COMPOSER_AUTH`.
- One that cannot be installed — a typo, no network, a dependency that
  conflicts with Fahoba's — stops the start, and the log says why.
- **A plugin with a frontend layer** (`extra.fahoba.frontend`) is built into
  the app and needs an image of its own: add it with `composer require` in a
  Fahoba checkout and build `deploy/Dockerfile`. The start refuses one
  otherwise. Providers, widgets, extensions and areas without screens need
  no build.
- In development (DDEV) Fahoba's own plugins are dev dependencies and always
  installed, so the tests cover them.

## Versions and publishing

- Publish your plugin on Packagist like any Composer package.
- The bundled plugins, the API and the template are developed in the
  Fahoba repository and split into read-only repositories
  (`fahoba-<name>`) on every release. Issues and pull requests go to the
  main repository.

## Directory

| Package | What |
|---|---|
| `fahoba/bring` | Bring! shopping lists, both ways |
| `fahoba/caldav` | CalDAV calendars (Nextcloud, iCloud, mailbox.org …) |
| `fahoba/ics-feed` | a secret calendar address per family or person |
| `fahoba/kochwiki` | recipes from Koch-Wiki |
| `fahoba/mcp` | Model Context Protocol for AI assistants |
| `fahoba/openapi` | `/api/openapi.json` |
| `fahoba/social-login` | sign-in with Google and others |
| `fahoba/two-factor` | a second factor for sign-in |
| `fahoba/webdav-backup` | nightly backup to WebDAV |
| `fahoba/webhooks` | signed POSTs on household events |
| `fahoba/example-area` | reference: an area and a tile with settings and buttons |
| `fahoba/example-language` | reference: a language pack, French for the first screens |

Built one? Open a pull request that adds a row below — package name, one
line, a link. Listed are plugins with a licence, a README and passing
tests; the list is no review of their code (see *Security*).

| Package | What | By |
|---|---|---|

## Good first plugins

Ideas that fit the API as it is, smallest first. Say in
[Discussions](https://github.com/fahoba/fahoba/discussions) which one you
take, so two people do not build the same.

- **Your language** — `--language=<code>` writes the pack; translating
  needs no PHP (`languages`).
- **Waste collection** — a tile with the next bins, from a city's ICS or
  open-data feed (`widgets`, `Outbound::householdGet`).
- **Public transport departures** — a tile for the nearest stop, e.g. from
  a GTFS-realtime or a national API (`widgets`, `table`, settings with a
  secret for the API key).
- **Pollen or air quality** — a `pairs` tile (`widgets`).
- **School holidays for another country** — a `school_holidays` provider.
- **Mealie or another recipe server** — a `recipes` provider with
  `UsesCredentials` (the built-in Tandoor provider is the model).
- **Microsoft To Do** — a `tasks` provider; the contract waits for a
  second implementation to check itself against.
- **Home Assistant** — a tile with a few sensor values and buttons for
  scenes (`HandlesActions`, scenes `guarded`). Only through a public
  address (Nabu Casa or a reverse proxy): `householdGet()` refuses the
  home network, on purpose.

## Help

- Questions, ideas and showing what you built:
  [Discussions](https://github.com/fahoba/fahoba/discussions).
- A bug in the API or the guide: an
  [issue](https://github.com/fahoba/fahoba/issues/new/choose).
- A security problem: never an issue — see
  [`SECURITY.md`](../SECURITY.md).
- [`/llms.txt`](../llms.txt) points coding assistants at what they need.
