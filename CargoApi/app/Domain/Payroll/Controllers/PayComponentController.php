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

/**
 * The firm's salary structure: what it pays and deducts, and who gets it.
 *
 * Two resources behind one controller, because they are one screen and one
 * decision. A catalogue nobody is assigned pays nothing, and an assignment
 * needs something to point at — splitting them across two controllers would put
 * a route boundary through the middle of a single job.
 *
 * ## Why there is no pagination here
 *
 * A firm's list of allowances and deductions is a dozen rows and stays a dozen
 * rows; so is one person's. Paginating either would mean a client had to page
 * through a payslip's worth of information to render one form. The endpoints
 * that *are* paginated in this system are the ones that grow without limit —
 * trips, invoices, pay runs — and this is not one of them.
 */
class PayComponentController extends ApiController
{
    public function __construct(private readonly PayComponentService $components) {}

    /**
     * `GET payroll/components` — the catalogue.
     *
     * The assignment count comes with each row, because it is what decides
     * whether a delete is a delete: a component somebody is on is retired
     * instead, and a screen that cannot see the count cannot warn about it.
     */
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

    /**
     * `DELETE payroll/components/{component}` — remove it, or retire it.
     *
     * A component nobody is assigned is deleted; one in use is retired, because
     * deleting it would cascade away the assignments and a firm that removed a
     * COLA by mistake could not put it back. Both stop it appearing on future
     * payslips, which is what was asked.
     *
     * Answers 200 with the retired component rather than 204 in that case — the
     * client has to be able to tell the two apart, and the difference is
     * something the office should see rather than infer from a row that did not
     * disappear.
     */
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

    /**
     * `GET employees/{employee}/pay-components` — what one person is paid.
     *
     * Under the employee rather than under payroll, because that is the screen
     * it belongs to: somebody opening a staff record wants to see the salary
     * beside the allowances that go with it.
     */
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
