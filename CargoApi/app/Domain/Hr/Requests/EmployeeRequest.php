<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\DTO\LicenceData;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registering somebody, and — only if they drive — their licence.
 *
 * The form used to carry a **Driver record** dropdown: pick, from a list of
 * every driver already on file, the one this employee is. It was wrong in both
 * directions. Somebody being hired as a mechanic was shown a fleet of drivers
 * to choose from for no reason, and somebody being hired *as a driver* had no
 * record to pick, because they are new — so registering a driver meant going to
 * Drivers Management first, creating half a person there, and coming back.
 *
 * So the driver details are separated out and asked for only when the job needs
 * them, which the position itself answers (`positions.drives`). Two fields, a
 * licence number and its expiry, and the service does the rest: it finds the
 * `drivers` row that licence already belongs to, or opens one.
 *
 * Matching on the licence number is what replaces the dropdown. It is unique
 * within a company and it is what a driver is actually filed under — so the
 * person entering it does not have to know whether an operational record
 * already exists, which is the one thing about this they had no way to know.
 */
class EmployeeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $employee = $this->employee();

        return [
            // Optional: allocated by the service when the office has none to
            // give, which is the normal case.
            'employee_no' => [
                'sometimes', 'string', 'max:30',
                Rule::unique('employees', 'employee_no')->ignore($employee?->id)->whereNull('deleted_at'),
            ],
            'first_name' => [$required, 'string', 'max:60'],
            'last_name' => [$required, 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            /**
             * Either a title from the managed list or one typed in.
             *
             * On a create one of the two is required; on a PATCH neither is,
             * so an edit that resends only a corrected surname does not demand
             * the person be reclassified as well.
             */
            'position' => [
                $this->creating() ? 'required_without:position_id' : 'sometimes',
                'string',
                'max:60',
            ],
            /**
             * The job from the managed list, scoped to the caller's company.
             *
             * `exists` queries the table directly and so runs **outside** the
             * tenant scope every read normally sits behind; the company clause
             * is what puts it back. Without it, an id belonging to another firm
             * passes validation and is written to the column — a reference this
             * company can never resolve, because `Position::find()` *is*
             * scoped and answers null.
             *
             * That was survivable while a position was only a job title: the
             * label simply did not get copied. It stopped being survivable when
             * a position started carrying a salary.
             */
            'position_id' => [
                'nullable', 'string',
                Rule::exists('positions', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['sometimes', Rule::in(EmploymentType::values())],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'hired_on' => [$required, 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'contact' => [$required, 'string', 'max:40'],
            // Not unique and not required. Plenty of staff have no address of
            // their own, and it is not the login — creating an account is a
            // separate action that validates the address it is given.
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            /**
             * The opening pay, and the only two fields here that are not
             * columns on the employee.
             *
             * They open a **contract** — its own row, with the date it starts
             * on — because pay is a history rather than a figure, and a raise
             * has to leave last fortnight's payslip saying what it said.
             *
             * Both together or neither. A basis with no figure cannot open a
             * contract and a figure with no basis cannot say what it means, so
             * one alone is answered the same way as nothing: the position's
             * rate card fills it in, which is the usual case — the office picks
             * a job and the figure follows.
             *
             * Sending them on an edit writes a **new** contract dated today. It
             * does not touch the one in force, and nothing else on this form
             * can change anybody's pay.
             */
            'pay_basis' => ['sometimes', Rule::enum(PayBasis::class)],
            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            /**
             * The day the figure starts paying. Today unless stated.
             *
             * Worth being able to say, and not decoration. A rise agreed on the
             * 20th for the 1st of next month is written now and sits there not
             * paying until it arrives; a correction to a figure that was always
             * wrong is backdated so the run being checked picks it up. Both are
             * everyday, and a system that can only mean "from now" makes the
             * second one impossible to express.
             */
            'effective_from' => ['sometimes', 'date'],

            /**
             * Which agencies this person is registered with.
             *
             * All three default to true in the database, so an old client that
             * has never heard of them keeps deducting everything — which is
             * what every roster predating this was doing. Off is for the cases
             * a fleet actually has: somebody not yet registered, a casual hand
             * taken on for the season, a person already contributing through
             * another employer.
             *
             * There is deliberately **no switch for withholding tax**. Whether
             * somebody is taxed is not the firm's to choose, and the module
             * already answers it properly from the BIR's exemption threshold.
             */
            'sss_enrolled' => ['sometimes', 'boolean'],
            'philhealth_enrolled' => ['sometimes', 'boolean'],
            'pagibig_enrolled' => ['sometimes', 'boolean'],

            /**
             * The most one payslip may take off the store tab.
             *
             * Zero — the default — means the whole outstanding balance, which
             * is what a mini-mart tab settled each cutoff actually does. A
             * figure spreads a larger one over several payslips without
             * anybody having to remember to stop.
             */
            'store_deduction_cap_cents' => ['sometimes', 'integer', 'min:0'],

            /**
             * The licence, required exactly when the job drives.
             *
             * `required` rather than `required_if:...`, because what decides it
             * is a row in another table — whether the chosen position's default
             * role is the driver's — and no declarative rule can read that. It
             * is worked out once in `licenceRequired()` and both fields follow
             * it, so they cannot end up demanding different things.
             */
            'licence_no' => [$this->licenceRequired() ? 'required' : 'nullable', 'string', 'max:40'],
            'licence_expiry' => [$this->licenceRequired() ? 'required' : 'nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:255'],
            'photo' => [
                'nullable', 'image',
                'max:'.(int) config('cargo.hr.photo_max_kb'),
            ],
        ];
    }

    /**
     * One driver record, one employee.
     *
     * The old form enforced this with `Rule::unique` on the `driver_id` the
     * client sent. There is no such field any more, so the same rule is now
     * asked of the licence: if that number is already on the roster under
     * somebody else, this is either a typo or two people being registered as
     * the same driver, and both want stopping here rather than at the database.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $licence = $this->string('licence_no')->trim()->value();

                if ($licence === '' || $validator->errors()->has('licence_no')) {
                    return;
                }

                $driverId = Driver::query()->where('licence_no', $licence)->value('id');

                if ($driverId === null) {
                    return;
                }

                $heldBy = Employee::query()
                    ->where('driver_id', $driverId)
                    ->whereKeyNot($this->employee()?->id)
                    ->value('employee_no');

                if ($heldBy !== null) {
                    $validator->errors()->add(
                        'licence_no',
                        "That licence is already on employee {$heldBy}'s record.",
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'licence_no.required' => 'A driver needs their licence number.',
            'licence_expiry.required' => 'A driver needs their licence expiry date.',
            'amount_cents.integer' => 'Send the pay in centavos as a whole number, not pesos.',
            'photo.max' => 'That photograph is too large. An ID photo, not a portrait session.',
        ];
    }

    public function toData(): EmployeeData
    {
        // The photograph is `PhotoStore`'s and the licence is the `drivers`
        // row's; neither is an `employees` column, so neither belongs in a DTO
        // that is handed straight to `Employee::create()`.
        return EmployeeData::fromArray(
            collect($this->validated())->except(['photo', 'licence_no', 'licence_expiry'])->all()
        );
    }

    /**
     * The licence, or null when this submission said nothing about one.
     *
     * Null and "blank" are kept apart deliberately: an edit that resends only a
     * corrected surname must leave the driver record alone, and an empty
     * `LicenceData` would read as an instruction to clear it.
     */
    public function toLicence(): ?LicenceData
    {
        $licence = collect($this->validated())->only(['licence_no', 'licence_expiry'])->all();

        return $licence === [] ? null : LicenceData::fromArray($licence);
    }

    public function photo(): ?UploadedFile
    {
        $photo = $this->file('photo');

        return $photo instanceof UploadedFile ? $photo : null;
    }

    /**
     * Does this submission have to carry a licence?
     *
     * Yes when the job it lands in drives *and* there is no driver record
     * behind the person already. The second half is what keeps an edit
     * workable: correcting a surname on a driver who is already on the fleet
     * must not demand their licence be retyped to save it.
     */
    private function licenceRequired(): bool
    {
        return $this->jobPosition()?->drives === true
            && $this->employee()?->driver_id === null;
    }

    /**
     * The managed position this submission lands in.
     *
     * Falls back to the one the employee already holds, because a PATCH that
     * only corrects a phone number does not resend the position — and reading
     * that silence as "no position" would let somebody edit a driver into
     * having no licence requirement by not mentioning their job.
     *
     * A typed-in **Custom title** has no position row, so it answers null and
     * asks for no licence. That is honest rather than clever: only the managed
     * list knows which jobs drive, and guessing from the words somebody typed
     * would create a driver record for a "Driveway Attendant".
     */
    private function jobPosition(): ?Position
    {
        $positionId = $this->input('position_id') ?: $this->employee()?->position_id;

        return $positionId === null ? null : Position::query()->find($positionId);
    }

    private function employee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
