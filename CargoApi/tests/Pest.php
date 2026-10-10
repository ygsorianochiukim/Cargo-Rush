<?php

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\ContractService;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function payContract(
    Employee $employee,
    string $basis,
    int $amountCents,
    ?string $from = null,
): Contract {
    return app(ContractService::class)->open(
        $employee,
        PayBasis::from($basis),
        $amountCents,
        $from ?? $employee->hired_on ?? now()->subYear(),
    );
}

function zoneCard(
    int $baseCents = 150_000,
    int $perKmCents = 3_500,
    int $perKgCents = 200,
    int $minimumCents = 150_000,
    ?int $maxKm = null,
    ?string $truckCategoryId = null,
): PricingZone {
    $zone = PricingZone::create([
        'name' => 'Everywhere',
        'code' => 'ALL',
        'min_km' => 0,
        'max_km' => $maxKm,
        'position' => 0,
        'status' => 'active',
    ]);

    PricingBracket::create([
        'zone_id' => $zone->id,
        'truck_category_id' => $truckCategoryId,
        'label' => 'Any run',
        'base_cents' => $baseCents,
        'per_km_cents' => $perKmCents,
        'per_kg_cents' => $perKgCents,
        'minimum_cents' => $minimumCents,
        'diesel_step_cents' => 0,
        'position' => 0,
    ]);

    return $zone->refresh();
}
