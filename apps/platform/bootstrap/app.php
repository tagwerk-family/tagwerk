<?php

use App\Http\Middleware\ApiTokenScope;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\DeviceTokenFromCookie;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureMail;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureWritable;
use App\Http\Middleware\RenamedCookies;
use App\Http\Middleware\ResolveHousehold;
use App\Http\Middleware\SetContentSecurityPolicy;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Http\Middleware\UserTokenFromCookie;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * Not via withRouting(channels:), because authorization would then hang
     * on the web guard. Wall and phone sign in through Sanctum though — one
     * with a device token, the other with a user token, both from an
     * httpOnly cookie. Without this middleware /broadcasting/auth would be
     * unreachable for both and no device would ever get into its channel.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['device.cookie', 'user.cookie', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The reverse proxy in front of the server. Without it every request
         * comes from the proxy's address: the admin network check and every
         * rate limit keyed by IP would see one single client.
         */
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        // Every response of Laravel, the admin's included; the app gets its
        // header from nginx (see ContentSecurityPolicy).
        $middleware->append(SetContentSecurityPolicy::class);

        $middleware->prepend(RenamedCookies::class);

        $middleware->alias([
            'household' => ResolveHousehold::class,
            'device.cookie' => DeviceTokenFromCookie::class,
            'user.cookie' => UserTokenFromCookie::class,
            'permission' => EnsurePermission::class,
            'feature' => EnsureFeature::class,
            'mail' => EnsureMail::class,
        ]);

        /*
         * The device token is set and read on API routes, where Laravel does
         * not encrypt cookies. Without this exception the web middleware
         * would discard it as unreadable on the first web route call.
         */
        $middleware->encryptCookies(except: [
            DeviceTokenFromCookie::COOKIE,
            UserTokenFromCookie::COOKIE,
        ]);

        /*
         * Laravel sorts route middleware by a fixed priority list; the order
         * in the route definition does not matter for it, and route:list
         * shows the registration order, not the execution order.
         *
         * The anchor is the contract AuthenticatesRequests, NOT the class
         * Authenticate: only the interface is in the list. With the class as
         * anchor Laravel finds nothing and appends the middleware to the end,
         * where it has no effect — it would only run after authentication and
         * therefore never, because that aborts with 401 first.
         */
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: DeviceTokenFromCookie::class,
        );

        /*
         * Both only set the Authorization header when it is still missing.
         * So on the wall the device token wins, on the phone the user token
         * — even if both cookies should ever sit in the same browser.
         */
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: UserTokenFromCookie::class,
        );

        /*
         * The language follows whoever is signed in, so it must run after
         * authentication — in the group alone it would run first and see no
         * user. Routes without sign-in get the default.
         */
        /*
         * A token for another program reads or writes by its abilities.
         * After authentication, for the same reason as the language.
         */
        $middleware->appendToGroup('api', ApiTokenScope::class);
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: ApiTokenScope::class,
        );

        $middleware->appendToGroup('api', ApplyLocale::class);
        $middleware->appendToGroup('web', ApplyLocale::class);
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: ApplyLocale::class,
        );

        /*
         * The household must be set before Laravel resolves models from the
         * URL: SubstituteBindings is in the priority list, ResolveHousehold
         * is not and would otherwise run after it. The binding would then
         * look up with an empty context, the global scope would find nothing,
         * and the wall would get a 404 when ticking a task it just showed.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveHousehold::class,
        );

        // Asks the household ResolveHousehold has just set.
        $middleware->appendToGroup('api', EnsureWritable::class);
        $middleware->appendToPriorityList(
            after: ResolveHousehold::class,
            append: EnsureWritable::class,
        );

        /*
         * A closed mail route answers before the throttle counts it: the
         * routes share the login limiter, and probing them would otherwise
         * use up the password sign-in of the same address.
         */
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: EnsureMail::class,
        );

        /*
         * There is no route named "login" — sign-in lives in the Filament
         * panel. Without this mapping a visitor who is not signed in would
         * run into an error instead of the sign-in page.
         */
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
