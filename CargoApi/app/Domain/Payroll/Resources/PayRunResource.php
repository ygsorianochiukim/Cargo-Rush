<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\PayRunLineComponent;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One pay run, with everybody on it.
 *
 * The lines always come along. A run without them is a period and a total,
 * which is not something anybody can check — and the screen that shows a run is
 * the screen somebody checks it on before approving.
 *
 * The statutory figures are broken out per agency as well as totalled, because
 * the monthly remittance is filed per agency on its own form. A single
 * `deductions_cents` would turn that into a spreadsheet exercise.
 *
 * @mixin PayRun
 */
class PayRunResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $lines = $this->lines ?? collect();
        $schedule = app(PayrollService::class)->schedule();

        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            /** `1–15 Sep 2026` — how a period reads on a list. */
            'period_label' => $this->periodLabel(),
            'pay_date' => $this->pay_date?->toDateString(),

            'status' => $this->status,
            'approved_at' => $this->iso($this->approved_at),
            'approved_by_name' => $this->approvedBy?->name,
            'paid_at' => $this->iso($this->paid_at),

            /**
             * The entry this run posted, once it was paid.
             *
             * Null before that, and it is the link that says payroll reached
             * the books rather than stopping at a spreadsheet.
             */
            'journal_entry_id' => $this->journal_entry_id,
            'journal_reference' => $this->journalEntry?->reference,

            /**
             * Which cutoff this run is, and what the firm's deduction policy
             * says about it.
             *
             * On screen this is what makes a ₱0.00 SSS line legible. A payslip
             * with no contributions on it is either correct — because the firm
             * takes them all on the other cutoff — or a mistake, and the reader
             * cannot tell which without being told the policy. Sent with the
             * run rather than fetched from the company separately, so the
             * figures and the explanation of them arrive together.
             */
            'is_first_cutoff' => $this->isFirstCutoff(),
            'deduct_on' => $schedule->value,
            'deduct_on_label' => $schedule->label(),
            'deduct_on_detail' => $schedule->detail(),
            'carries_contributions' => $schedule->carriedOn(
                $this->isFirstCutoff(),
                $this->isOnlyRunOfMonth(),
            ),

            'staff_count' => $lines->count(),
            'gross_cents' => $this->grossCents(),
            'deductions_cents' => $this->deductionsCents(),
            'net_cents' => $this->netCents(),
            'statutory' => $this->statutoryCents(),
            'currency' => 'PHP',

            /**
             * What the client may offer.
             *
             * From the API, because the rules are the API's: a draft can be
             * rebuilt, edited, approved and deleted; an approved run can only
             * be paid; a paid one is finished. Two clients working that out
             * from `status` is two places to get it wrong.
             */
            'can_edit' => $this->isDraft(),
            'can_approve' => $this->isDraft(),
            'can_pay' => $this->isApproved(),

            'notes' => $this->notes,

            'lines' => $lines->map(static fn (PayRunLine $line): array => [
                'id' => $line->id,
                'employee_id' => $line->employee_id,
                'employee_no' => $line->employee_no,
                'name' => $line->name,
                'position' => $line->position,

                /**
                 * How this payslip was worked out, and the workings behind it.
                 *
                 * `basic_cents` alone is uncheckable on anything but a salary:
                 * "₱12,400" of per-trip pay is a figure the person holding it
                 * has no way to verify. `sheet_days` says how many days of the
                 * truck sheet it covers, `days_worked` is what a daily rate
                 * was multiplied by, and `trips` is what a trip rate was — all
                 * three zero on a monthly payslip, where none of the questions
                 * arise.
                 *
                 * Frozen on the line, like every other figure there: the basis
                 * a payslip was computed on has to keep saying what it said
                 * after somebody is moved from a day rate to a salary.
                 */
                'pay_basis' => $line->pay_basis?->value,
                'pay_basis_label' => $line->pay_basis?->label(),
                'days_worked' => $line->days_worked,
                'sheet_days' => $line->sheet_days,
                'trips' => $line->trips,

                'basic_cents' => $line->basic_cents,
                'allowance_cents' => $line->allowance_cents,
                'overtime_cents' => $line->overtime_cents,
                'adjustments_cents' => $line->adjustments_cents,
                'adjustment_note' => $line->adjustment_note,

                /**
                 * The firm's own allowances and deductions — the totals, and
                 * the itemised lines behind them.
                 *
                 * Both, because they answer different questions. The totals are
                 * what the gross and the net are built from; the rows are what
                 * the payslip prints, and a payslip that showed a lump
                 * "allowances ₱3,000" is one the person receiving it cannot
                 * check. The rows are frozen copies, so they say what they said
                 * on the day whatever the catalogue looks like now.
                 */
                'component_earnings_cents' => $line->component_earnings_cents,
                'component_deductions_cents' => $line->component_deductions_cents,
                'components' => $line->components
                    ->map(static fn (PayRunLineComponent $component): array => [
                        'id' => $component->id,
                        'pay_component_id' => $component->pay_component_id,
                        'name' => $component->name,
                        'kind' => $component->kind->value,
                        'sign' => $component->kind->sign(),
                        'taxable' => $component->taxable,
                        'amount_cents' => $component->amount_cents,
                        /**
                         * Did somebody type this onto the payslip?
                         *
                         * Which is the same question as *may it be taken back
                         * off here*. A row from the salary structure is the
                         * catalogue's answer to this period, and removing it on
                         * one payslip would be a correction the next rebuild
                         * silently undoes — so the screen offers it only on the
                         * ones it can honour.
                         */
                        'added_by_hand' => (bool) $component->added_by_hand,
                    ])->values()->all(),

                'sss_cents' => $line->sss_cents,
                'philhealth_cents' => $line->philhealth_cents,
                'pagibig_cents' => $line->pagibig_cents,

                /**
                 * Which contributions this payslip was subject to.
                 *
                 * Frozen on the line and sent with the figures, because
                 * "SSS ₱0.00" has two quite different explanations — the person
                 * is not enrolled, or there was nothing to contribute on — and
                 * only one of them is something for the office to go and fix.
                 * A payslip that showed the zero and not the reason is the line
                 * an employee asks about.
                 */
                'sss_enrolled' => (bool) $line->sss_enrolled,
                'philhealth_enrolled' => (bool) $line->philhealth_enrolled,
                'pagibig_enrolled' => (bool) $line->pagibig_enrolled,

                'withholding_tax_cents' => $line->withholding_tax_cents,
                'other_deductions_cents' => $line->other_deductions_cents,
                /** What came off the store tab — the mini-mart *pautang*. */
                'store_deduction_cents' => $line->store_deduction_cents,
                'deduction_note' => $line->deduction_note,

                /**
                 * Why this payslip is zero, when it is. Null when it is not.
                 *
                 * A ₱0.00 line on a run is almost always one of three things —
                 * the truck sheet names nobody, it names them with no salary
                 * against the day, or a daily rate was never set — and they
                 * need different fixing. Composed from the frozen columns, so
                 * an old payslip goes on explaining itself the way it did.
                 */
                'zero_explanation' => $line->zeroExplanation(),

                'gross_cents' => $line->gross_cents,
                'deductions_cents' => $line->deductions_cents,
                'net_cents' => $line->net_cents,
            ])->values()->all(),

            ...$this->stamps(),
        ];
    }
}
