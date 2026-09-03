<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Repositories\EmployeeRepository;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Http\UploadedFile;

/**
 * The roster.
 *
 * Registration is the one verb here that is not plain CRUD, because it has a
 * photograph attached and a payroll number to allocate — and the number has to
 * come from the same place every time or two people end up sharing one.
 */
class EmployeeService extends CrudService
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly PhotoStore $photos,
    ) {}

    protected function repository(): Repository
    {
        return $this->employees;
    }

    /**
     * Register somebody, with their photograph.
     *
     * The employee number is allocated here when the caller offered none, so
     * the office never has to know the numbering scheme — and cannot collide
     * with a number already on a payslip.
     */
    public function register(EmployeeData $data, ?UploadedFile $photo): Employee
    {
        $attributes = $data->persistable();

        if (empty($attributes['employee_no'])) {
            $attributes['employee_no'] = $this->employees->nextEmployeeNo();
        }

        $attributes['photo_path'] = $this->photos->store($photo, 'employees');
        $attributes = $this->withPositionLabel($attributes);

        return Employee::create($attributes)->refresh();
    }

    /**
     * Keep the free-text title in step with the chosen job.
     *
     * The `position` column stays the label everything else reads — the roster
     * table, the performance figures, the search. Denormalising it means a
     * position renamed later leaves old records saying what they said at the
     * time, which for a job title is the honest answer rather than a bug: it is
     * what that person was called then.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withPositionLabel(array $attributes): array
    {
        if (empty($attributes['position_id'])) {
            return $attributes;
        }

        $position = Position::find($attributes['position_id']);

        if ($position !== null) {
            $attributes['position'] = $position->name;
        }

        return $attributes;
    }

    /**
     * Edit a record, replacing the photograph only when a new one arrived.
     *
     * Absent means "not part of this edit", which is the same rule the DTOs
     * follow. Reading a missing file as "remove the photograph" would clear it
     * on every form submission that did not re-upload one.
     */
    public function edit(Employee $employee, EmployeeData $data, ?UploadedFile $photo): Employee
    {
        $attributes = $data->persistable();

        if ($photo !== null) {
            $attributes['photo_path'] = $this->photos->replace($employee->photo_path, $photo, 'employees');
        }

        $employee->update($this->withPositionLabel($attributes));

        // The name on a login is the person's, so a correction to the roster
        // has to reach it. Without this, fixing a misspelled surname leaves the
        // old one on every screen that greets them by name.
        if ($employee->user !== null && ($data->first_name !== null || $data->last_name !== null)) {
            $employee->user->forceFill(['name' => $employee->fresh()->fullName()])->save();
        }

        return $employee->refresh();
    }

    /**
     * The roster headline: how many people, doing what.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $byStatus = $this->employees->countsByStatus();
        $byPosition = $this->employees->countsByPosition();

        arsort($byPosition);

        return [
            'headcount' => array_sum($byStatus),
            'active' => $byStatus['active'] ?? 0,
            'inactive' => $byStatus['inactive'] ?? 0,
            'by_position' => array_map(
                static fn (string $position, int $count): array => [
                    'position' => $position,
                    'count' => $count,
                ],
                array_keys($byPosition),
                array_values($byPosition),
            ),
            'without_account' => $this->employees->all(['has_account' => false])->count(),
        ];
    }

    public function photoUrl(?string $path): ?string
    {
        return $this->photos->url($path);
    }
}
