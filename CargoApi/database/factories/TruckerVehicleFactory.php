<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Models\TruckerVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TruckerVehicle>
 */
class TruckerVehicleFactory extends Factory
{
    protected $model = TruckerVehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plate' => strtoupper($this->faker->bothify('???-###')),
            'model' => $this->faker->randomElement([
                'Isuzu Forward', 'Hino 500', 'Fuso Fighter', 'UD Quester',
            ]),
            // A ten-wheeler's working load. Big enough that an ordinary test
            // load fits, so a test about something else does not fail on
            // capacity.
            'capacity_kg' => 15_000,
            'status' => StatusValue::Available->value,
        ];
    }

    /** In the shop, so it cannot be put under a load. */
    public function inMaintenance(): self
    {
        return $this->state(fn (): array => ['status' => StatusValue::Maintenance->value]);
    }

    public function carrying(int $kg): self
    {
        return $this->state(fn (): array => ['capacity_kg' => $kg]);
    }
}
