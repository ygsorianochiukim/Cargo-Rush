<?php

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\ContractService;
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
