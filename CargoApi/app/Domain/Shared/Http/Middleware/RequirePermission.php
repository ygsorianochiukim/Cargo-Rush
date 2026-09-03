<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate between a role and an endpoint.
 *
 * Until this existed, `Role::permissions()` decided only what the *sidebar*
 * showed. Every endpoint was reachable by any authenticated account, so a
 * driver's token could read the payroll — the menu was a suggestion, not a
 * boundary. This is what makes a role mean something.
 *
 *     Route::get('employees', ...)->middleware('permission:hr.view');
 *
 * Several abilities are ANY-of, not all-of:
 *
 *     ->middleware('permission:finance.manage,finance.write')
 *
 * because the ledger is one endpoint reached two ways — the office managing the
 * sheet, and a driver filing the day's figures from the cab. Requiring both
 * would lock out each of them in turn.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        // 401 rather than 403 when nobody is signed in: the client's
        // interceptor sends a 401 to the login screen, and a 403 would leave a
        // signed-out session staring at "forbidden".
        abort_if($user === null, 401);

        foreach ($abilities as $ability) {
            if ($user->hasPermission($ability)) {
                return $next($request);
            }
        }

        // Names the permission that was missing. A 403 saying nothing is the
        // kind of thing that reaches a developer as "the app is broken".
        abort(403, sprintf(
            'This account does not hold %s.',
            implode(' or ', $abilities),
        ));
    }
}
