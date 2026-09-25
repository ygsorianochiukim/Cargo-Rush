<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Controllers;

use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trucker\Requests\RegisterTruckerRequest;
use App\Domain\Trucker\Services\TruckerRegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/register/trucker` — the owner-operator's front door.
 *
 * Answers in exactly the shape a login does, token in `meta`, like the other
 * two registrations: somebody who has just signed up is somebody who is now
 * signed in, and the app should carry on through the same code path rather than
 * a second one written only for this case.
 *
 * What the response does **not** say is that they can start working. The
 * `truckers` row lands `pending` and the job board stays empty until a human at
 * the fleet approves them — so the app opens on a screen that says so. A
 * registration that dropped somebody onto an empty board would look like a bug
 * rather than a queue.
 *
 * Throttled on the same limiter as the other two registrations. It writes a
 * login on an unauthenticated call, which is worth metering whether it succeeds
 * or not.
 */
class TruckerRegistrationController extends ApiController
{
    public function __construct(private readonly TruckerRegistrationService $registration) {}

    public function __invoke(RegisterTruckerRequest $request): JsonResponse
    {
        ['user' => $user, 'trucker' => $trucker, 'token' => $token] =
            $this->registration->register($request->toData(), $request);

        return $this->item(
            new MeResource($user),
            array_filter([
                'token' => $token,
                'token_type' => $token === null ? null : 'Bearer',
                // The standing, said plainly in the same breath. The client
                // opens on the waiting screen off this rather than having to
                // infer it from an empty board.
                'trucker_id' => $trucker->getKey(),
                'trucker_status' => $trucker->status->value,
            ], static fn ($value): bool => $value !== null),
            201,
        );
    }
}
