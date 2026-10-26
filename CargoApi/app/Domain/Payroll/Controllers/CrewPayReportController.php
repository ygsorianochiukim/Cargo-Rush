<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Services\CrewPayReportService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CrewPayReportController extends ApiController
{
    public function __construct(private readonly CrewPayReportService $report) {}

    public function index(): JsonResponse
    {
        return $this->payload($this->report->roster());
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        abort_if($employee->driver_id === null, 404, 'That person is not on the crew.');

        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subMonths(2)->startOfMonth();

        return $this->payload($this->report->forEmployee($employee->load('driver'), $from, $to));
    }
}
