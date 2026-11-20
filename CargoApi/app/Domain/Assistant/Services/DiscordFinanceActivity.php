<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Billing\Models\Payment;
use App\Domain\Finance\Models\DiscordExpenseReceipt;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DiscordFinanceActivity
{
    /** @param array<string, mixed> $input */
    public function page(AssistantSetting $settings, array $input): array
    {
        $start = Carbon::parse($input['day'], $input['timezone'])->startOfDay();
        $window = [$start->copy()->utc(), $start->copy()->addDay()->utc()];
        $alerts = (bool) ($input['alerts'] ?? false);
        $kind = $input['kind'];
        [$query, $id, $date] = match ($kind) {
            'runs' => $this->runs($alerts),
            'changes' => $this->changes($settings->company_id),
            'payments' => $this->payments($settings->discord_payment_alert_pesos, $alerts),
            'receipt-expenses' => $this->receiptExpenses($alerts),
        };

        $rows = $query->where($date, '>=', $window[0])->where($date, '<', $window[1])
            ->when(isset($input['after']), fn (Builder $query) => $query->where($id, '>', $input['after']))
            ->orderBy($id)->limit(101)->get();
        $page = $rows->take(100);

        return [
            'events' => $page->map(static function (object $row) use ($kind): array {
                $event = (array) $row;
                $event['id'] = (string) $row->id;
                $event['occurred_at'] = Carbon::parse($row->occurred_at)->toIso8601String();

                if ($kind === 'changes') {
                    $event['previous_status'] = json_decode($row->old_values ?? '{}', true)['status'] ?? null;
                    $event['new_status'] = json_decode($row->new_values ?? '{}', true)['status'] ?? null;
                    unset($event['old_values'], $event['new_values']);
                }
                if ($kind === 'payments') {
                    $event['voided'] = $row->deleted_at !== null;
                    $event['paid_on'] = $row->paid_on === null ? null : Carbon::parse($row->paid_on)->toDateString();
                    unset($event['deleted_at']);
                }
                if ($kind === 'receipt-expenses') {
                    $fields = json_decode($row->fields, true);
                    $event['amount'] = $fields['amount'];
                    $event['currency'] = $fields['currency'];
                    $event['reference'] = $fields['reference'] ?? null;
                    unset($event['fields']);
                }

                return $event;
            })->values(),
            'next' => $rows->count() > 100 ? (string) $page->last()->id : null,
        ];
    }

    private function runs(bool $alerts): array
    {
        $changes = DB::table('invoices_logs')->whereColumn('automation_run_id', 'automation_runs.id');
        $query = AutomationRun::query()->where('workflow', AutomationRun::OVERDUE_INVOICES)
            ->select(['id', 'status', 'finished_at as occurred_at'])
            ->addSelect(['changed_count' => (clone $changes)->selectRaw('count(*)')])
            ->when($alerts, fn ($query) => $query->where(fn ($query) => $query
                ->where('status', 'failed')->orWhereExists($changes)))
            ->toBase();

        return [$query, 'id', 'finished_at'];
    }

    private function changes(string $companyId): array
    {
        $query = DB::table('invoices_logs as audit')
            ->join('automation_runs as run', 'run.id', '=', 'audit.automation_run_id')
            ->leftJoin('invoices', function (JoinClause $join) use ($companyId): void {
                $join->on('invoices.id', '=', 'audit.record_id')->where('invoices.company_id', $companyId);
            })
            ->where('run.company_id', $companyId)
            ->where('run.workflow', AutomationRun::OVERDUE_INVOICES)
            ->select(['audit.id', 'audit.record_id as invoice_id', 'invoices.number as invoice_number',
                'audit.old_values', 'audit.new_values', 'audit.created_at as occurred_at']);

        return [$query, 'audit.id', 'audit.created_at'];
    }

    private function payments(?int $threshold, bool $alerts): array
    {
        $query = Payment::withTrashed()
            ->select(['id', 'direction', 'amount_cents', 'currency', 'paid_on', 'deleted_at', 'created_at as occurred_at']);
        if ($alerts) {
            if ($threshold === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereNull('deleted_at')->where('currency', 'PHP')->where('amount_cents', '>=', $threshold * 100);
            }
        }

        return [$query->toBase(), 'id', 'created_at'];
    }

    private function receiptExpenses(bool $alerts): array
    {
        $query = DiscordExpenseReceipt::query()
            ->select('id', 'expense_id', 'fields', 'updated_at as occurred_at')
            ->where('status', 'confirmed');
        if ($alerts) {
            $query->whereRaw('1 = 0');
        }

        return [$query->toBase(), 'id', 'updated_at'];
    }
}
