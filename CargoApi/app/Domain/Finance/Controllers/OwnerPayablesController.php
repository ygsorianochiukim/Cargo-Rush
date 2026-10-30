<?php

declare(strict_types=1);

namespace App\Domain\Finance\Controllers;

use App\Domain\Finance\Requests\OwnerCutoffSettleRequest;
use App\Domain\Finance\Services\OwnerPayablesService;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Resources\WalletEntryResource;
use App\Domain\Trucker\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OwnerPayablesController extends ApiController
{
    public function __construct(private readonly OwnerPayablesService $owners) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'trucker' => ['sometimes', 'string', 'max:26'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subMonths(2)->startOfMonth();

        return $this->payload($this->owners->statement($from, $to, $validated['trucker'] ?? null));
    }

    public function history(Request $request, Trucker $trucker): JsonResponse
    {
        return $this->payload($this->owners->history($trucker, $this->perPage($request, 100)));
    }

    public function settle(OwnerCutoffSettleRequest $request, Trucker $trucker): JsonResponse
    {
        $data = $request->validated();

        $entry = $this->owners->settle(
            owner: $trucker,
            start: Carbon::parse((string) $data['start']),
            end: Carbon::parse((string) $data['end']),
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
            recordedBy: (int) $request->user()->getAuthIdentifier(),
            occurredOn: isset($data['occurred_on']) ? Carbon::parse((string) $data['occurred_on']) : null,
            method: $data['method'] ?? WalletService::DEFAULT_METHOD,
            cleared: (bool) ($data['cleared'] ?? false),
        );

        return (new WalletEntryResource($entry->load('trip')))
            ->response()
            ->setStatusCode(201);
    }
}
