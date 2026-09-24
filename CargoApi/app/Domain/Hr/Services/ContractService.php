<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Support\Carbon;

/**
 * Writing down what somebody is paid.
 *
 * Every pay figure in this system is written here and nowhere else, which is
 * what makes the history trustworthy: there is one door, and it only ever adds.
 *
 * ## Nothing in here edits
 *
 * A rise appends a row. So does a correction, a regularisation, and a move to a
 * different basis. That is not bureaucracy for its own sake — it is the only
 * arrangement under which a pay run rebuilt for last March still pays last
 * March's figure, which payroll depends on, because a draft is rebuilt every
 * time somebody corrects a line.
 *
 * The one thing that does delete is a row written by mistake, and that is a
 * soft delete on the row itself rather than a method here.
 */
class ContractService
{
    /**
     * Put somebody on a figure from a given day.
     *
     * The tier defaults to what the employee is engaged as, because that is
     * almost always the honest answer and making every caller pass it would
     * mean five places deciding it. Pass one explicitly when the figure came
     * off a different column of the rate card than the person's own stage —
     * which happens, and is exactly the sort of thing a contract should record
     * rather than leave to be inferred later.
     */
    public function open(
        Employee $employee,
        PayBasis $basis,
        int $amountCents,
        Carbon|string|null $effectiveFrom = null,
        ?string $reason = null,
        ?EmploymentType $tier = null,
        ?User $author = null,
    ): Contract {
        $contract = new Contract([
            'employee_id' => $employee->getKey(),
            'pay_basis' => $basis->value,
            'tier' => ($tier ?? $employee->employment_type ?? EmploymentType::Regular)->tier()->value,
            'amount_cents' => max(0, $amountCents),
            'effective_from' => Carbon::parse($effectiveFrom ?? Carbon::now())->toDateString(),
            'reason' => $reason,
            'created_by' => $author?->getKey(),
        ]);

        $contract->save();

        // The relation is very likely already loaded on the caller's copy — a
        // hire reads it back on the same request — and leaving a stale one
        // behind means the response reports the figure from before this row.
        $employee->unsetRelation('contracts');

        return $contract->refresh();
    }

    /**
     * The opening contract for a new hire, taken from the job's rate card.
     *
     * Null where the position has no figure for that tier, and null is the
     * right answer rather than a zero row: a job nobody has priced leaves the
     * office to type what was agreed, and a ₱0.00 contract looks identical to a
     * real one on every screen afterwards.
     *
     * Dated from the hire date rather than today, so a record entered a week
     * late still says the agreement started when the person did — which is what
     * a run covering that week has to see.
     */
    public function openFromPosition(
        Employee $employee,
        Position $position,
        Carbon|string|null $effectiveFrom = null,
        ?User $author = null,
    ): ?Contract {
        $type = $employee->employment_type ?? EmploymentType::Regular;
        $starting = $position->startingPay($type);

        if ($starting === null) {
            return null;
        }

        return $this->open(
            $employee,
            PayBasis::from($starting['pay_basis']),
            $starting['amount_cents'],
            $effectiveFrom ?? $employee->hired_on,
            sprintf('Hired into %s on the %s rate.', $position->name, $type->tier()->label()),
            $type,
            $author,
        );
    }

    /**
     * A rise, keeping everything the current agreement already settled.
     *
     * The basis and the tier carry over because a rise is a change of figure
     * and nothing else — moving somebody from monthly to per-trip is a
     * different conversation and uses `open()` with both stated. Somebody with
     * no contract at all is opened on a monthly one, which is what the rest of
     * the system assumes when nothing says otherwise.
     */
    public function raise(
        Employee $employee,
        int $amountCents,
        Carbon|string|null $effectiveFrom = null,
        ?string $reason = null,
        ?User $author = null,
    ): Contract {
        $current = $employee->contractOn();

        return $this->open(
            $employee,
            $current?->pay_basis ?? PayBasis::Monthly,
            $amountCents,
            $effectiveFrom,
            $reason,
            $current?->tier,
            $author,
        );
    }
}
