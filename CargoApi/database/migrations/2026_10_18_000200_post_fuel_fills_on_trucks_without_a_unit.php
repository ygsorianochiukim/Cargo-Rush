<?php

declare(strict_types=1);

use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Services\FuelPostingService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $posting = app(FuelPostingService::class);
        $tenant = app(Tenant::class);

        foreach (Company::query()->pluck('id') as $companyId) {
            $tenant->use($companyId, static function () use ($posting): void {
                FuelRecord::query()
                    ->where('status', StatusValue::Active->value)
                    ->where('posted_cents', 0)
                    ->whereNotNull('vehicle_id')
                    ->where('amount_cents', '>', 0)
                    ->whereNotNull('logged_at')
                    ->each(static fn (FuelRecord $fill) => $posting->sync($fill));
            });
        }
    }

    public function down(): void {}
};
