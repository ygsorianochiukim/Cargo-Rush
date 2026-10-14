<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\DTO\LicenceData;
use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Repositories\EmployeeRepository;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class EmployeeService extends CrudService
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly PhotoStore $photos,
        private readonly ContractService $contracts,
    ) {}

    protected function repository(): Repository
    {
        return $this->employees;
    }

    public function register(EmployeeData $data, ?UploadedFile $photo, ?LicenceData $licence = null): Employee
    {
        $attributes = $data->persistable();

        if (empty($attributes['employee_no'])) {
            $attributes['employee_no'] = $this->employees->nextEmployeeNo();
        }

        $attributes['photo_path'] = $this->photos->store($photo, 'employees');
        $attributes = $this->withPositionLabel($attributes);

        $employee = Employee::create($attributes)->refresh();

        $this->openingContract($employee, $data);

        $this->linkDriverRecord($employee, $licence);

        return $employee->refresh();
    }

    /**
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

    private function openingContract(Employee $employee, EmployeeData $data, Carbon|string|null $from = null): ?Contract
    {
        $on = $from ?? $employee->hired_on;
        $type = $employee->employment_type ?? EmploymentType::Regular;

        $position = $employee->position_id === null
            ? null
            : Position::find($employee->position_id);

        $terms = $data->contractTerms();

        if ($terms === null) {
            return $position === null
                ? null
                : $this->contracts->openFromPosition($employee, $position, $on);
        }

        $current = $employee->contractOn();

        $basis = $terms['pay_basis']
            ?? $current?->pay_basis
            ?? $position?->pay_basis
            ?? PayBasis::Monthly;

        $amount = $terms['amount_cents']
            ?? $current?->amount_cents
            ?? $position?->amountFor($type);

        if ((int) $amount <= 0) {
            return null;
        }

        return $this->contracts->open(
            $employee,
            $basis,
            (int) $amount,
            $terms['effective_from'] ?? $on,
            'Agreed with the office.',
            $type,
        );
    }

    public function edit(
        Employee $employee,
        EmployeeData $data,
        ?UploadedFile $photo,
        ?LicenceData $licence = null,
    ): Employee {
        $attributes = $data->persistable();

        if ($photo !== null) {
            $attributes['photo_path'] = $this->photos->replace($employee->photo_path, $photo, 'employees');
        }

        $positionChanged = array_key_exists('position_id', $attributes)
            && $attributes['position_id'] !== $employee->position_id;

        $attributes = $this->withPositionLabel($attributes);

        $employee->update($attributes);

        if ($data->contractTerms() !== null || $positionChanged) {
            $this->openingContract($employee->refresh(), $data, Carbon::now());
        }

        if ($employee->user !== null && ($data->first_name !== null || $data->last_name !== null)) {
            $employee->user->forceFill(['name' => $employee->fresh()->fullName()])->save();
        }

        $this->linkDriverRecord($employee->refresh(), $licence);

        return $employee->refresh();
    }

    public function enrolCrew(Driver $driver): Employee
    {
        $existing = Employee::query()->where('driver_id', $driver->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $position = Position::query()->where('key', $driver->role->value)->first();
        [$first, $last] = $this->splitName($driver->name);

        $userTaken = $driver->user_id === null
            || Employee::withTrashed()->where('user_id', $driver->user_id)->exists();

        $employee = Employee::create([
            'employee_no' => $this->employees->nextEmployeeNo(),
            'first_name' => $first,
            'last_name' => $last,
            'position' => $position?->name ?? $driver->role->label(),
            'position_id' => $position?->id,
            'employment_type' => EmploymentType::Probationary->value,
            'status' => StatusValue::Active->value,
            'hired_on' => Carbon::today()->toDateString(),
            'contact' => $driver->contact ?? '',
            'driver_id' => $driver->id,
            'user_id' => $userTaken ? null : $driver->user_id,
        ])->refresh();

        if ($position !== null) {
            $this->contracts->openFromPosition($employee, $position, $employee->hired_on);
        }

        return $employee;
    }

    public function syncCrew(Driver $driver): void
    {
        $employee = Employee::query()->where('driver_id', $driver->id)->first();

        if ($employee === null) {
            $this->enrolCrew($driver);

            return;
        }

        [$first, $last] = $this->splitName($driver->name);

        $changes = ['first_name' => $first, 'last_name' => $last];

        if ($driver->contact !== null && $driver->contact !== '') {
            $changes['contact'] = $driver->contact;
        }

        $employee->fill($changes);

        if ($employee->isDirty()) {
            $employee->save();
        }
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return [trim($name), ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    private function linkDriverRecord(Employee $employee, ?LicenceData $licence): void
    {
        if ($employee->driver !== null) {
            $employee->driver->forceFill(['name' => $employee->fullName()])->save();
        }

        if ($licence === null || ! $licence->hasLicence()) {
            $this->linkHelperRecord($employee);

            return;
        }

        if ($employee->jobPosition?->drives !== true) {
            return;
        }

        $details = array_filter([
            'licence_no' => $licence->licence_no,
            'licence_expiry' => $licence->licence_expiry,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($employee->driver !== null) {
            $employee->driver->update($details);

            return;
        }

        $existing = Driver::query()->where('licence_no', $licence->licence_no)->first();

        $driver = $existing ?? Driver::create([
            ...$details,
            'role' => $this->crewRoleFor($employee)->value,
            'name' => $employee->fullName(),
            'contact' => $employee->contact,
            'status' => StatusValue::Available->value,
        ]);

        if ($existing !== null) {
            $existing->update($details);
        }

        $employee->update(['driver_id' => $driver->id]);
    }

    private function linkHelperRecord(Employee $employee): void
    {
        if ($employee->driver_id !== null || $this->crewRoleFor($employee) !== CrewRole::Helper) {
            return;
        }

        $driver = Driver::create([
            'role' => CrewRole::Helper->value,
            'name' => $employee->fullName(),
            'contact' => $employee->contact,
            'status' => StatusValue::Available->value,
        ]);

        $employee->update(['driver_id' => $driver->id]);
    }

    private function crewRoleFor(Employee $employee): CrewRole
    {
        return $employee->jobPosition?->key === CrewRole::Helper->value
            ? CrewRole::Helper
            : CrewRole::Driver;
    }

    /** @return array<string, mixed> */
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
