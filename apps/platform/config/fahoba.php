<?php

declare(strict_types=1);

use App\Integrations\Calendar\Builtin\BuiltinCalendarProvider;
use App\Integrations\Calendar\Google\GoogleCalendarProvider;
use App\Integrations\Geocoding\Nominatim\NominatimGeocoder;
use App\Integrations\Holidays\Yasumi\YasumiHolidays;
use App\Integrations\Lists\GoogleTasks\GoogleTasksListProvider;
use App\Integrations\Photos\Amazon\AmazonPhotoSource;
use App\Integrations\Photos\Builtin\BuiltinPhotoSource;
use App\Integrations\Photos\Folder\FolderPhotoSource;
use App\Integrations\Photos\Rules\LookBackRule;
use App\Integrations\Recipes\Builtin\BuiltinRecipeProvider;
use App\Integrations\Recipes\Tandoor\TandoorRecipeProvider;
use App\Integrations\SchoolHolidays\Austria\AustrianSchoolHolidays;
use App\Integrations\SchoolHolidays\Ics\IcsSchoolHolidays;
use App\Integrations\Tasks\Builtin\BuiltinTaskProvider;
use App\Integrations\Tasks\GoogleTasks\GoogleTasksTaskProvider;
use App\Integrations\Weather\OpenMeteo\OpenMeteoWeather;

