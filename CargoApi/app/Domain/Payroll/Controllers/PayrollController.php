<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\PayRunLineComponent;
use App\Domain\Payroll\Resources\PayRunResource;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payroll — the run, and the four things you can do to one.
 *
 * Build, adjust, approve, pay. Every one of them is a verb rather than a status
 * PATCH, for the reason confirming a trip and posting a journal entry are:
 * approving freezes figures and tells the office, and paying writes the journal
 * entry that puts the run in the books. A status field that did either when set
 * to a particular value would hide what actually happened.
 *
 * Rebuilding is a POST to the run rather than a PUT, because it is not an edit:
 * it throws the lines away and works them out again from the employee records
 * as they now stand.
 */
class PayrollController extends ApiController
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $runs = $this->payroll->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(PayRunResource::collection($runs), $runs);
    }

    public function show(PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

    /**
     * The legal pay periods in a month — what the choice on screen is made of.
     *
     * A period is one of the firm's own one or two per month rather than a
     * range somebody types. Sent by the API for the reason the account types
     * and the journal categories are: it is a set the server owns, and a client
     * working the calendar out for itself would be a second place to get
     * February wrong — or to keep offering two halves to an office that has
     * switched to paying monthly.
     *
     * Which days those are is now the **company's** setting
     * (`companies.payroll_cutoff_days`), so this answer differs per haulier.
     * That is the point of the endpoint rather than a complication of it: when
     * the cutoff lived in an environment variable, one install could only ever
     * describe one firm's fortnight.
     *
     * With no `month`, this answers with the month holding the period that has
     * just closed, and flags that period as the suggested one. That is what an
     * office wants on a cutoff day: on the 3rd of October, the back half of
     * September.
     */
    public function periods(Request $request): JsonResponse
    {
        $calendar = $this->payroll->calendar();

        $suggested = $calendar->justClosed();
        // The month a period belongs to is the month it *closes* in, so the
        // month on screen comes off the end date. Under a 1st/16th calendar the
        // two are the same; for a firm cutting off on the 10th, the period
        // starting on the 26th of August is September's.
        $month = $this->month($request) ?? $suggested->end;

        $periods = array_map(
            static fn (PayPeriod $period): array => [
                ...$period->toArray(),
                'suggested' => $period->matches($suggested),
            ],
            $calendar->inMonth((int) $month->year, (int) $month->month),
        );

        return $this->payload($periods, [
            'month' => $month->format('Y-m'),
            /**
             * The firm's own cutoff days, sent with the periods they produce.
             *
             * So a client can say *why* these two are the choice — "cut off on
             * the 10th and the 25th" — without holding a second copy of the
             * setting or, worse, working the calendar out for itself. That was
             * the old bug in a new place: two implementations of February.
             */
            'calendar' => $calendar->toArray($month),
        ]);
    }

    /**
     * Open a run for a period and work everybody's pay out.
     *
     * The period is **not** the office's to invent on the day: it is one of the
     * periods its own cutoff days produce, because the statutory figures on
     * every payslip are half a month's contributions and a semi-monthly tax
     * table. A run covering the 3rd to the 20th under a 1st/16th calendar would
     * be wrong twice and wrong invisibly — see `PayPeriod`.
     *
     * Which is a different thing from the cutoff being fixed. A firm that wants
     * its fortnight to end on the 25th changes its cutoff days, in settings,
     * once — and every period offered here moves with it. What it cannot do is
     * type a one-off range into this endpoint and have payroll pretend the
     * statutory tables still apply to it.
     *
     * `pay_date` is when the money actually goes out, which is a different day
     * and the one the journal entry is dated. It defaults to the cutoff on
     * screen and is otherwise left alone: paying on the 20th for a period that
     * closed on the 16th is ordinary, and the books care when the money moved.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Usually the cutoff or a few days after it. Not restricted to the
            // future: an office catching up on last month's payroll is
            // ordinary, and refusing it would send them to the database.
            'pay_date' => ['required', 'date'],
        ]);

        $calendar = $this->payroll->calendar();

        $start = Carbon::parse($validated['period_start']);
        $period = $calendar->matching($start, Carbon::parse($validated['period_end']));

        /**
         * Refused here, at the boundary, and deliberately not inside
         * `PayrollService::build()`.
         *
         * Rebuilding passes a run's own stored dates back through `build()`, so
         * a guard there would make every run opened before this rule existed
         * un-rebuildable — punishing the office for a rule they could not have
         * followed. New runs must be a half-month; old ones stay workable.
         */
        if ($period === null) {
            throw ValidationException::withMessages([
                'period_start' => [$calendar->explainFor($start)],
            ]);
        }

        $run = $this->payroll->build(
            $period->start,
            $period->end,
            Carbon::parse($validated['pay_date']),
            $this->user($request),
        );

        return $this->item(new PayRunResource($run), status: 201);
    }

    /**
     * Work it out again, from the employee records as they now stand.
     *
     * The lines are replaced rather than merged — a run whose lines came from
     * two different calculations is a run nobody can check.
     */
    public function rebuild(Request $request, PayRun $run): JsonResponse
    {
        $rebuilt = $this->payroll->build(
            $run->period_start,
            $run->period_end,
            $run->pay_date,
            $this->user($request),
            $run,
        );

        return $this->item(new PayRunResource($rebuilt));
    }

    /**
     * Correct one payslip — an allowance, overtime, a cash advance, or a
     * statutory figure the table got wrong.
     *
     * The tax is deliberately not recomputed from the new gross; see
     * `PayrollService::adjustLine()`.
     */
    public function adjust(Request $request, PayRun $run, PayRunLine $line): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->getKey(), 404, 'That payslip is not on this run.');

        $validated = $request->validate([
            'allowance_cents' => ['sometimes', 'integer', 'min:0'],
            'overtime_cents' => ['sometimes', 'integer', 'min:0'],
            // Signed: a bonus and a docked half-day are both real.
            'adjustments_cents' => ['sometimes', 'integer'],
            'adjustment_note' => ['nullable', 'string', 'max:255'],
            'sss_cents' => ['sometimes', 'integer', 'min:0'],
            'philhealth_cents' => ['sometimes', 'integer', 'min:0'],
            'pagibig_cents' => ['sometimes', 'integer', 'min:0'],
            'withholding_tax_cents' => ['sometimes', 'integer', 'min:0'],
            'other_deductions_cents' => ['sometimes', 'integer', 'min:0'],
            'deduction_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->payroll->adjustLine($line, $validated);

        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

    /**
     * Put a deduction on one payslip, for this run only.
     *
     * The everyday charge no assignment exists for: a uniform, a breakage, a
     * cash advance against this fortnight. It names itself on the payslip
     * instead of disappearing into the single "other deductions" figure —
     * "₱3,450 other" is a number the person holding it cannot ask about.
     *
     * Either half identifies it. A `pay_component_id` takes the name from the
     * firm's catalogue, so the same charge is spelled the same way every time;
     * a `name` on its own is the one-off nobody is going to catalogue. The
     * amount is always this payslip's, whatever the catalogue says the
     * component is normally worth.
     */
    public function addDeduction(Request $request, PayRun $run, PayRunLine $line): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->getKey(), 404, 'That payslip is not on this run.');

        $validated = $request->validate([
            'pay_component_id' => [
                'nullable', 'string',
                Rule::exists('pay_components', 'id')->where('company_id', app(Tenant::class)->id()),
            ],
            'name' => ['nullable', 'string', 'max:80'],
            'amount_cents' => ['required', 'integer', 'min:0'],
        ]);

        $this->payroll->addDeduction($line, $validated);

        return $this->item(new PayRunResource($this->payroll->find($run->getKey())), status: 201);
    }

    /** Take one back off. Only the hand-added ones — see the service. */
    public function removeDeduction(PayRun $run, PayRunLine $line, PayRunLineComponent $component): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->getKey(), 404, 'That payslip is not on this run.');
        abort_unless($component->pay_run_line_id === $line->getKey(), 404, 'That is not on this payslip.');

        $this->payroll->removeDeduction($component);

        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

    /** Freeze it. Payslips can go out from here. */
    public function approve(Request $request, PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource(
            $this->payroll->approve($run, $this->user($request)),
        ));
    }

    /** The money has gone out — and this is what posts it to the books. */
    public function pay(Request $request, PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource(
            $this->payroll->markPaid($run, $this->user($request)),
        ));
    }

    /** Delete a draft. An approved run has been shown to people. */
    public function destroy(PayRun $run): JsonResponse
    {
        $this->payroll->delete($run);

        return $this->noContent();
    }

    /** Who approved or paid it. Stamped from the token, never a payload. */
    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * `month=2026-09` off the query string, or null for "whichever is due".
     *
     * Unparseable is treated as absent rather than as a 422: this read only
     * offers a choice, and the honest answer to a month nobody can read is the
     * one the office is most likely to want.
     */
    private function month(Request $request): ?Carbon
    {
        $value = trim((string) $request->query('month', ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}
