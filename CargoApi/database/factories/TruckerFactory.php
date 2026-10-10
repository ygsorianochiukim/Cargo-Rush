<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trucker> */
class TruckerFactory extends Factory
{
    protected $model = Trucker::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone' => '0917 '.$this->faker->numerify('### ####'),
            'licence_no' => strtoupper($this->faker->bothify('??##-##-######')),
            'licence_expiry' => now()->addYears(2)->toDateString(),

            'status' => StatusValue::Pending->value,
            'is_online' => false,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'status' => StatusValue::Active->value,
            'is_online' => true,
        ]);
    }

    public function at(float $lat, float $lng): self
    {
        return $this->state(fn (): array => [
            'latitude' => $lat,
            'longitude' => $lng,
            'located_at' => now(),
        ]);
    }
}
