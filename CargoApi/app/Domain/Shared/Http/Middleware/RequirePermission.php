<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);

        foreach ($abilities as $ability) {
            if ($user->hasPermission($ability)) {
                return $next($request);
            }
        }

        abort(403, sprintf(
            'This account does not hold %s.',
            implode(' or ', $abilities),
        ));
    }
}
