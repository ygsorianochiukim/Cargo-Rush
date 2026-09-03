<?php

use App\Domain\Shared\Http\Middleware\RequirePermission;
use App\Domain\Shared\Providers\DomainServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        // CargoUI authenticates as a first-party SPA, so its cookie has to
        // reach the API group. cargoApp sends a bearer token and is unaffected.
        $middleware->statefulApi();

        $middleware->alias([
            // What makes a role mean something. Without it the permission list
            // decides only what the sidebar shows, and every endpoint is
            // reachable by any account that can sign in.
            'permission' => RequirePermission::class,
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
