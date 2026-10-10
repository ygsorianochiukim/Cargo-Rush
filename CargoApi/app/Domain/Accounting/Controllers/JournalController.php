<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Requests\JournalEntryRequest;
use App\Domain\Accounting\Requests\VoidJournalEntryRequest;
use App\Domain\Accounting\Resources\JournalEntryResource;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends ApiController
{
    public function __construct(private readonly JournalService $journal) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            ...$this->filters($request),
            ...array_filter([
                'category' => $request->query('category'),
                'account_id' => $request->query('account_id'),
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];

        $entries = $this->journal->paginate($filters, $this->perPage($request));

        return $this->collection(JournalEntryResource::collection($entries), $entries);
    }

    public function categories(): JsonResponse
    {
        return $this->payload(array_map(static fn (JournalCategory $category): array => [
            'value' => $category->value,
            'label' => $category->label(),
            'icon' => $category->icon(),
        ], JournalCategory::cases()));
    }

    public function show(JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource($entry->load(['lines.account', 'postedBy'])));
    }

    public function store(JournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journal->create($request->toData(), $this->user($request));

        return $this->item(new JournalEntryResource($entry), status: 201);
    }

    public function update(JournalEntryRequest $request, JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource(
            $this->journal->update($entry, $request->toData())
        ));
    }

    public function post(Request $request, JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource(
            $this->journal->post($entry, $this->user($request))
        ));
    }

    public function void(VoidJournalEntryRequest $request, JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource(
            $this->journal->void($entry, $request->reason(), $this->user($request))
        ));
    }

    public function destroy(JournalEntry $entry): JsonResponse
    {
        $this->journal->delete($entry);

        return $this->noContent();
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
