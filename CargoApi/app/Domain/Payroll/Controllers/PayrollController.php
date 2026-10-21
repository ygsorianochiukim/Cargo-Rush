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
use App\Domain\Printing\Printables\PayrollRegisterPrintable;
use App\Domain\Printing\Printables\PayslipPrintable;
use App\Domain\Printing\Services\PrintRenderer;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function pdf(Request $request, PayRun $run, PrintRenderer $renderer): Response
    {
        $data = (new PayRunResource($this->payroll->find($run->getKey())))->resolve($request);

        $lineId = $request->query('line');
        $line = $lineId === null ? null : collect($data['lines'])->firstWhere('id', $lineId);

        abort_if($lineId !== null && $line === null, 404, 'That person is not on this run.');

        if ($line === null) {
            $register = app(PayrollRegisterPrintable::class);

            return $renderer->download($register, $register->data($data), "payroll-{$data['reference']}.pdf");
        }

        $payslip = app(PayslipPrintable::class);

        return $renderer->download(
            $payslip,
            $payslip->data($data, [$line]),
            'payslip-'.$data['reference'].'-'.Str::slug($line['name']).'.pdf',
        );
    }

    public function periods(Request $request): JsonResponse
    {
        $calendar = $this->payroll->calendar();

        $suggested = $calendar->justClosed();
        $month = $this->month($request) ?? $suggested->end;

        $periods = array_map(
            static fn (PayPeriod $period): array => [
                ...$period->toArray(),
                'suggested' => $period->matches($suggested),

                'release' => $calendar->releaseFor($period->end)->toDateString(),
            ],
            $calendar->inMonth((int) $month->year, (int) $month->month),
        );

        return $this->payload($periods, [
            'month' => $month->format('Y-m'),
            'calendar' => $calendar->toArray($month),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'pay_date' => ['sometimes', 'date'],
        ]);

        $calendar = $this->payroll->calendar();

        $start = Carbon::parse($validated['period_start']);
        $period = $calendar->matching($start, Carbon::parse($validated['period_end']));

        if ($period === null) {
            throw ValidationException::withMessages([
                'period_start' => [$calendar->explainFor($start)],
            ]);
        }

        $payDate = isset($validated['pay_date'])
            ? Carbon::parse($validated['pay_date'])
            : $calendar->releaseFor($period->end);

        $run = $this->payroll->build(
            $period->start,
            $period->end,
            $payDate,
            $this->user($request),
        );

        return $this->item(new PayRunResource($run), status: 201);
    }

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

    public function adjust(Request $request, PayRun $run, PayRunLine $line): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->getKey(), 404, 'That payslip is not on this run.');

        $validated = $request->validate([
            'allowance_cents' => ['sometimes', 'integer', 'min:0'],
            'overtime_cents' => ['sometimes', 'integer', 'min:0'],
            'adjustments_cents' => ['sometimes', 'integer'],
            'adjustment_note' => ['nullable', 'string', 'max:255'],
            'sss_cents' => ['sometimes', 'integer', 'min:0'],
            'philhealth_cents' => ['sometimes', 'integer', 'min:0'],
            'pagibig_cents' => ['sometimes', 'integer', 'min:0'],
            'withholding_tax_cents' => ['sometimes', 'integer', 'min:0'],
            'employer_sss_cents' => ['sometimes', 'integer', 'min:0'],
            'employer_ec_cents' => ['sometimes', 'integer', 'min:0'],
            'employer_philhealth_cents' => ['sometimes', 'integer', 'min:0'],
            'employer_pagibig_cents' => ['sometimes', 'integer', 'min:0'],
            'other_deductions_cents' => ['sometimes', 'integer', 'min:0'],
            'deduction_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->payroll->adjustLine($line, $validated);

        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

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

    public function removeDeduction(PayRun $run, PayRunLine $line, PayRunLineComponent $component): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->getKey(), 404, 'That payslip is not on this run.');
        abort_unless($component->pay_run_line_id === $line->getKey(), 404, 'That is not on this payslip.');

        $this->payroll->removeDeduction($component);

        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

    public function approve(Request $request, PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource(
            $this->payroll->approve($run, $this->user($request)),
        ));
    }

    public function pay(Request $request, PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource(
            $this->payroll->markPaid($run, $this->user($request)),
        ));
    }

    public function remittanceNumber(Request $request): JsonResponse
    {
        $on = $request->date('on') ?? now();

        return $this->payload(['remittance_no' => PayRun::nextRemittanceNo((int) $on->format('Y'))]);
    }

    public function remit(Request $request, PayRun $run): JsonResponse
    {
        $data = $request->validate([
            'remitted_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [
            'remitted_on.before_or_equal' => 'A remittance cannot be dated in the future.',
        ]);

        return $this->item(new PayRunResource($this->payroll->remit(
            $run,
            Carbon::parse($data['remitted_on'])->toDateString(),
            isset($data['reference']) ? trim((string) $data['reference']) ?: null : null,
            $this->user($request),
        )));
    }

    public function destroy(PayRun $run): JsonResponse
    {
        $this->payroll->delete($run);

        return $this->noContent();
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

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
