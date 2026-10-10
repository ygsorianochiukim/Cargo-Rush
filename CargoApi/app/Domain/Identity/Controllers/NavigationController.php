<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Services\NavigationService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends ApiController
{
    public function __construct(private readonly NavigationService $navigation) {}

    public function __invoke(Request $request): JsonResponse
    {
        $client = $request->string('client', 'web')->toString() === 'mobile' ? 'mobile' : 'web';

        $items = $this->navigation->forUser($request->user(), $client);

        return $this->payload($items->all(), ['client' => $client, 'total' => $items->count()]);
    }
}
