<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trucker>
 */
class TruckerFactory extends Factory
{
    /** Declared because the model lives under `app/Domain`, not `App\`. */
    protected $model = Trucker::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone' => '0917 '.$this->faker->numerify('### ####'),
            'licence_no' => strtoupper($this->faker->bothify('??##-##-######')),
            'licence_expiry' => now()->addYears(2)->toDateString(),

            /**
             * `pending`, exactly as a real registration lands.
             *
             * The default is the unvetted state rather than the convenient one,
             * so a test that forgets to approve somebody fails the way
             * production would — which is the whole point of the column. Tests
             * that want a working partner say `->approved()`, and say it
             * visibly.
             */
            'status' => StatusValue::Pending->value,
            'is_online' => false,
        ];
    }

    /** Vetted by the office and taking work. */
    public function approved(): self
    {
        return $this->state(fn (): array => [
            'status' => StatusValue::Active->value,
            'is_online' => true,
        ]);
    }

    /** Somewhere, recently — so the job board can measure from them. */
    public function at(float $lat, float $lng): self
    {
        return $this->state(fn (): array => [
            'latitude' => $lat,
            'longitude' => $lng,
            'located_at' => now(),
        ]);
    }
}
