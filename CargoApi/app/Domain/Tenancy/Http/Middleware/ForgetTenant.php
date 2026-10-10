<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Tenancy\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForgetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        app(Tenant::class)->forget();

        return $next($request);
    }
}
