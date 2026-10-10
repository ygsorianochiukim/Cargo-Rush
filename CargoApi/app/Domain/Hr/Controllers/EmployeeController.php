<?php

declare(strict_types=1);

namespace App\Domain\Hr\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Requests\EmployeeRequest;
use App\Domain\Hr\Requests\ModuleAssignmentRequest;
use App\Domain\Hr\Requests\RoleAssignmentRequest;
use App\Domain\Hr\Requests\StaffAccountRequest;
use App\Domain\Hr\Resources\EmployeeResource;
use App\Domain\Hr\Services\EmployeeService;
use App\Domain\Hr\Services\StaffAccountService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends ApiController
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly StaffAccountService $accounts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->employees->paginate(
            [
                ...$this->filters($request),
                ...$request->only(['position', 'department', 'employment_type']),
                ...$request->has('has_account') ? ['has_account' => $request->boolean('has_account')] : [],
            ],
            $this->perPage($request, 50),
        );

        return $this->collection(EmployeeResource::collection($page), $page);
    }

    public function show(Employee $employee): JsonResponse
    {
        return $this->item(new EmployeeResource($employee));
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->register(
            $request->toData(),
            $request->photo(),
            $request->toLicence(),
        );

        return $this->item(new EmployeeResource($employee), status: 201);
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        return $this->item(new EmployeeResource(
            $this->employees->edit($employee, $request->toData(), $request->photo(), $request->toLicence())
        ));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->employees->delete($employee);

        return $this->noContent();
    }

    public function overview(): JsonResponse
    {
        return $this->payload($this->employees->overview());
    }

    public function createAccount(StaffAccountRequest $request, Employee $employee): JsonResponse
    {
        $credentials = $this->accounts->createFor(
            employee: $employee,
            email: (string) $request->input('email'),
            role: $request->role(),
            password: $request->input('password'),
        );

        return $this->item(
            new EmployeeResource($employee->refresh()->load(['user', 'driver'])),
            ['credentials' => $credentials],
            status: 201,
        );
    }

    public function assignRole(RoleAssignmentRequest $request, Employee $employee): JsonResponse
    {
        abort_if($employee->user === null, 422, 'This employee has no account to assign a role to.');

        $this->accounts->assignRole($employee->user, $request->role());

        return $this->item(new EmployeeResource($employee->refresh()->load(['user', 'driver'])));
    }

    public function modules(Employee $employee): JsonResponse
    {
        abort_if($employee->user === null, 422, 'This employee has no account yet.');

        return $this->payload($this->accounts->moduleState($employee->user));
    }

    public function assignModules(ModuleAssignmentRequest $request, Employee $employee): JsonResponse
    {
        abort_if($employee->user === null, 422, 'This employee has no account yet.');

        $result = $this->accounts->assignModules($employee->user, $request->modules());

        return $this->payload(
            $this->accounts->moduleState($employee->user->refresh()),
            $result['rejected'] === [] ? [] : ['rejected' => $result['rejected']],
        );
    }
}
