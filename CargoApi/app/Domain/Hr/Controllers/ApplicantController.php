<?php

declare(strict_types=1);

namespace App\Domain\Hr\Controllers;

use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Requests\ApplicantRequest;
use App\Domain\Hr\Resources\ApplicantResource;
use App\Domain\Hr\Resources\EmployeeResource;
use App\Domain\Hr\Services\ApplicantService;
use App\Domain\Shared\Enums\ApplicantStage;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Applicants — the hiring pipeline in front of the roster.
 */
class ApplicantController extends ApiController
{
    public function __construct(private readonly ApplicantService $applicants) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->applicants->paginate(
            [
                ...$this->filters($request),
                ...$request->only(['stage', 'position']),
                ...$request->boolean('open') ? ['open' => true] : [],
            ],
            $this->perPage($request, 50),
        );

        return $this->collection(ApplicantResource::collection($page), $page);
    }

    public function show(Applicant $applicant): JsonResponse
    {
        return $this->item(new ApplicantResource($applicant));
    }

    public function store(ApplicantRequest $request): JsonResponse
    {
        $applicant = $this->applicants->receive($request->toData(), $request->photo(), $request->resume());

        return $this->item(new ApplicantResource($applicant), status: 201);
    }

    public function update(ApplicantRequest $request, Applicant $applicant): JsonResponse
    {
        return $this->item(new ApplicantResource($this->applicants->edit(
            $applicant,
            $request->toData(),
            $request->photo(),
            $request->resume(),
        )));
    }

    public function destroy(Applicant $applicant): JsonResponse
    {
        $this->applicants->delete($applicant);

        return $this->noContent();
    }

    /** How many sit at each stage, empty stages included. */
    public function pipeline(): JsonResponse
    {
        return $this->payload($this->applicants->pipeline());
    }

    /**
     * Move somebody along.
     *
     * A verb rather than a PATCH on `stage`, because it stamps the decision
     * date — and because `hired` is refused here: hiring creates an employee
     * record, and a stage change that sometimes did that and sometimes did not
     * would be a trap.
     */
    public function stage(Request $request, Applicant $applicant): JsonResponse
    {
        $validated = $request->validate([
            'stage' => ['required', Rule::in(ApplicantStage::values())],
        ]);

        $moved = $this->applicants->moveTo($applicant, ApplicantStage::from($validated['stage']));

        return $this->item(new ApplicantResource($moved));
    }

    /**
     * Hire them: build the employee record from the application.
     *
     * Returns the new employee rather than the applicant, because that is what
     * the office does next — give them a login, set their salary, put them on
     * a truck.
     */
    public function hire(Request $request, Applicant $applicant): JsonResponse
    {
        $overrides = $request->validate([
            'hired_on' => ['sometimes', 'date'],
            'position' => ['sometimes', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['sometimes', 'string'],
            'base_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
        ]);

        $employee = $this->applicants->hire($applicant, $overrides);

        return $this->item(new EmployeeResource($employee), status: 201);
    }
}
