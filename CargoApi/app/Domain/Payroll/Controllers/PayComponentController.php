<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Payroll\Requests\EmployeePayComponentRequest;
use App\Domain\Payroll\Requests\PayComponentRequest;
use App\Domain\Payroll\Resources\EmployeePayComponentResource;
use App\Domain\Payroll\Resources\PayComponentResource;
use App\Domain\Payroll\Services\PayComponentService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayComponentController extends ApiController
{
    public function __construct(private readonly PayComponentService $components) {}

    public function index(Request $request): JsonResponse
    {
        $components = PayComponent::query()
            ->withCount('assignments')
            ->when($request->boolean('active'), fn ($query) => $query->active())
            ->inPayslipOrder()
            ->get();

        return $this->collection(PayComponentResource::collection($components), $components);
    }

    public function store(PayComponentRequest $request): JsonResponse
    {
        return $this->item(
            new PayComponentResource($this->components->create($request->toAttributes())),
            status: 201,
        );
    }

    public function update(PayComponentRequest $request, PayComponent $component): JsonResponse
    {
        return $this->item(
            new PayComponentResource($this->components->update($component, $request->toAttributes())),
        );
    }

    public function destroy(PayComponent $component): JsonResponse
    {
        if ($this->components->delete($component)) {
            return $this->noContent();
        }

        return $this->item(
            new PayComponentResource($component->refresh()),
            meta: ['retired' => true, 'reason' => 'Staff are assigned this component, so it has been retired rather than deleted. It will not appear on new payslips.'],
        );
    }

    public function forEmployee(Employee $employee): JsonResponse
    {
        $assignments = $this->components->assignmentsFor($employee);

        return $this->collection(EmployeePayComponentResource::collection($assignments), $assignments);
    }

    public function assign(EmployeePayComponentRequest $request): JsonResponse
    {
        return $this->item(
            new EmployeePayComponentResource($this->components->assign($request->toAttributes())),
            status: 201,
        );
    }

    public function updateAssignment(
        EmployeePayComponentRequest $request,
        EmployeePayComponent $assignment,
    ): JsonResponse {
        return $this->item(new EmployeePayComponentResource(
            $this->components->updateAssignment($assignment, $request->toAttributes()),
        ));
    }

    public function unassign(EmployeePayComponent $assignment): JsonResponse
    {
        $this->components->unassign($assignment);

        return $this->noContent();
    }
}
