<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\DTO;

use App\Domain\Tenancy\Models\Company;

final class CarrierListing
{
    public function __construct(
        public readonly Company $company,
        public readonly ?float $distance_km,
        public readonly int $vehicles_ready,
        public readonly int $capacity_kg,
        public readonly bool $linked,
    ) {}

    public function sortKey(): float
    {
        return $this->distance_km ?? PHP_FLOAT_MAX;
    }
}
