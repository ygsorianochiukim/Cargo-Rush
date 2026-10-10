<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\DTO\ApplicantData;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\DTO\LicenceData;
use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Repositories\ApplicantRepository;
use App\Domain\Shared\Enums\ApplicantStage;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        if ($data->stage !== null && $data->stage !== $applicant->stage) {
            $attributes['decided_at'] = Carbon::now();
        }

        $applicant->update($attributes);

        return $applicant->refresh();
    }

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

    /** @param array<string, mixed> $overrides */
    public function hire(Applicant $applicant, array $overrides = []): Employee
    {
        abort_if(
            $applicant->employee_id !== null,
            422,
            'This applicant has already been hired.',
        );

        return DB::transaction(function () use ($applicant, $overrides): Employee {
            $licence = LicenceData::fromArray(
                collect($overrides)->only(['licence_no', 'licence_expiry'])->all()
            );

            $employee = $this->employees->register(
                EmployeeData::fromArray([
                    'first_name' => $applicant->first_name,
                    'last_name' => $applicant->last_name,
                    'position' => $applicant->position_applied,
                    'contact' => $applicant->contact,
                    'email' => $applicant->email,
                    'address' => $applicant->address,
                    'hired_on' => Carbon::now()->toDateString(),
                    ...collect($overrides)->except(['licence_no', 'licence_expiry'])->all(),
                ]),
                photo: null,
                licence: $licence,
            );

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

    /** @return array<string, mixed> */
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
