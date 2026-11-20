<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Middleware;

use App\Domain\Assistant\Services\AssistantService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RestrictAssistantWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken && in_array(AssistantService::ABILITY, $token->abilities, true)) {
            abort_unless($request->isMethodSafe() || $request->routeIs(
                'assistant.bot-status',
                'assistant.receipts.store',
                'assistant.receipts.decide',
                'assistant.receipts.edit-link',
            ), 403);
        }

        return $next($request);
    }
}
