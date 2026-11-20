<?php

declare(strict_types=1);

namespace App\Domain\Automation\Controllers;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AutomationController extends ApiController
{
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $runs = $this->runs($tenant);
        $history = (clone $runs)->latest('started_at')->orderByDesc('id')->paginate($this->perPage($request, 15));
        $active = (clone $runs)->where('status', 'running')
            ->where(function (Builder $query): void {
                $cutoff = now()->subHour();
                $query->where('started_at', '>=', $cutoff)
                    ->orWhereExists(DB::table('invoices_logs')
                        ->whereColumn('automation_run_id', 'automation_runs.id')
                        ->where('created_at', '>=', $cutoff));
            })
            ->latest('started_at')->orderByDesc('id')->get();
        $latest = $runs->where('workflow', AutomationRun::OVERDUE_INVOICES)
            ->latest('started_at')->orderByDesc('id')->first();

        return $this->payload([
            'active' => $active->map($this->runData(...)),
            'history' => $history->getCollection()->map($this->runData(...)),
            'latest_finance_run' => $latest === null ? null : $this->runData($latest),
        ], [
            'page' => $history->currentPage(),
            'per_page' => $history->perPage(),
            'total' => $history->total(),
        ]);
    }

    public function show(Request $request, Tenant $tenant, string $run): JsonResponse
    {
        $record = $this->runs($tenant)->findOrFail($run);
        $changes = DB::table('invoices_logs as audit')
            ->leftJoin('invoices', function (JoinClause $join) use ($record): void {
                $join->on('invoices.id', '=', 'audit.record_id')
                    ->where('invoices.company_id', $record->company_id);
            })
            ->where('audit.automation_run_id', $record->getKey())
            ->select('audit.id', 'audit.record_id', 'audit.old_values', 'audit.new_values', 'audit.created_at', 'invoices.number', 'invoices.deleted_at')
            ->orderByDesc('audit.id')
            ->paginate($this->perPage($request));

        return $this->payload([
            'run' => $this->runData($record),
            'changes' => $changes->getCollection()->map(static fn (object $change): array => [
                'id' => $change->id,
                'invoice_id' => $change->record_id,
                'invoice_number' => $change->number,
                'invoice_available' => $change->number !== null && $change->deleted_at === null,
                'before' => json_decode($change->old_values ?? '{}', true)['status'] ?? null,
                'after' => json_decode($change->new_values ?? '{}', true)['status'] ?? null,
                'changed_at' => Carbon::parse($change->created_at)->toIso8601String(),
            ]),
            'can_open_invoices' => $request->user()->hasPermission('billing.view'),
        ], [
            'page' => $changes->currentPage(),
            'per_page' => $changes->perPage(),
            'total' => $changes->total(),
        ]);
    }

    private function runs(Tenant $tenant): Builder
    {
        abort_if(! $tenant->check() || $tenant->isNobody(), 404);

        $audit = DB::table('invoices_logs')->whereColumn('automation_run_id', 'automation_runs.id');

        return AutomationRun::query()->select('automation_runs.*')->addSelect([
            'changed_count' => (clone $audit)->selectRaw('count(*)'),
            'last_change_at' => $audit->select('created_at')->orderByDesc('id')->limit(1),
        ]);
    }

    private function runData(AutomationRun $run): array
    {
        $lastActivity = $run->last_change_at === null ? $run->started_at : Carbon::parse($run->last_change_at);
        $status = $run->status;
        if ($status === 'running' && $lastActivity->lt(now()->subHour())) {
            $status = 'unconfirmed';
        }

        return [
            'id' => $run->getKey(),
            'workflow' => $run->workflow,
            'trigger' => $run->trigger,
            'status' => $status,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'changed_count' => (int) $run->changed_count,
        ];
    }
}
