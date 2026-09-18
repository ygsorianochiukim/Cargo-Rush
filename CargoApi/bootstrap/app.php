<?php

use App\Domain\Shared\Http\Middleware\RequirePermission;
use App\Domain\Shared\Providers\DomainServiceProvider;
use App\Domain\Tenancy\Http\Middleware\BindTenant;
use App\Domain\Tenancy\Http\Middleware\ForgetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        DomainServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Two changes to the API group, and both are about ordering.
         *
         * `ForgetTenant` goes first, ahead of authentication: a request starts
         * scoped to no company, so working out who is calling is not filtered
         * by whoever called last. It matters only where a process is reused,
         * which is exactly where it is easy to miss.
         *
         * `SubstituteBindings` comes *out* of the group, and is put back as a
         * route middleware after `tenant` — see the authenticated group in
         * `routes/api.php`. Group middleware all run before route middleware,
         * so left here it would resolve `{vehicle}`, `{trip}` and every other
         * bound model *before* the caller's company was in force, which is to
         * say unscoped: an id in a URL would fetch another company's row and
         * the controller would hand it over. No public route takes a
         * parameter, so nothing outside that group needs it.
         */
        $middleware->api(prepend: ForgetTenant::class, remove: SubstituteBindings::class);

        /**
         * Deployed, this sits behind a reverse proxy that terminates TLS, so
         * every request arrives over plain HTTP on a private address. Without
         * trusting the proxy's `X-Forwarded-*` headers Laravel believes that
         * literally: `SESSION_SECURE_COOKIE=true` then declines to set the
         * session cookie at all, `url()` builds `http://` links into a site
         * served over https, and the rate limiter keys every caller to the
         * proxy's address instead of their own.
         *
         * The default is the private ranges rather than `*`, because trusting
         * every proxy means trusting whatever `X-Forwarded-For` a caller sends
         * — which is the rate limiter's key. Nothing outside the machine can
         * reach the port these headers arrive on. `TRUSTED_PROXIES` overrides
         * it for a deployment where the proxy is elsewhere.
         */
        $middleware->trustProxies(
            at: array_map(
                trim(...),
                explode(',', (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1')),
            ),
        );

        // CargoUI authenticates as a first-party SPA, so its cookie has to
        // reach the API group. cargoApp sends a bearer token and is unaffected.
        $middleware->statefulApi();

        // Laravel does not meter the API group on its own. Without this the
        // whole API is unlimited, which matters most on the two public routes
        // — see the limiters in `DomainServiceProvider`. Keyed per account, so
        // an office behind one NAT does not throttle itself.
        $middleware->throttleApi('api');

        $middleware->alias([
            // What makes a role mean something. Without it the permission list
            // decides only what the sidebar shows, and every endpoint is
            // reachable by any account that can sign in.
            'permission' => RequirePermission::class,
            // Puts the caller's company in force before any controller runs.
            // Paired with `auth:sanctum` on the whole authenticated group —
            // an endpoint inside that group without it would query across
            // every company on the platform.
            'tenant' => BindTenant::class,
            // Route model binding, as a route middleware so it can be ordered
            // after `tenant`. Laravel has no alias for it of its own.
            'bindings' => SubstituteBindings::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Both clients speak JSON only; an HTML error page would be unusable
        // in either one.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
