<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 401);

        $company = $user->company;

        if ($company === null && $user->awaitingCarrier()) {
            app(Tenant::class)->nobody();

            return $next($request);
        }

        abort_if(
            $company === null,
            403,
            'This account is not attached to a company. Ask an administrator to reattach it.',
        );

        abort_if(
            ! $company->isActive(),
            403,
            sprintf('%s is suspended. Contact support to reactivate the account.', $company->name),
        );

        app(Tenant::class)->set($company);

        return $next($request);
    }
}
