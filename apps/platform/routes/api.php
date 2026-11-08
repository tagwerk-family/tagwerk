<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarChoiceController;
use App\Http\Controllers\Api\CalendarWebhookController;
use App\Http\Controllers\Api\CspReportController;
use App\Http\Controllers\Api\DesignController;
use App\Http\Controllers\Api\DevicePairingController;
use App\Http\Controllers\Api\LanguageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PhotoAlbumController;
use App\Http\Controllers\Api\PhotoManageController;
use App\Http\Controllers\Api\PhotoUploadController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\WallAgendaController;
use App\Http\Controllers\Api\WallDashboardController;
use App\Http\Controllers\Api\WallEventController;
use App\Http\Controllers\Api\WallHistoryController;
use App\Http\Controllers\Api\WallListController;
use App\Http\Controllers\Api\WallMealController;
use App\Http\Controllers\Api\WallPhotoController;
use App\Http\Controllers\Api\WallPinController;
use App\Http\Controllers\Api\WallPointsController;
use App\Http\Controllers\Api\WallRecipeController;
use App\Http\Controllers\Api\WallRewardController;
use App\Http\Controllers\Api\WallTaskAssignmentController;
use App\Http\Controllers\Api\WallTaskCompletionController;
use App\Http\Controllers\Api\WallTaskController;
use App\Http\Controllers\Api\WallTimetableController;
use App\Http\Controllers\Api\WallWeatherController;
use App\Http\Middleware\EnsureWritable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public: the language the app starts in — sign-in and pairing screens too.
Route::middleware(['device.cookie', 'user.cookie', 'throttle:language'])->group(function (): void {
    Route::get('/language', [LanguageController::class, 'show']);
    Route::get('/language/{code}/{hash}.json', [LanguageController::class, 'messages'])
        ->where(['code' => '[a-z]{2}', 'hash' => '[0-9a-f]{12}']);
});

// Public: an installed design's stylesheet and the files it names.
Route::middleware('throttle:language')->group(function (): void {
    $design = ['vendor' => '[a-z0-9][a-z0-9-]*', 'name' => '[a-z0-9][a-z0-9-]*', 'hash' => '[0-9a-f]{12}'];
    Route::get('/design/{vendor}/{name}/{hash}.css', [DesignController::class, 'stylesheet'])->where($design);
    Route::get('/design/{vendor}/{name}/{hash}/{file}', [DesignController::class, 'asset'])
        ->where([...$design, 'file' => '[A-Za-z0-9][A-Za-z0-9._-]*']);
});

// Public: a freshly set-up tablet redeems its pairing code here.
// Public: browsers report what the Content Security Policy blocked.
Route::post('/csp-report', CspReportController::class)->middleware('throttle:csp');

Route::post('/devices/pair', [DevicePairingController::class, 'store'])->middleware('throttle:pair');

/*
 * Personal sign-in for the phone PWA. The wall has no sign-in — it carries a
 * device token.
 */
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/auth/methods', [AuthController::class, 'methods']);
Route::post('/auth/password/forgot', [PasswordResetController::class, 'request'])->middleware(['mail', 'throttle:login']);
Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])->middleware(['mail', 'throttle:login']);
Route::post('/auth/invitation', [PasswordResetController::class, 'accept'])->middleware(['mail', 'throttle:login']);
Route::post('/auth/link', [AuthController::class, 'requestLink'])->middleware(['mail', 'throttle:login']);
Route::post('/auth/link/redeem', [AuthController::class, 'redeemLink'])->middleware(['mail', 'throttle:login']);
Route::post('/auth/two-factor', [AuthController::class, 'secondFactor'])->middleware('throttle:second-factor');

/*
 * `EnsureWritable::SELF`: still allowed in a read-only household — the
 * account's and the device's own matters (signing in and out, the PIN,
 * push, messages and notices, preferences, the own dashboard's layout),
 * never the family's data.
 */
Route::group(['middleware' => ['user.cookie', 'auth:sanctum', 'household'], EnsureWritable::SELF => true], function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/locale', [AuthController::class, 'updateLocale']);
    Route::put('/auth/household', [AuthController::class, 'switchHousehold']);
    Route::delete('/auth/household', [AuthController::class, 'leaveHousehold']);
    Route::post('/auth/invitations/{household}', [AuthController::class, 'acceptInvitation'])->whereNumber('household');
    Route::delete('/auth/invitations/{household}', [AuthController::class, 'declineInvitation'])->whereNumber('household');
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/account', [AccountController::class, 'show']);
    Route::delete('/auth/account', [AccountController::class, 'destroy'])->middleware('throttle:login');
    Route::get('/calendars/choice', [CalendarChoiceController::class, 'show']);
    Route::put('/calendars/choice', [CalendarChoiceController::class, 'update']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);
    Route::post('/notices/dismiss', [NotificationController::class, 'dismiss']);

    Route::get('/push/key', [PushSubscriptionController::class, 'key']);
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'destroy']);
});

