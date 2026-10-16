<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Support\ChangeLog;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripHistoryService
{
    private const LIMIT = 300;

    private const QUIET = ['id', 'company_id', 'created_at', 'updated_at', 'reference', 'number'];

    /** @var array<string, class-string> */
    private const NAMED = [
        'customer_id' => Customer::class,
        'vehicle_id' => Vehicle::class,
        'driver_id' => Driver::class,
    ];

    /** @return array<int, array<string, mixed>> */
    public function for(Trip $trip): array
    {
        $invoiceIds = Invoice::withTrashed()->where('trip_id', $trip->id)->pluck('number', 'id');

        $rows = collect()
            ->concat($this->rows('trips', [$trip->id], 'Trip'))
            ->concat($this->rows('delivery_logs', DeliveryLog::query()->where('trip_id', $trip->id)->pluck('id')->all(), 'Delivery'))
            ->concat($invoiceIds->map(fn (?string $number, string $id) => $this->rows('invoices', [$id], "Invoice {$number}"))->flatten(1))
            ->concat($this->rows('payments', $this->paymentIds($invoiceIds->keys()->all()), 'Payment'))
            ->sortByDesc(static fn (array $row): string => $row['at'].sprintf('%012d', $row['seq']))
            ->take(self::LIMIT)
            ->values();

        return $this->present($rows);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(string $table, array $ids, string $subject): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table(ChangeLog::logTable($table))
            ->whereIn('record_id', array_map('strval', $ids))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (object $log): array => [
                'key' => "{$table}:{$log->id}",
                'seq' => (int) $log->id,
                'subject' => $subject,
                'action' => $log->action,
                'at' => (string) $log->created_at,
                'user_id' => $log->user_id,
                'ip' => $log->ip_address,
                'url' => $log->url,
                'old' => json_decode((string) $log->old_values, true) ?: [],
                'new' => json_decode((string) $log->new_values, true) ?: [],
            ]);
    }

    /**
     * @param  array<int, string>  $invoiceIds
     * @return array<int, string>
     */
    private function paymentIds(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $current = PaymentAllocation::query()->whereIn('invoice_id', $invoiceIds)->pluck('payment_id');

        $removed = DB::table(ChangeLog::logTable('payment_allocations'))
            ->where(static function ($query) use ($invoiceIds): void {
                foreach ($invoiceIds as $id) {
                    $query->orWhere('old_values', 'like', '%"invoice_id":"'.$id.'"%')
                        ->orWhere('new_values', 'like', '%"invoice_id":"'.$id.'"%');
                }
            })
            ->get(['old_values', 'new_values'])
            ->map(static function (object $log): ?string {
                $values = json_decode((string) ($log->new_values ?? $log->old_values), true) ?: [];

                return isset($values['payment_id']) ? (string) $values['payment_id'] : null;
            });

        return $current->map('strval')->concat($removed)->filter()->unique()->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $names = $this->names($rows);

        return $rows->map(function (array $row) use ($users, $names): array {
            $fields = $row['action'] === 'updated'
                ? array_keys($row['new'])
                : array_keys($row['action'] === 'deleted' ? $row['old'] : $row['new']);

            $changes = [];

            foreach (array_diff($fields, self::QUIET) as $field) {
                $from = $row['old'][$field] ?? null;
                $to = $row['new'][$field] ?? null;

                if ($row['action'] !== 'updated' && ($from ?? $to) === null) {
                    continue;
                }

                $changes[] = [
                    'field' => $field,
                    'from' => $this->display($field, $from, $names),
                    'to' => $this->display($field, $to, $names),
                ];
            }

            return [
                'id' => $row['key'],
                'subject' => $row['subject'],
                'action' => $row['action'],
                'at' => Carbon::parse($row['at'], 'UTC')->toIso8601String(),
                'user' => $row['user_id'] === null ? null : ($users[$row['user_id']] ?? "User #{$row['user_id']}"),
                'ip_address' => $row['ip'],
                'url' => $row['url'],
                'changes' => $changes,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<string, string>>
     */
    private function names(Collection $rows): array
    {
        $names = [];

        foreach (self::NAMED as $field => $model) {
            $ids = $rows->flatMap(static fn (array $row): array => [$row['old'][$field] ?? null, $row['new'][$field] ?? null])
                ->filter()
                ->unique()
                ->values();

            $names[$field] = $ids->isEmpty() ? [] : $model::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->get()
                ->mapWithKeys(static fn ($record): array => [(string) $record->id => (string) ($record->plate ?? $record->name)])
                ->all();
        }

        return $names;
    }

    /** @param array<string, array<string, string>> $names */
    private function display(string $field, mixed $value, array $names): mixed
    {
        if ($value === null || ! isset(self::NAMED[$field])) {
            return $value;
        }

        return $names[$field][(string) $value] ?? $value;
    }
}
