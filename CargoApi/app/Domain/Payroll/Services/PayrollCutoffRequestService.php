<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Payroll\Models\PayrollCutoffRequest;
use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Services\CompanyService;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asking for the cutoff to be moved, and deciding it.
 *
 * Three verbs, and the middle one is the whole point.
 *
 * **File** records what somebody wants and why, and tells the administrators
 * there is something to decide. It does not change anything.
 *
 * **Approve** changes the company's cutoff *and* settles the request, in one
 * transaction. An approval that only marked a row and left an administrator to
 * go and retype the days would have put the retyping — and the typo — back
 * exactly where this exists to remove it from. It is the same argument paying a
 * pay run makes about posting the journal entry: the decision and its
 * consequence are one act or they are two things that can disagree.
 *
 * **Decline** settles it with a reason, and changes nothing.
 *
 * ## Why approval re-validates
 *
 * The shape of a cutoff cannot change under a pending request — one or two
 * days, distinct, the earlier on the 27th or before, all still true next week.
 * The rule about an **open draft run** can, and moves in both directions: a
 * request filed while payroll was mid-draft becomes safe once that run is
 * approved, and one filed on a clear week becomes unsafe the moment somebody
 * opens a new draft. So the check runs at the moment it matters rather than at
 * the moment it was convenient, and `PayrollCutoffDays::problemWith()` is
 * asked the same question the settings form asks.
 */
class PayrollCutoffRequestService
{
    public function __construct(
        private readonly CompanyService $company,
        private readonly NotificationService $notifications,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return Collection<int, PayrollCutoffRequest>
     */
    public function all(?string $status = null): Collection
    {
        return PayrollCutoffRequest::query()
            ->with(['requestedBy:id,name', 'decidedBy:id,name'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->newestFirst()
            ->get();
    }

    /** The one waiting on a decision, if there is one. */
    public function pending(): ?PayrollCutoffRequest
    {
        return PayrollCutoffRequest::query()
            ->with(['requestedBy:id,name'])
            ->pending()
            ->newestFirst()
            ->first();
    }

    /**
     * File a request, and tell whoever can decide it.
     *
     * One pending request at a time, and the refusal names the one already
     * open. Two people asking for different cutoffs in the same week is not a
     * queue to work through — it is a disagreement inside the office, and an
     * administrator approving one of them without seeing the other would be
     * settling an argument they did not know was happening.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function file(array $attributes, ?User $author = null): PayrollCutoffRequest
    {
        $open = $this->pending();

        // `summary()` is a whole sentence and ends in a full stop, so this
        // starts a new one rather than punctuating into the middle of it.
        abort_if($open !== null, 422, sprintf(
            'There is already a request waiting — %s Asked for by %s. '
            .'Withdraw it before filing another, so nobody is deciding between two.',
            $open?->summary(),
            $open?->requestedBy?->name ?? 'somebody since removed',
        ));

        $request = PayrollCutoffRequest::create([
            ...$attributes,
            'status' => PayrollCutoffRequest::PENDING,
            'requested_by' => $author?->id,
        ]);

        $this->notifications->pushToRoles(
            // The people who can actually act on it. Notifying anybody else
            // would be telling them about a button they do not have.
            roles: [Role::Administrator],
            icon: 'calendar',
            title: 'Payroll cutoff change requested',
            detail: sprintf(
                '%s · asked for by %s · %s',
                $request->summary(),
                $author?->name ?? 'somebody',
                $request->reason,
            ),
            tone: Tone::Warning,
        );

        return $request->refresh()->load('requestedBy:id,name');
    }

    /**
     * Approve it — which is what moves the cutoff.
     *
     * Re-validated first; see the class note on why that is not a formality.
     * One transaction over the company and the request, because a cutoff that
     * moved without the request recording that it had is a setting nobody can
     * account for, and a request marked approved over a company that did not
     * change is worse.
     */
    public function approve(
        PayrollCutoffRequest $request,
        ?User $author = null,
        ?string $note = null,
    ): PayrollCutoffRequest {
        $this->mustBePending($request);

        $days = (array) $request->cutoff_days;

        $problem = PayrollCutoffDays::problemWith($days);

        abort_if($problem !== null, 422, $problem ?? '');

        return DB::transaction(function () use ($request, $days, $author, $note): PayrollCutoffRequest {
            $company = $this->company->current();

            // What they were on, copied before it is overwritten — otherwise
            // the log says a change happened without saying from what.
            $previous = $company->payroll_cutoff_days
                ?? PayrollCalendar::for($company)->days;

            $changes = ['payroll_cutoff_days' => $days];

            // Null means the request had no opinion about the deduction
            // schedule, which is most of them. Writing it anyway would have an
            // approval quietly reset a setting nobody asked about.
            if ($request->payroll_deduct_on !== null) {
                $changes['payroll_deduct_on'] = $request->payroll_deduct_on->value;
            }

            $this->company->update($changes);

            $request->forceFill([
                'status' => PayrollCutoffRequest::APPROVED,
                'decided_by' => $author?->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'previous_cutoff_days' => $previous,
            ])->save();

            $this->tellTheAsker($request, 'approved', $author);

            return $request->refresh()->load(['requestedBy:id,name', 'decidedBy:id,name']);
        });
    }

    /** Decided no. Nothing moves, and the note says why. */
    public function decline(
        PayrollCutoffRequest $request,
        ?User $author = null,
        ?string $note = null,
    ): PayrollCutoffRequest {
        $this->mustBePending($request);

        $request->forceFill([
            'status' => PayrollCutoffRequest::DECLINED,
            'decided_by' => $author?->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->tellTheAsker($request, 'declined', $author);

        return $request->refresh()->load(['requestedBy:id,name', 'decidedBy:id,name']);
    }

    /**
     * The asker taking it back.
     *
     * Its own state rather than a delete, and rather than a decline. A request
     * withdrawn because the office changed its mind is a different fact from
     * one an administrator refused, and only one of them is worth noticing a
     * pattern in.
     */
    public function withdraw(PayrollCutoffRequest $request): PayrollCutoffRequest
    {
        $this->mustBePending($request);

        $request->forceFill([
            'status' => PayrollCutoffRequest::WITHDRAWN,
            'decided_at' => now(),
        ])->save();

        return $request->refresh()->load('requestedBy:id,name');
    }

    /**
     * Tell the person who asked what happened to it.
     *
     * Straight to them rather than to a role: they are the one waiting, and a
     * decision that only appeared on an administrator's screen would leave the
     * office running payroll on a cutoff they had asked to change and had no
     * way of knowing was still in force.
     */
    private function tellTheAsker(PayrollCutoffRequest $request, string $outcome, ?User $author): void
    {
        if ($request->requested_by === null) {
            return;
        }

        $this->notifications->push(
            'calendar',
            "Cutoff change {$outcome}",
            trim(sprintf(
                '%s · %s by %s%s',
                $request->summary(),
                $outcome,
                $author?->name ?? 'the office',
                $request->decision_note === null ? '' : ' · '.$request->decision_note,
            )),
            $outcome === 'approved' ? Tone::Success : Tone::Warning,
            (int) $request->requested_by,
        );
    }

    private function mustBePending(PayrollCutoffRequest $request): void
    {
        abort_unless($request->isPending(), 422, sprintf(
            'That request was already %s. File a new one rather than reopening it.',
            $request->status,
        ));
    }
}
