<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Repositories\AccessRepository;
use App\Domain\Identity\Requests\PositionRequest;
use App\Domain\Identity\Requests\RoleRequest;
use App\Domain\Identity\Resources\PositionResource;
use App\Domain\Identity\Resources\RoleResource;
use App\Domain\Identity\Services\AccessService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Access control — roles, what each reaches, and the job titles behind them.
 */
class AccessController extends ApiController
{
    public function __construct(
        private readonly AccessService $access,
        private readonly AccessRepository $repository,
    ) {}

    /* --------------------------------------------------------------- Roles */

    public function roles(Request $request): JsonResponse
    {
        $roles = $this->repository->roles($request->boolean('active'));

        return $this->collection(RoleResource::collection($roles), $roles);
    }

    public function storeRole(RoleRequest $request): JsonResponse
    {
        return $this->item(
            new RoleResource($this->access->createRole($request->validated())),
            status: 201,
        );
    }

    public function updateRole(RoleRequest $request, Role $role): JsonResponse
    {
        return $this->item(new RoleResource($this->access->updateRole($role, $request->validated())));
    }

    public function destroyRole(Role $role): JsonResponse
    {
        $this->access->deleteRole($role);

        return $this->noContent();
    }

    /**
     * The permission vocabulary, grouped by module.
     *
     * Read-only on purpose: a permission is only real if code checks for it, so
     * one invented here would gate nothing. Adding one is a release.
     */
    public function permissions(): JsonResponse
    {
        return $this->payload($this->repository->permissionGroups());
    }

    /* ----------------------------------------------------------- Positions */

    public function positions(Request $request): JsonResponse
    {
        $positions = $this->repository->positions($request->boolean('active'));

        return $this->collection(PositionResource::collection($positions), $positions);
    }

    public function storePosition(PositionRequest $request): JsonResponse
    {
        return $this->item(
            new PositionResource($this->access->createPosition($request->validated())),
            status: 201,
        );
    }

    public function updatePosition(PositionRequest $request, Position $position): JsonResponse
    {
        return $this->item(
            new PositionResource($this->access->updatePosition($position, $request->validated())),
        );
    }

    /**
     * Deleting a position somebody holds retires it instead.
     *
     * A 200 with the retired row rather than a 204, so the client can say what
     * happened — a row that vanishes on some presses and greys out on others,
     * with no explanation, reads as a bug.
     */
    public function destroyPosition(Position $position): JsonResponse
    {
        if ($this->access->deletePosition($position)) {
            return $this->noContent();
        }

        return $this->item(
            new PositionResource($position->refresh()->load('defaultRole')),
            ['retired' => true, 'reason' => 'Employees hold this position, so it was switched off rather than deleted.'],
        );
    }
}
