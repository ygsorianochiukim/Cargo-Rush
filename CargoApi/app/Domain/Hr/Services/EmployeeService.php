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
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The roster.
 *
 * Registration is the one verb here that is not plain CRUD, because it has a
 * photograph attached and a payroll number to allocate — and the number has to
 * come from the same place every time or two people end up sharing one.
 *
 * It also decides whether the person needs a `drivers` row, which is the second
 * thing that stops this being CRUD. The office no longer picks one from a list;
 * they type a licence, and `linkDriverRecord()` works out whether that means an
 * existing record or a new one.
 */
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

    /**
     * Register somebody, with their photograph and — if they drive — a licence.
     *
     * The employee number is allocated here when the caller offered none, so
     * the office never has to know the numbering scheme — and cannot collide
     * with a number already on a payslip.
     */
    public function register(EmployeeData $data, ?UploadedFile $photo, ?LicenceData $licence = null): Employee
    {
        $attributes = $data->persistable();

        if (empty($attributes['employee_no'])) {
            $attributes['employee_no'] = $this->employees->nextEmployeeNo();
        }

        $attributes['photo_path'] = $this->photos->store($photo, 'employees');
        $attributes = $this->withPositionLabel($attributes);

        $employee = Employee::create($attributes)->refresh();

        // After the row exists, because a contract points at it.
        $this->openingContract($employee, $data);

        $this->linkDriverRecord($employee, $licence);

        return $employee->refresh();
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
     * Put somebody on a figure, from what the office typed or what the job pays.
     *
     * Three sources, in a fixed order, and the order is the whole rule: what
     * the office typed, then the agreement already in force, then the job's
     * rate card. A figure somebody typed is a figure somebody agreed; what they
     * are already on beats what the job offers a new hire; and the rate card is
     * the default for when nobody has said anything at all.
     *
     * Whatever they answer between them, this writes one contract row or none.
     *
     * None is a real outcome and not a failure: a job nobody has priced, hired
     * into without a stated figure, leaves the person with no contract. They
     * stay off pay runs until the office writes one, which is the safe
     * direction — a ₱0.00 payslip looks exactly like a real one afterwards.
     */
    private function openingContract(Employee $employee, EmployeeData $data, Carbon|string|null $from = null): ?Contract
    {
        $on = $from ?? $employee->hired_on;
        $type = $employee->employment_type ?? EmploymentType::Regular;

        $position = $employee->position_id === null
            ? null
            : Position::find($employee->position_id);

        $terms = $data->contractTerms();

        // Nothing stated: the job's rate card is the whole answer.
        if ($terms === null) {
            return $position === null
                ? null
                : $this->contracts->openFromPosition($employee, $position, $on);
        }

        $current = $employee->contractOn();

        // Whichever half the caller left out, filled from the agreement already
        // in force and then from the job — in that order, because the figure
        // this person is on beats the figure the job offers a new hire.
        $basis = $terms['pay_basis']
            ?? $current?->pay_basis
            ?? $position?->pay_basis
            ?? PayBasis::Monthly;

        $amount = $terms['amount_cents']
            ?? $current?->amount_cents
            ?? $position?->amountFor($type);

        // A stated zero is a real instruction and not an omission: somebody
        // hired on nothing is somebody the office has not agreed a figure with
        // yet. No contract, which keeps them off pay runs — rather than a
        // ₱0.00 contract, which looks exactly like a real one afterwards.
        if ((int) $amount <= 0) {
            return null;
        }

        return $this->contracts->open(
            $employee,
            $basis,
            (int) $amount,
            // A stated date wins: a rise dated forward waits, and a correction
            // dated back reaches the run somebody is checking.
            $terms['effective_from'] ?? $on,
            'Agreed with the office.',
            $type,
        );
    }

    /**
     * Edit a record, replacing the photograph only when a new one arrived.
     *
     * Absent means "not part of this edit", which is the same rule the DTOs
     * follow. Reading a missing file as "remove the photograph" would clear it
     * on every form submission that did not re-upload one.
     */
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

        /**
         * Moving somebody into a different job puts them on that job's rate
         * card — but only when the edit did not name a figure of its own.
         *
         * A promotion is exactly the moment to re-apply the structure, and
         * doing it on every edit would undo a negotiated salary the next time
         * anybody corrected a phone number. So it fires on a **change of
         * position**, or on an edit that states pay outright, and on nothing
         * else.
         *
         * Either way it writes a **new contract** dated today rather than
         * editing the one in force. The old row stays exactly as it was, which
         * is what keeps a pay run rebuilt for last fortnight paying last
         * fortnight's figure.
         */
        $positionChanged = array_key_exists('position_id', $attributes)
            && $attributes['position_id'] !== $employee->position_id;

        $attributes = $this->withPositionLabel($attributes);

        $employee->update($attributes);

        if ($data->contractTerms() !== null || $positionChanged) {
            $this->openingContract($employee->refresh(), $data, Carbon::now());
        }

        // The name on a login is the person's, so a correction to the roster
        // has to reach it. Without this, fixing a misspelled surname leaves the
        // old one on every screen that greets them by name.
        if ($employee->user !== null && ($data->first_name !== null || $data->last_name !== null)) {
            $employee->user->forceFill(['name' => $employee->fresh()->fullName()])->save();
        }

        // After the update, so moving somebody *into* a driving job opens their
        // driver record in the same save rather than needing a second edit.
        $this->linkDriverRecord($employee->refresh(), $licence);

        return $employee->refresh();
    }

    /**
     * Give a driving employee their `drivers` row, or find the one they have.
     *
     * This is what replaced the **Driver record** dropdown, and the reason it
     * can is that a licence number identifies a driver better than a name in a
     * list does. Three cases, and the middle one is why matching beats picking:
     *
     *   **Already linked** — the row is theirs; the licence details are written
     *   through to it, because a renewal is exactly what somebody is doing when
     *   they edit a driver's expiry date on the roster.
     *
     *   **A record exists under that licence** — the fleet knew this driver
     *   before HR did, which is the normal order in a business that was running
     *   before it had an HR module. They are linked, and the operational record
     *   is left standing: every trip, dispatch and GPS ping in the system points
     *   at it, and the whole point of `employees` is to describe that person,
     *   not to replace their history.
     *
     *   **Nobody on file** — a new hire. The row is opened here so registering
     *   a driver is one form rather than two screens in a particular order.
     *
     * Nothing happens for a job that does not drive, and nothing happens when
     * the submission carried no licence — an edit that corrects a phone number
     * must leave the driver record exactly as it was.
     *
     * **A driver moved off the road keeps their record.** There is no branch
     * here that unlinks or deletes one, and there should not be: the history
     * belongs to the person, and taking it away because their job title changed
     * would quietly rewrite who drove which trip.
     */
    private function linkDriverRecord(Employee $employee, ?LicenceData $licence): void
    {
        if ($licence === null || ! $licence->hasLicence()) {
            return;
        }

        // The job decides, not the caller. A licence sent for an office role is
        // ignored rather than obeyed — otherwise a stray field on a payload
        // would put the bookkeeper on the driver roster.
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
            'name' => $employee->fullName(),
            // Available, because somebody just hired is somebody who can be
            // given a run. `Driver::create` would default this anyway; saying
            // it here is what makes that a decision rather than an accident.
            'status' => StatusValue::Available->value,
        ]);

        if ($existing !== null) {
            $existing->update($details);
        }

        $employee->update(['driver_id' => $driver->id]);
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
