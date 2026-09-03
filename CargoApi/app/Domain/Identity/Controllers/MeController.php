<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/v1/me` — drives the web user chip and the mobile profile. */
class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->item(new MeResource($request->user()->load(['driver', 'customer:id,name'])));
    }
}
