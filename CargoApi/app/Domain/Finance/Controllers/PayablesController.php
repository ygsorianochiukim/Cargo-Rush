<?php

declare(strict_types=1);

namespace App\Domain\Finance\Controllers;

use App\Domain\Finance\Services\PayablesService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/payables` — everything the fleet owes, in one list.
 *
 * A roll-up over four modules that never met: a partner's wallet, a hired
 * truck's rent, a supplier's bill and whatever else was filed as spend. Each
 * was right about its own corner, and nobody could answer "what do we owe this
 * week" without opening all four.
 *
 * **Read-only.** Every line names the screen that settles it and is settled
 * there, under that module's own permission — see `PayablesService` for why
 * that is the right shape rather than a limitation.
 *
 * A raw payload rather than a resource collection: this is four groups of
 * computed lines with no model behind them, which is exactly what `payload()`
 * exists for.
 */
class PayablesController extends ApiController
{
    public function __construct(private readonly PayablesService $payables) {}

    public function __invoke(): JsonResponse
    {
        return $this->payload($this->payables->overview());
    }
}
