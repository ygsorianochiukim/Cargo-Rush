<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GeneralLedgerController extends ApiController
{
    public function __construct(private readonly GeneralLedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        return $this->payload($this->ledger->summary(
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        ));
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        return $this->payload($this->ledger->forAccount(
            $account,
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        ));
    }

    public function trialBalance(Request $request): JsonResponse
    {
        return $this->payload($this->ledger->trialBalance($this->date($request, 'as_of')));
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
