<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Console;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Payroll\Models\PayrollDiscordAlert;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Shared\Enums\PayrollScope;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDuePayrollDraftsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:payroll-drafts';

    protected $description = 'Generate Office and Crew payroll drafts when their planned pay date is due';

    public function handle(PayrollService $payroll): int
    {
        $localDay = now('Asia/Manila')->startOfDay();
        $day = Carbon::parse($localDay->toDateString())->startOfDay();

        return $this->eachCompany(function (Company $company) use ($payroll, $day, $localDay): void {
            if (AssistantSetting::query()->where('enabled', true)->whereNotNull('discord_hr_channel_id')->exists()) {
                PayRun::query()->whereDate('pay_date', $day->toDateString())
                    ->whereDate('period_end', '<', $day->toDateString())
                    ->whereIn('status', [PayRun::DRAFT, PayRun::APPROVED])
                    ->where('created_at', '<', $localDay->copy()->utc())
                    ->eachById(static fn (PayRun $run) => PayrollDiscordAlert::enqueue($run, 'due'));
            }

            if ($company->payroll_auto_drafts !== true) {
                return;
            }

            $calendar = $company->payrollCalendar();
            foreach ($calendar->between($day->copy()->subDays(32), $day) as $period) {
                $payDate = $calendar->releaseFor($period->end);
                $draftDate = $payDate->max($period->end->copy()->addDay());
                if ($draftDate->isSameDay($day)) {
                    foreach (PayrollScope::cases() as $scope) {
                        $payroll->buildScheduledDraft($period, $payDate, $scope);
                    }
                }
            }
        });
    }
}