return [

    /*
    |--------------------------------------------------------------------------
    | First-party plugins
    |--------------------------------------------------------------------------
    |
    | The Composer packages that may offer keys under `fahoba/`. A key is
    | stored data (connections, meal plans, switched areas), so no other
    | package may claim one — `fahoba/bring` stays Fahoba's own.
    |
    */

    'first_party' => [
        'fahoba/bring',
        'fahoba/caldav',
        'fahoba/example-area',
        'fahoba/example-language',
        'fahoba/example-theme',
        'fahoba/ics-feed',
        'fahoba/kochwiki',
        'fahoba/mcp',
        'fahoba/openapi',
        'fahoba/social-login',
        'fahoba/two-factor',
        'fahoba/webdav-backup',
        'fahoba/webhooks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | One list per area: key in the database → class. A new provider is a
    | class and an entry here; the domain logic behind it stays untouched.
    |
    | Server-wide credentials (Google's OAuth client) live in
    | config/services.php. Anything that belongs to one family — Bring,
    | Tandoor, a photo folder — lives on the household's connection.
    |
    */

    'providers' => [

        'calendar' => [
            'builtin' => BuiltinCalendarProvider::class,
            'google' => GoogleCalendarProvider::class,
        ],

        'lists' => [
            'google' => GoogleTasksListProvider::class,
        ],

        'tasks' => [
            'builtin' => BuiltinTaskProvider::class,
            'google' => GoogleTasksTaskProvider::class,
        ],

        'photos' => [
            'builtin' => BuiltinPhotoSource::class,
            'folder' => FolderPhotoSource::class,
            'amazon' => AmazonPhotoSource::class,
        ],

        'geocoding' => [
            'nominatim' => NominatimGeocoder::class,
        ],

        'holidays' => [
            'yasumi' => YasumiHolidays::class,
        ],

        'school_holidays' => [
            'austria' => AustrianSchoolHolidays::class,
            'ics' => IcsSchoolHolidays::class,
        ],

        'weather' => [
            'open-meteo' => OpenMeteoWeather::class,
        ],

        'recipes' => [
            'builtin' => BuiltinRecipeProvider::class,
            'tandoor' => TandoorRecipeProvider::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Photo folders
    |--------------------------------------------------------------------------
    |
    | Directory on the server holding one folder per household, named after
    | its id (`<photo_folders>/7`). The folder and Amazon Photos sources only
    | read below the household's own folder. Empty: those sources find nothing.
    |
    */

    'photo_folders' => env('FAHOBA_PHOTO_FOLDERS'),

    /*
    |--------------------------------------------------------------------------
    | Photo rules
    |--------------------------------------------------------------------------
    |
    | Which pictures the frame prefers, chosen per household. Without a rule
    | every source contributes evenly. Plugins add rules through
    | PluginCatalog::offer('photo_rules', 'vendor/name', Rule::class).
    |
    */

    'photo_rules' => [
        LookBackRule::KEY => LookBackRule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin networks
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs or CIDR ranges that may reach /admin,
    | e.g. the tailnet `100.64.0.0/10`. Empty: reachable from anywhere. Behind
    | a reverse proxy it needs FAHOBA_TRUSTED_PROXIES, otherwise every
    | request comes from the proxy's address.
    |
    */

    'admin_allowed_ips' => env('FAHOBA_ADMIN_ALLOWED_IPS'),

    /*
    | Where "Move to Fahoba Cloud" on the export page leads. Empty hides
    | the line; nothing else in the admin mentions the hosted service.
    */
    'hosted_url' => env('FAHOBA_HOSTED_URL', ''),

    // Largest export the admin accepts for a move — Livewire's temporary
    // upload stops at 12 MB unless raised there and in php.ini and nginx
    // too. Bigger ones: fahoba:import-household --new.
    'import_max_mb' => (int) env('FAHOBA_IMPORT_MAX_MB', 12),

    'trusted_proxies' => env('FAHOBA_TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | `report-only` (default): browsers report violations to /api/csp-report
    | and block nothing. `enforce`: they block. `off`: no header at all.
    | See App\Security\ContentSecurityPolicy.
    |
    */

    'csp' => env('FAHOBA_CSP', 'report-only'),

    /*
    |--------------------------------------------------------------------------
    | Outgoing mail
    |--------------------------------------------------------------------------
    |
    | `on` (default) or `off`. Off closes every way that would send a mail —
    | invitations, password reset, sign-in links, confirming a new address —
    | and accounts get their password from a parent or the operator in the
    | admin. Explicit on purpose: `MAIL_MAILER=log` is the development
    | default and must keep the mail paths visible.
    |
    */

    'mail' => env('FAHOBA_MAIL', 'on'),

    /*
    |--------------------------------------------------------------------------
    | Home page widgets
    |--------------------------------------------------------------------------
    |
    | Key → description and default size in grid cells (12 columns). A new
    | widget is an entry here and a Vue component with the same key;
    | `feature` hides it when the household has disabled the area.
    |
    */

    'widgets' => [

        'calendar' => [
            'label' => 'Termine',
            'description' => 'Was als Nächstes ansteht.',
            'feature' => 'calendar',
            'w' => 6,
            'h' => 6,
        ],

        'tasks' => [
            'label' => 'Aufgaben',
            'description' => 'Fortschritt je Person für heute.',
            'feature' => 'tasks',
            'w' => 3,
            'h' => 4,
        ],

        'stars' => [
            'label' => 'Sterne',
            'description' => 'Der Sternenstand der Kinder.',
            'feature' => 'points',
            'w' => 3,
            'h' => 3,
        ],

        'rewards' => [
            'label' => 'Belohnungen',
            'description' => 'Was sich einlösen lässt.',
            'feature' => 'points',
            'w' => 3,
            'h' => 3,
        ],

        'members' => [
            'label' => 'Familie',
            'description' => 'Wer zum Haushalt gehört.',
            'w' => 3,
            'h' => 2,
        ],

        'meals' => [
            'label' => 'Essen',
            'description' => 'Die Mahlzeiten von heute.',
            'feature' => 'meals',
            'w' => 3,
            'h' => 3,
        ],

        'lists' => [
            'label' => 'Liste',
            'description' => 'Offene Artikel einer Liste.',
            'feature' => 'lists',
            'w' => 3,
            'h' => 4,
        ],

        'weather' => [
            'label' => 'Wetter',
            'description' => 'Am Ort des Haushalts.',
            'w' => 3,
            'h' => 2,
        ],

        'clock' => [
            'label' => 'Uhr',
            'description' => 'Uhrzeit und Datum, groß.',
            'w' => 3,
            'h' => 2,
        ],

        'photo' => [
            'label' => 'Foto',
            'description' => 'Ein Bild, das wechselt.',
            'feature' => 'photos',
            'w' => 3,
            'h' => 3,
        ],

    ],

];
