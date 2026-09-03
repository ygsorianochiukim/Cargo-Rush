<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Services\NavigationService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/navigation` — the sidebar and the tab bar.
 *
 * Already filtered by permission, already sorted, badges already counted.
 * Clients render exactly what comes back (DESIGN.md section 7.3).
 */
class NavigationController extends ApiController
{
    public function __construct(private readonly NavigationService $navigation) {}

    public function __invoke(Request $request): JsonResponse
    {
        // `?client=mobile` asks for the driver app's five tabs instead of the
        // back office's twelve modules.
        $client = $request->string('client', 'web')->toString() === 'mobile' ? 'mobile' : 'web';

        $items = $this->navigation->forUser($request->user(), $client);

        return $this->payload($items->all(), ['client' => $client, 'total' => $items->count()]);
    }
}
