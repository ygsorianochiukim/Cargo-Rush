<?php

declare(strict_types=1);

namespace App\Domain\Finance\Controllers;

use App\Domain\Finance\Services\PayablesService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class PayablesController extends ApiController
{
    public function __construct(private readonly PayablesService $payables) {}

    public function __invoke(): JsonResponse
    {
        return $this->payload($this->payables->overview());
    }
}
