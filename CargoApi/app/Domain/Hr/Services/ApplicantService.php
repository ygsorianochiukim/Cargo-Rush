<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\DTO\ApplicantData;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Repositories\ApplicantRepository;
use App\Domain\Shared\Enums\ApplicantStage;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The hiring pipeline.
 *
 * Two things here earn their place over plain CRUD. Moving somebody's stage
 * records *when* the decision was made, because "we offered this person a job
 * three weeks ago and never heard back" is a question the list has to be able
 * to answer. And hiring builds the employee record from the application rather
 * than making somebody retype a name, a number and an address that are already
 * on the screen — retyping is where the two records start to disagree.
 */
class ApplicantService extends CrudService
{
    public function __construct(
        private readonly ApplicantRepository $applicants,
        private readonly EmployeeService $employees,
        private readonly PhotoStore $files,
    ) {}

    protected function repository(): Repository
    {
        return $this->applicants;
    }

    public function receive(ApplicantData $data, ?UploadedFile $photo, ?UploadedFile $resume): Applicant
    {
        $attributes = $data->persistable();

        $attributes['applied_on'] ??= Carbon::now()->toDateString();
        $attributes['photo_path'] = $this->files->store($photo, 'applicants');
        $attributes['resume_path'] = $this->files->store($resume, 'applicants');

        return Applicant::create($attributes)->refresh();
    }

    public function edit(
        Applicant $applicant,
        ApplicantData $data,
        ?UploadedFile $photo,
        ?UploadedFile $resume,
    ): Applicant {
        $attributes = $data->persistable();

        if ($photo !== null) {
            $attributes['photo_path'] = $this->files->replace($applicant->photo_path, $photo, 'applicants');
        }

        if ($resume !== null) {
            $attributes['resume_path'] = $this->files->replace($applicant->resume_path, $resume, 'applicants');
        }

        // A stage change is a decision, whichever direction it goes, so the
        // date is stamped here rather than only on the two final outcomes.
        if ($data->stage !== null && $data->stage !== $applicant->stage) {
            $attributes['decided_at'] = Carbon::now();
        }

        $applicant->update($attributes);

        return $applicant->refresh();
    }

    /**
     * Move somebody along the pipeline.
     *
     * `hired` is not settable this way — hiring creates an employee record, and
     * a stage change that silently did or did not do that depending on the
     * value would be a trap. `hire()` is the verb.
     */
    public function moveTo(Applicant $applicant, ApplicantStage $stage): Applicant
    {
        abort_if(
            $stage === ApplicantStage::Hired,
            422,
            'Hiring creates the employee record. Use the hire action rather than setting the stage.',
        );

        $applicant->update(['stage' => $stage->value, 'decided_at' => Carbon::now()]);

        return $applicant->refresh();
    }

    /**
     * Hire an applicant: build their employee record from the application.
     *
     * Idempotent by refusal rather than by silence — hiring twice would create
     * a second employee for one person, and the second one would carry a second
     * payroll number. The overrides are for what an application does not know:
     * their actual start date, the salary agreed, the department they land in.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function hire(Applicant $applicant, array $overrides = []): Employee
    {
        abort_if(
            $applicant->employee_id !== null,
            422,
            'This applicant has already been hired.',
        );

        return DB::transaction(function () use ($applicant, $overrides): Employee {
            $employee = $this->employees->register(
                EmployeeData::fromArray([
                    'first_name' => $applicant->first_name,
                    'last_name' => $applicant->last_name,
                    'position' => $applicant->position_applied,
                    'contact' => $applicant->contact,
                    'email' => $applicant->email,
                    'address' => $applicant->address,
                    'hired_on' => Carbon::now()->toDateString(),
                    ...$overrides,
                ]),
                photo: null,
            );

            // The photograph moves across as a path rather than being copied:
            // it is the same file, and duplicating it would leave two to keep
            // in step the first time somebody replaces one.
            if ($applicant->photo_path !== null) {
                $employee->forceFill(['photo_path' => $applicant->photo_path])->save();
            }

            $applicant->update([
                'stage' => ApplicantStage::Hired->value,
                'employee_id' => $employee->id,
                'decided_at' => Carbon::now(),
            ]);

            return $employee->refresh();
        });
    }

    /**
     * The pipeline strip: how many sit at each stage.
     *
     * Every stage appears, including the empty ones — a pipeline with a gap in
     * it reads as a broken screen, and "nobody is at interview" is exactly the
     * thing worth seeing.
     *
     * @return array<string, mixed>
     */
    public function pipeline(): array
    {
        $counts = $this->applicants->countsByStage();

        return [
            'stages' => array_map(
                static fn (ApplicantStage $stage): array => [
                    'stage' => $stage->value,
                    'label' => $stage->label(),
                    'tone' => $stage->tone()->value,
                    'open' => $stage->isOpen(),
                    'count' => $counts[$stage->value] ?? 0,
                ],
                ApplicantStage::cases(),
            ),
            'open' => $this->applicants->openCount(),
            'total' => array_sum($counts),
        ];
    }

    public function fileUrl(?string $path): ?string
    {
        return $this->files->url($path);
    }
}
