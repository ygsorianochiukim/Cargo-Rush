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
            // The managed job title, which is what decides whether the licence
            // below is wanted — see `positions.drives`.
            'position_id' => ['nullable', 'string', 'exists:positions,id'],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['sometimes', 'string'],
            'pay_basis' => ['sometimes', 'string'],
            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            /**
             * The licence, where the applicant is being hired to drive.
             *
             * Optional even then, unlike registering somebody directly. An
             * application is not a licence check: the desk hires the person
             * first and the licence turns up with them on day one, and
             * refusing the hire for want of a number nobody has yet would send
             * the office to create the employee by hand instead. Left out, the
             * driver record is opened later by editing the roster.
             */
            'licence_no' => ['nullable', 'string', 'max:40'],
            'licence_expiry' => ['nullable', 'date', 'required_with:licence_no'],
        ]);

        $employee = $this->applicants->hire($applicant, $overrides);

        return $this->item(new EmployeeResource($employee), status: 201);
    }
}
