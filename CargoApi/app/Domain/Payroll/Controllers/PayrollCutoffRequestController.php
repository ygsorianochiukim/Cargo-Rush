<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollCutoffRequest;
use App\Domain\Payroll\Requests\PayrollCutoffRequestRequest;
use App\Domain\Payroll\Resources\PayrollCutoffRequestResource;
use App\Domain\Payroll\Services\PayrollCutoffRequestService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollCutoffRequestController extends ApiController
{
    public function __construct(private readonly PayrollCutoffRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $rows = $this->requests->all(is_string($status) && $status !== '' ? $status : null);

        return $this->collection(PayrollCutoffRequestResource::collection($rows), $rows);
    }

    public function pending(): JsonResponse
    {
        $pending = $this->requests->pending();

        return $pending === null
            ? $this->payload([])
            : $this->item(new PayrollCutoffRequestResource($pending));
    }

    public function store(PayrollCutoffRequestRequest $request): JsonResponse
    {
        return $this->item(
            new PayrollCutoffRequestResource(
                $this->requests->file($request->toAttributes(), $this->user($request)),
            ),
            status: 201,
        );
    }

    public function approve(Request $httpRequest, PayrollCutoffRequest $request): JsonResponse
    {
        return $this->item(new PayrollCutoffRequestResource(
            $this->requests->approve($request, $this->user($httpRequest), $this->note($httpRequest)),
        ));
    }

    public function decline(Request $httpRequest, PayrollCutoffRequest $request): JsonResponse
    {
        return $this->item(new PayrollCutoffRequestResource(
            $this->requests->decline($request, $this->user($httpRequest), $this->note($httpRequest)),
        ));
    }

    public function withdraw(PayrollCutoffRequest $request): JsonResponse
    {
        return $this->item(new PayrollCutoffRequestResource($this->requests->withdraw($request)));
    }

    private function note(Request $request): ?string
    {
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $note = trim((string) ($validated['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
