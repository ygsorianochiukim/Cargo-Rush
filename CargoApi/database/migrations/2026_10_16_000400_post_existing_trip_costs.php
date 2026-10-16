<?php

declare(strict_types=1);

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Services\TripCostPostingService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $costs = app(TripCostPostingService::class);
        $tenant = app(Tenant::class);

        foreach (Company::query()->pluck('id') as $companyId) {
            $tenant->use($companyId, static function () use ($costs): void {
                $withAllowance = Trip::query()
                    ->where(static fn ($q) => $q->where('driver_allowance_cents', '>', 0)->orWhere('helper_allowance_cents', '>', 0))
                    ->pluck('id');

                DeliveryLog::query()
                    ->whereNotNull('delivered_at')
                    ->where(static fn ($q) => $q->where('fuel_cost_cents', '>', 0)->orWhereIn('trip_id', $withAllowance))
                    ->each(static fn (DeliveryLog $log) => $costs->sync($log));
            });
        }
    }

    public function down(): void {}
};
