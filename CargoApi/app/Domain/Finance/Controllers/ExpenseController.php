<?php

declare(strict_types=1);

namespace App\Domain\Finance\Controllers;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Requests\ExpenseCategoryRequest;
use App\Domain\Finance\Requests\ExpenseRequest;
use App\Domain\Finance\Resources\ExpenseCategoryResource;
use App\Domain\Finance\Resources\ExpenseResource;
use App\Domain\Finance\Services\ExpenseService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ExpenseController extends ApiController
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->expenses->paginate(
            [...$this->filters($request), ...$request->only(['category_id', 'trip_id'])],
            $this->perPage($request, 50),
        );

        return $this->collection(ExpenseResource::collection($page), $page);
    }

    public function show(Expense $expense): JsonResponse
    {
        return $this->item(new ExpenseResource($expense));
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->record($request->toData(), $request->user()?->id);

        return $this->item(new ExpenseResource($expense), status: 201);
    }

    public function update(ExpenseRequest $request, Expense $expense): JsonResponse
    {
        return $this->item(new ExpenseResource($this->expenses->updateExpense($expense, $request->toData())));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->expenses->delete($expense);

        return $this->noContent();
    }

    public function report(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->payload($this->expenses->report($from, $to));
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = $this->expenses->categories($request->boolean('active'));

        return $this->collection(ExpenseCategoryResource::collection($categories), $categories);
    }

    public function storeCategory(ExpenseCategoryRequest $request): JsonResponse
    {
        return $this->item(
            new ExpenseCategoryResource($this->expenses->createCategory($request->toData())),
            status: 201,
        );
    }

    public function updateCategory(ExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        return $this->item(
            new ExpenseCategoryResource($this->expenses->updateCategory($category, $request->toData())),
        );
    }

    public function destroyCategory(ExpenseCategory $category): JsonResponse
    {
        if ($this->expenses->deleteCategory($category)) {
            return $this->noContent();
        }

        return $this->item(
            new ExpenseCategoryResource($category->refresh()),
            ['retired' => true, 'reason' => 'This category has expenses filed against it, so it was switched off rather than deleted.'],
        );
    }

    private function range(Request $request): array
    {
        $from = $this->date($request, 'from') ?? now()->startOfMonth();
        $to = $this->date($request, 'to') ?? now()->endOfMonth();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function date(Request $request, string $key): ?Carbon
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
