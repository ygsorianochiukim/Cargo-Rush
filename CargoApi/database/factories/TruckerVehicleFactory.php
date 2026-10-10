<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\TruckVerification;
use App\Domain\Trucker\Models\TruckerVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TruckerVehicle> */
class TruckerVehicleFactory extends Factory
{
    protected $model = TruckerVehicle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plate' => strtoupper($this->faker->bothify('???-###')),
            'model' => $this->faker->randomElement([
                'Isuzu Forward', 'Hino 500', 'Fuso Fighter', 'UD Quester',
            ]),
            'capacity_kg' => 15_000,
            'status' => StatusValue::Available->value,
            'verification' => TruckVerification::Verified->value,
            'verified_at' => now(),
        ];
    }

    public function awaitingCheck(): self
    {
        return $this->state(fn (): array => [
            'verification' => TruckVerification::Pending->value,
            'verified_at' => null,
        ]);
    }

    public function inMaintenance(): self
    {
        return $this->state(fn (): array => ['status' => StatusValue::Maintenance->value]);
    }

    public function carrying(int $kg): self
    {
        return $this->state(fn (): array => ['capacity_kg' => $kg]);
    }
}
