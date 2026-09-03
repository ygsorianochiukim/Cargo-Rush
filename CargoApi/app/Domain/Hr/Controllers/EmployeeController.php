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

/**
 * Employees — the roster, their logins, and what each of those can see.
 */
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
                // Explicitly read as a boolean so `?has_account=0` is the
                // question it looks like rather than a truthy string.
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
        $employee = $this->employees->register($request->toData(), $request->photo());

        return $this->item(new EmployeeResource($employee), status: 201);
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        return $this->item(new EmployeeResource(
            $this->employees->edit($employee, $request->toData(), $request->photo())
        ));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->employees->delete($employee);

        return $this->noContent();
    }

    /** Headcount, the shape of the roster, and who still has no login. */
    public function overview(): JsonResponse
    {
        return $this->payload($this->employees->overview());
    }

    /* ----------------------------------------------------------- Accounts */

    /**
     * Give an employee a login.
     *
     * The password comes back in the response and only here, so whoever added
     * them can pass it on. It is a starting password rather than a secret —
     * the same trade `CustomerAccountService` makes, for the same reason.
     */
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

    /** Change the role on an employee's login. */
    public function assignRole(RoleAssignmentRequest $request, Employee $employee): JsonResponse
    {
        abort_if($employee->user === null, 422, 'This employee has no account to assign a role to.');

        $this->accounts->assignRole($employee->user, $request->role());

        return $this->item(new EmployeeResource($employee->refresh()->load(['user', 'driver'])));
    }

    /** What this employee's account can see, and what it could. */
    public function modules(Employee $employee): JsonResponse
    {
        abort_if($employee->user === null, 422, 'This employee has no account yet.');

        return $this->payload($this->accounts->moduleState($employee->user));
    }

    /**
     * Choose which modules the account sees.
     *
     * `rejected` in the meta is the honest answer to a request for a module the
     * role cannot open: it was not assigned, and here is the list, rather than
     * a checkbox that quietly refuses to stick.
     */
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
