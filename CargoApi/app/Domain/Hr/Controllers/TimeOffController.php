<?php

declare(strict_types=1);

namespace App\Domain\Hr\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Models\UndertimeRequest;
use App\Domain\Hr\Repositories\TimeOffRepository;
use App\Domain\Hr\Requests\DecisionRequest;
use App\Domain\Hr\Requests\LeaveRequestRequest;
use App\Domain\Hr\Requests\UndertimeRequestRequest;
use App\Domain\Hr\Resources\LeaveRequestResource;
use App\Domain\Hr\Resources\UndertimeRequestResource;
use App\Domain\Hr\Services\TimeOffService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leave and undertime — asking, and deciding.
 *
 * Approving is a verb rather than a PATCH on `status`, because it also records
 * who decided and when. A status a client could set directly would let a
 * request be approved by nobody.
 */
class TimeOffController extends ApiController
{
    public function __construct(
        private readonly TimeOffService $timeOff,
        private readonly TimeOffRepository $repository,
    ) {}

    /** The desk's queue and what it costs the fleet today. */
    public function overview(): JsonResponse
    {
        return $this->payload($this->timeOff->overview());
    }

    /* --------------------------------------------------------------- Leave */

    public function leaveIndex(Request $request): JsonResponse
    {
        $page = $this->repository->leave($this->timeOffFilters($request), $this->perPage($request, 50));

        return $this->collection(LeaveRequestResource::collection($page), $page);
    }

    public function storeLeave(LeaveRequestRequest $request): JsonResponse
    {
        $employee = Employee::findOrFail($request->input('employee_id'));

        return $this->item(
            new LeaveRequestResource($this->timeOff->requestLeave($employee, $request->validated())),
            status: 201,
        );
    }

    public function updateLeave(LeaveRequestRequest $request, LeaveRequest $leave): JsonResponse
    {
        return $this->item(
            new LeaveRequestResource($this->timeOff->updateLeave($leave, $request->validated())),
        );
    }

    public function decideLeave(DecisionRequest $request, LeaveRequest $leave): JsonResponse
    {
        $decided = $this->timeOff->decide(
            $leave,
            $request->decision(),
            $request->user()?->id,
            $request->input('note'),
        );

        return $this->item(new LeaveRequestResource($decided));
    }

    public function withdrawLeave(LeaveRequest $leave): JsonResponse
    {
        return $this->item(new LeaveRequestResource($this->timeOff->withdraw($leave)));
    }

    public function destroyLeave(LeaveRequest $leave): JsonResponse
    {
        $leave->delete();

        return $this->noContent();
    }

    /* ----------------------------------------------------------- Undertime */

    public function undertimeIndex(Request $request): JsonResponse
    {
        $page = $this->repository->undertime($this->timeOffFilters($request), $this->perPage($request, 50));

        return $this->collection(UndertimeRequestResource::collection($page), $page);
    }

    public function storeUndertime(UndertimeRequestRequest $request): JsonResponse
    {
        $employee = Employee::findOrFail($request->input('employee_id'));

        return $this->item(
            new UndertimeRequestResource(
                $this->timeOff->requestUndertime($employee, $request->validated()),
            ),
            status: 201,
        );
    }

    public function updateUndertime(UndertimeRequestRequest $request, UndertimeRequest $undertime): JsonResponse
    {
        return $this->item(
            new UndertimeRequestResource($this->timeOff->updateUndertime($undertime, $request->validated())),
        );
    }

    public function decideUndertime(DecisionRequest $request, UndertimeRequest $undertime): JsonResponse
    {
        $decided = $this->timeOff->decide(
            $undertime,
            $request->decision(),
            $request->user()?->id,
            $request->input('note'),
        );

        return $this->item(new UndertimeRequestResource($decided));
    }

    public function withdrawUndertime(UndertimeRequest $undertime): JsonResponse
    {
        return $this->item(new UndertimeRequestResource($this->timeOff->withdraw($undertime)));
    }

    public function destroyUndertime(UndertimeRequest $undertime): JsonResponse
    {
        $undertime->delete();

        return $this->noContent();
    }

    /**
     * The filters both lists honour.
     *
     * `status` is passed straight through rather than going via the shared
     * `filters()` helper, because these tables use `RequestStatus` and the
     * shared vocabulary has no word for "approved".
     *
     * @return array<string, mixed>
     */
    private function timeOffFilters(Request $request): array
    {
        return array_filter(
            [
                ...$request->only(['employee_id', 'status', 'type', 'from', 'to']),
                ...$request->boolean('open') ? ['open' => true] : [],
            ],
            static fn ($value) => $value !== null && $value !== '',
        );
    }
}