Route::middleware(['device.cookie', 'user.cookie', 'auth:sanctum', 'household'])->group(function (): void {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::get('/devices/me', [DevicePairingController::class, 'me']);
    Route::get('/session', SessionController::class);

    // Behind the parent PIN: a child unpairing the hallway tablet would
    // leave the family with a start page.
    Route::group([EnsureWritable::SELF => true], function (): void {
        Route::delete('/devices/me', [DevicePairingController::class, 'destroy'])
            ->middleware('permission:household.manage');
    });
});

/*
 * Without authentication: the call comes from Google. Protected by the
 * channel token we pass along when creating the channel.
 */
Route::post('/calendar/{provider}/webhook', CalendarWebhookController::class)
    ->name('calendar.webhook');

Route::middleware(['device.cookie', 'user.cookie', 'auth:sanctum', 'household'])->group(function (): void {
    // Connection data for the WebSocket — the same for wall and phone.
    Route::get('/realtime/config', RealtimeController::class);

    Route::group([EnsureWritable::SELF => true], function (): void {
        // Light or dark — the choice applies per device on the wall, per person on the phone.
        Route::get('/theme', [ThemeController::class, 'show']);
        Route::put('/theme', [ThemeController::class, 'update']);

        // Start area and default views -- also per device.
        Route::get('/preferences', [PreferenceController::class, 'show']);
        Route::put('/preferences', [PreferenceController::class, 'update']);
    });

    Route::get('/wall/agenda', WallAgendaController::class)
        ->name('wall.agenda');

    // The dashboard is the home page, not a tile that can be switched off:
    // every paired device and every account reads its own (see below).
    Route::get('/wall/dashboard', [WallDashboardController::class, 'index']);
    Route::get('/wall/dashboard/{widget}/content', [WallDashboardController::class, 'content']);
    Route::post('/wall/dashboard/{widget}/action/{action}', [WallDashboardController::class, 'act'])
        ->where('action', '[a-z0-9_-]{1,40}')
        ->middleware('throttle:widget-action');

    // Weather belongs to no area: it sits on the dashboard, and that is the
    // home page, not a tile that can be switched off.
    Route::get('/wall/weather', WallWeatherController::class);

    /*
     * One group per area: when it is disabled for the household, its routes
     * do not exist. Otherwise "off" would only be a matter of display, and a
     * tablet with a stale navigation bar would keep writing.
     */
    Route::middleware('feature:calendar')->group(function (): void {
        Route::middleware('permission:calendar.edit_own')->group(function (): void {
            Route::post('/wall/events', [WallEventController::class, 'store']);
            Route::patch('/wall/events/{event}', [WallEventController::class, 'update']);
            Route::delete('/wall/events/{event}', [WallEventController::class, 'destroy']);
        });
    });

    Route::middleware('feature:lists')->group(function (): void {
        Route::get('/wall/lists', [WallListController::class, 'index']);
        Route::middleware('permission:lists.edit')->group(function (): void {
            Route::post('/wall/lists/{checklist}/items', [WallListController::class, 'storeItem']);
            Route::delete('/wall/lists/{checklist}/done', [WallListController::class, 'clearDone']);
            Route::patch('/wall/items/{listItem}', [WallListController::class, 'updateItem']);
            Route::delete('/wall/items/{listItem}', [WallListController::class, 'destroyItem']);
        });
    });

    Route::middleware('feature:tasks')->group(function (): void {
        /*
         * The history of completions. Anyone in the household may read it:
         * who ticked what is on the wall anyway.
         */
        Route::get('/wall/history', WallHistoryController::class);

        /*
         * How often whom, how reliably, how many stars. Parents only: it
         * compares children, which the wall does not do in passing.
         */
        Route::get('/statistics', StatisticsController::class)
            ->middleware('permission:household.manage');

        Route::get('/wall/tasks', [WallTaskController::class, 'index']);
        Route::get('/wall/tasks/progress', [WallTaskController::class, 'progress']);
        Route::middleware('permission:tasks.complete_own')->group(function (): void {
            Route::post('/wall/tasks/{task}/completion', [WallTaskCompletionController::class, 'store']);
            Route::delete('/wall/tasks/{task}/completion', [WallTaskCompletionController::class, 'destroy']);
        });
    });

    Route::middleware('feature:points')->group(function (): void {
        /*
         * Reading is open: their own score is what children go to the wall
         * for. Only what is reserved for parents is behind the PIN.
         */
        Route::get('/wall/points', [WallPointsController::class, 'index']);
        Route::get('/wall/rewards', [WallRewardController::class, 'index']);
        Route::post('/wall/rewards/{reward}/request', [WallRewardController::class, 'request'])
            ->middleware('permission:rewards.request');
    });

    Route::middleware('feature:recipes')->group(function (): void {
        Route::get('/wall/recipe-book', [WallRecipeController::class, 'index']);
        Route::get('/wall/recipe-book/suggestions', [WallRecipeController::class, 'suggestions']);
        // Before the routes below: a plugin's key carries a slash (`vendor/name`).
        Route::get('/wall/recipe-book/builtin/{recipe}/photo', [WallRecipeController::class, 'photo'])
            ->whereNumber('recipe');
        Route::get('/wall/recipe-book/builtin/{recipe}/pages/{page}', [WallRecipeController::class, 'page'])
            ->whereNumber('recipe')->whereAlphaNumeric('page');
        Route::get('/wall/recipe-book/{provider}/{recipe}', [WallRecipeController::class, 'show'])
            ->where(['provider' => WallRecipeController::PROVIDER, 'recipe' => '[^/]+']);
        Route::post('/wall/recipe-book/{provider}/{recipe}/ingredients', [WallRecipeController::class, 'ingredients'])
            ->where(['provider' => WallRecipeController::PROVIDER, 'recipe' => '[^/]+'])
            ->middleware('permission:lists.edit');
        Route::put('/wall/recipe-book/{provider}/{recipe}/favorite', [WallRecipeController::class, 'favor'])
            ->where(['provider' => WallRecipeController::PROVIDER, 'recipe' => '[^/]+']);
        Route::delete('/wall/recipe-book/{provider}/{recipe}/favorite', [WallRecipeController::class, 'unfavor'])
            ->where(['provider' => WallRecipeController::PROVIDER, 'recipe' => '[^/]+']);

        Route::middleware('permission:recipes.edit')->group(function (): void {
            Route::post('/wall/recipe-book', [WallRecipeController::class, 'store']);
            Route::post('/wall/recipe-book/link', [WallRecipeController::class, 'link'])->middleware('throttle:recipe-link');
            Route::patch('/wall/recipe-book/builtin/{recipe}', [WallRecipeController::class, 'update'])->whereNumber('recipe');
            Route::delete('/wall/recipe-book/builtin/{recipe}', [WallRecipeController::class, 'destroy'])->whereNumber('recipe');
            Route::post('/wall/recipe-book/builtin/{recipe}/photo', [WallRecipeController::class, 'storePhoto'])->whereNumber('recipe');
            Route::delete('/wall/recipe-book/builtin/{recipe}/photo', [WallRecipeController::class, 'destroyPhoto'])->whereNumber('recipe');
            Route::post('/wall/recipe-book/builtin/{recipe}/pages', [WallRecipeController::class, 'storePage'])->whereNumber('recipe');
            Route::delete('/wall/recipe-book/builtin/{recipe}/pages/{page}', [WallRecipeController::class, 'destroyPage'])
                ->whereNumber('recipe')->whereAlphaNumeric('page');
        });
    });

    Route::middleware('feature:photos')->group(function (): void {
        Route::get('/wall/photos', [WallPhotoController::class, 'index']);
        Route::get('/wall/photos/memories', [WallPhotoController::class, 'memories']);
        Route::get('/wall/photos/albums', [WallPhotoController::class, 'albums']);
        Route::put('/wall/photos/{photo}/favorite', [WallPhotoController::class, 'favorite'])->whereNumber('photo');
        Route::get('/wall/photos/{source}/{photo}', [WallPhotoController::class, 'show']);
        Route::middleware('permission:photos.upload')->group(function (): void {
            Route::patch('/photos/{photo}', [PhotoManageController::class, 'update'])->whereNumber('photo');
            Route::post('/photos/{photo}/show', [PhotoManageController::class, 'show'])->whereNumber('photo');
            Route::post('/photos/albums', [PhotoAlbumController::class, 'store']);
            Route::patch('/photos/albums/{album}', [PhotoAlbumController::class, 'update'])->whereNumber('album');
            Route::delete('/photos/albums/{album}', [PhotoAlbumController::class, 'destroy'])->whereNumber('album');
            Route::post('/photos/albums/{album}/add', [PhotoAlbumController::class, 'add'])->whereNumber('album');
            Route::post('/photos/albums/{album}/remove', [PhotoAlbumController::class, 'remove'])->whereNumber('album');
            Route::post('/photos/known', [PhotoUploadController::class, 'known']);
            Route::post('/photos', [PhotoUploadController::class, 'store']);
            Route::get('/photos', [PhotoManageController::class, 'index']);
            Route::get('/photos/trash', [PhotoManageController::class, 'trash']);
            Route::get('/photos/{photo}/image', [PhotoManageController::class, 'image'])->whereNumber('photo');
            Route::post('/photos/delete', [PhotoManageController::class, 'destroy']);
            Route::post('/photos/restore', [PhotoManageController::class, 'restore']);
            Route::post('/photos/purge', [PhotoManageController::class, 'purge']);
        });
    });

    Route::middleware('feature:timetable')->group(function (): void {
        Route::get('/wall/timetable', [WallTimetableController::class, 'index']);
        Route::middleware('permission:timetable.change_own')->group(function (): void {
            Route::post('/wall/timetable/{lesson}/change', [WallTimetableController::class, 'storeChange']);
            Route::delete('/wall/timetable/{lesson}/change', [WallTimetableController::class, 'destroyChange']);
        });
    });

    Route::middleware('feature:meals')->group(function (): void {
        Route::get('/wall/meals', [WallMealController::class, 'index']);
        Route::get('/wall/recipes', [WallMealController::class, 'recipes']);
        Route::middleware('permission:meals.edit')->group(function (): void {
            Route::post('/wall/meals', [WallMealController::class, 'store']);
            Route::patch('/wall/meals/{meal}', [WallMealController::class, 'update']);
            Route::delete('/wall/meals/{meal}', [WallMealController::class, 'destroy']);
            Route::post('/wall/meals/{meal}/ingredients', [WallMealController::class, 'ingredients']);
            Route::post('/wall/meals/shopping', [WallMealController::class, 'shopping']);
        });
    });

    Route::group([EnsureWritable::SELF => true], function (): void {
        Route::get('/wall/pin', [WallPinController::class, 'show']);
        Route::delete('/wall/pin', [WallPinController::class, 'lock']);

        /*
         * A four-digit PIN without a limit on attempts is no protection: ten
         * thousand possibilities are tried in seconds.
         */
        Route::post('/wall/pin', [WallPinController::class, 'unlock'])
            ->middleware('throttle:pin');

        /*
         * Each tablet and each account arranges its own dashboard. On a tablet
         * that needs `dashboard.arrange` (the PIN): a moved tile is seen by
         * everyone passing it. The controller checks, because an account needs
         * no permission for its own.
         */
        Route::post('/wall/dashboard', [WallDashboardController::class, 'store']);
        Route::patch('/wall/dashboard/{widget}', [WallDashboardController::class, 'update']);
        Route::delete('/wall/dashboard/{widget}', [WallDashboardController::class, 'destroy']);
    });

    Route::middleware(['feature:points', 'permission:points.award'])->group(function (): void {
        Route::post('/wall/points', [WallPointsController::class, 'store']);
        Route::post('/wall/points/gift', [WallPointsController::class, 'gift']);

        Route::post('/wall/redemptions/{redemption}/approve', [WallRewardController::class, 'approve']);
        Route::post('/wall/redemptions/{redemption}/reject', [WallRewardController::class, 'reject']);
    });

    /*
     * Changing assignments is part of managing: otherwise a child would pass
     * their week on to a sibling with two taps. Creating as well: whoever
     * invents a task decides what earns points.
     */
    Route::middleware(['feature:tasks', 'permission:tasks.manage'])->group(function (): void {
        Route::put('/wall/tasks/{task}/assignment', [WallTaskAssignmentController::class, 'update']);
        Route::delete('/wall/tasks/{task}/assignment', [WallTaskAssignmentController::class, 'destroy']);

        Route::post('/wall/tasks', [WallTaskController::class, 'store']);
        Route::patch('/wall/tasks/{task}', [WallTaskController::class, 'update']);
        Route::delete('/wall/tasks/{task}', [WallTaskController::class, 'destroy']);
    });
});
