<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->item(new MeResource($request->user()->load(['driver', 'customer:id,name'])));
    }

    public function updateDeveloperAccess(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor->currentAccessToken() instanceof PersonalAccessToken, 403);
        $request->validate(['enabled' => ['required', 'boolean']]);

        $account = DB::transaction(function () use ($actor, $request): User {
            $account = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            abort_unless($account->canEditOwnDeveloperAccess(), 403);

            $account->developer_access = $request->boolean('enabled');
            $account->save();

            return $account;
        });

        return $this->item(new MeResource($account->load(['driver', 'customer:id,name'])));
    }
}
