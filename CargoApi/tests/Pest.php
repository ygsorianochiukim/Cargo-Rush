<?php

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\ContractService;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    // Every feature test runs against a fresh in-memory schema, so the seeded
    // workbook figures are the same on every run.
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Put somebody on a figure, the way hiring them through the form would.
 *
 * Pay is a contract row rather than a column, so a test that builds an employee
 * directly has to open one — and doing it inline, in a dozen files, would be a
 * dozen chances to get the shape of a contract subtly wrong.
 *
 * Dated from the hire date so the contract covers every period a test is likely
 * to build, rather than starting today and quietly leaving last fortnight
 * unpaid.
 */
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

/**
 * A zone card that charges exactly what the old fallback tariff did.
 *
 * Pricing is zone-only: with no zone covering a run, a trip is saved unpriced
 * and cannot be confirmed, dispatched, delivered or billed. Most of the suite
 * is about those transitions and not about pricing, and it was written when a
 * missing card fell through to the config tariff — ₱1,500 + ₱35/km + ₱2/kg,
 * floored at ₱1,500.
 *
 * So this lays down one open-ended zone (0 km and beyond) with one general
 * line at those four figures. Every figure a test worked out from the old
 * tariff is still the right answer, and it is now reached the way production
 * reaches it — off a zone line — rather than off a fallback that no longer
 * exists. Tests *about* pricing build their own card instead.
 */
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
