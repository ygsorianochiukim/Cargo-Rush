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

/**
 * Other Expenses — the categorised spend that the workbook's five columns
 * never had a place for.
 */
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

    /** Where the money went over a window, biggest category first. */
    public function report(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->payload($this->expenses->report($from, $to));
    }

    /* --------------------------------------------------------- Categories */

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

    /**
     * Deleting a category that has spend against it retires it instead.
     *
     * A 200 with the retired row rather than a 204, so the client can say what
     * actually happened — a category that vanishes from the list on some
     * presses and greys out on others, with no explanation, reads as a bug.
     */
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

    /** The window a report covers: this month unless the caller says otherwise. */
    private function range(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : now()->endOfMonth();

        return [$from, $to];
    }
}
