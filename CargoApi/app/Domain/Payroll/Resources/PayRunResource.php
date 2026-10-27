<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\PayRunLineComponent;
use App\Domain\Payroll\Models\PayRunLineTrip;
use App\Domain\Payroll\Services\HiredOutCrew;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** @mixin PayRun */
class PayRunResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $lines = $this->lines ?? collect();
        $schedule = app(PayrollService::class)->schedule();
        $hiredOut = $this->hiredOut($lines);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'scope' => $this->scope()->value,
            'scope_label' => $this->scope()->label(),

            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'period_label' => $this->periodLabel(),
            'pay_date' => $this->pay_date?->toDateString(),

            'status' => $this->status,
            'approved_at' => $this->iso($this->approved_at),
            'approved_by_name' => $this->approvedBy?->name,
            'paid_at' => $this->iso($this->paid_at),

            'owed_to_agencies_cents' => $this->resource->owedToAgenciesCents(),
            'remittance_due_on' => $this->resource->remittanceDueOn()->toDateString(),
            'remitted_on' => $this->remitted_on?->toDateString(),
            'remittance_no' => $this->remittance_no,
            'remittance_reference' => $this->remittance_reference,

            'journal_entry_id' => $this->journal_entry_id,
            'journal_reference' => $this->journalEntry?->reference,

            'is_first_cutoff' => $this->isFirstCutoff(),
            'deduct_on' => $schedule->value,
            'deduct_on_label' => $schedule->label(),
            'deduct_on_detail' => $schedule->detail($this->cutoffCount()),
            'carries_contributions' => $schedule->carriedOn(
                $this->cutoffIndex(),
                $this->cutoffCount(),
            ),

            'staff_count' => $lines->count(),
            'gross_cents' => $this->grossCents(),
            'deductions_cents' => $this->deductionsCents(),
            'net_cents' => $this->netCents(),
            'statutory' => $this->statutoryCents(),
            'employer_contributions' => $this->employerContributionsCents(),
            'currency' => 'PHP',

            'can_edit' => $this->isDraft(),
            'can_approve' => $this->isDraft(),
            'can_pay' => $this->isApproved(),

            'notes' => $this->notes,

            'lines' => $lines->map(fn (PayRunLine $line): array => [
                'id' => $line->id,
                'employee_id' => $line->employee_id,
                'employee_no' => $line->employee_no,
                'name' => $line->name,
                'position' => $line->position,
                'crew' => (bool) $line->crew,

                'pay_basis' => $line->pay_basis?->value,
                'pay_basis_label' => $line->pay_basis?->label(),
                'days_worked' => $line->days_worked,
                'sheet_days' => $line->sheet_days,
                'trips' => $line->trips,
                'trip_details' => $this->tripDetails($line),
                'hired_out' => $hiredOut[$this->driverIdOf($line) ?? ''] ?? [],

                'basic_cents' => $line->basic_cents,
                'allowance_cents' => $line->allowance_cents,
                'overtime_cents' => $line->overtime_cents,
                'adjustments_cents' => $line->adjustments_cents,
                'adjustment_note' => $line->adjustment_note,

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
                        'added_by_hand' => (bool) $component->added_by_hand,
                    ])->values()->all(),

                'sss_cents' => $line->sss_cents,
                'philhealth_cents' => $line->philhealth_cents,
                'pagibig_cents' => $line->pagibig_cents,

                'sss_enrolled' => (bool) $line->sss_enrolled,
                'philhealth_enrolled' => (bool) $line->philhealth_enrolled,
                'pagibig_enrolled' => (bool) $line->pagibig_enrolled,

                'withholding_tax_cents' => $line->withholding_tax_cents,

                'employer_sss_cents' => $line->employer_sss_cents,
                'employer_ec_cents' => $line->employer_ec_cents,
                'employer_philhealth_cents' => $line->employer_philhealth_cents,
                'employer_pagibig_cents' => $line->employer_pagibig_cents,
                'employer_contributions_cents' => $line->employerContributionsCents(),
                'other_deductions_cents' => $line->other_deductions_cents,
                'store_deduction_cents' => $line->store_deduction_cents,
                'deduction_note' => $line->deduction_note,

                'zero_explanation' => $line->zeroExplanation(),

                'gross_cents' => $line->gross_cents,
                'deductions_cents' => $line->deductions_cents,
                'net_cents' => $line->net_cents,
            ])->values()->all(),

            ...$this->stamps(),
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function tripDetails(PayRunLine $line): ?array
    {
        if (! $line->relationLoaded('tripLinks')) {
            return null;
        }

        $driverId = $line->relationLoaded('employee') ? $line->employee?->driver_id : null;

        return $line->tripLinks
            ->map(static fn (PayRunLineTrip $link): ?array => $link->trip === null ? null : [
                'id' => $link->trip->id,
                'reference' => $link->trip->reference,
                'origin' => $link->trip->origin,
                'destination' => $link->trip->destination,
                'plate' => $link->trip->vehicle?->plate,
                'seat' => $driverId !== null && $link->trip->driver_id === $driverId ? 'driver' : 'helper',
                'on' => self::dayOf($link->trip->deliveryLog?->delivered_at ?? $link->trip->scheduled_at),
            ])
            ->filter()
            ->sortBy('on')
            ->values()
            ->all();
    }

    private static function dayOf(mixed $moment): ?string
    {
        return $moment === null ? null : Carbon::parse($moment)->toDateString();
    }

    private function driverIdOf(PayRunLine $line): ?string
    {
        return $line->relationLoaded('employee') ? $line->employee?->driver_id : null;
    }

    /**
     * @param  Collection<int, PayRunLine>  $lines
     * @return array<string, list<array{plate: string|null, arrangement: string, arrangement_label: string, from: string, to: string}>>
     */
    private function hiredOut($lines): array
    {
        if (! $this->isCrewRun() || $this->period_start === null || $this->period_end === null) {
            return [];
        }

        $driverIds = $lines->map(fn (PayRunLine $line): ?string => $this->driverIdOf($line))->filter()->values()->all();

        if ($driverIds === []) {
            return [];
        }

        $spans = app(HiredOutCrew::class)->spansFor($driverIds, $this->period_start, $this->period_end);

        return array_map(static fn (array $rows): array => array_map(static fn (array $span): array => [
            'plate' => $span['plate'],
            'arrangement' => $span['arrangement'],
            'arrangement_label' => $span['arrangement_label'],
            'from' => $span['from']->toDateString(),
            'to' => $span['to']->toDateString(),
        ], $rows), $spans);
    }
}
