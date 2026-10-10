<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatementController extends ApiController
{
    public function __construct(private readonly FinancialStatementService $statements) {}

    public function income(Request $request): JsonResponse
    {
        return $this->payload($this->statements->incomeStatement(
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        ));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        return $this->payload($this->statements->balanceSheet($this->date($request, 'as_of')));
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
