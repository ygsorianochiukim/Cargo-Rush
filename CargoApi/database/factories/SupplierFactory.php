<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            // Unique, because the table is: two suppliers sharing a name inside
            // one company is a 422 in the app and would be a confusing failure
            // in a test that only wanted two rows.
            'name' => fake()->unique()->company(),
            'contact' => fake()->numerify('09## ### ####'),
            'address' => fake()->city(),
            'supplies' => fake()->randomElement([
                'Engine oil, filters, brake parts',
                'Tyres and retreading',
                'Straps, tarpaulins, rope',
                'Meals for the crew',
            ]),
            'status' => StatusValue::Active->value,
        ];
    }

    /** Somebody the office has stopped buying from. */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => StatusValue::Inactive->value]);
    }
}
