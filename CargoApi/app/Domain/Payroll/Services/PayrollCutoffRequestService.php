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

class PayrollCutoffRequestService
{
    public function __construct(
        private readonly CompanyService $company,
        private readonly NotificationService $notifications,
        private readonly Tenant $tenant,
    ) {}

    /** @return Collection<int, PayrollCutoffRequest> */
    public function all(?string $status = null): Collection
    {
        return PayrollCutoffRequest::query()
            ->with(['requestedBy:id,name', 'decidedBy:id,name'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->newestFirst()
            ->get();
    }

    public function pending(): ?PayrollCutoffRequest
    {
        return PayrollCutoffRequest::query()
            ->with(['requestedBy:id,name'])
            ->pending()
            ->newestFirst()
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function file(array $attributes, ?User $author = null): PayrollCutoffRequest
    {
        $open = $this->pending();

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

            $previous = $company->payroll_cutoff_days
                ?? PayrollCalendar::for($company)->days;

            $changes = ['payroll_cutoff_days' => $days];

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

    public function withdraw(PayrollCutoffRequest $request): PayrollCutoffRequest
    {
        $this->mustBePending($request);

        $request->forceFill([
            'status' => PayrollCutoffRequest::WITHDRAWN,
            'decided_at' => now(),
        ])->save();

        return $request->refresh()->load('requestedBy:id,name');
    }

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
