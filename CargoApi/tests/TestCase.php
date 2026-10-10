<?php

namespace Tests;

use App\Domain\Inspection\DTO\InspectionData;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->oldest()->first()
            ?? $this->makeCompany('Test Haulage', 'test-haulage');

        app(Tenant::class)->set($this->company);
    }

    protected function makeCompany(string $name, ?string $code = null): Company
    {
        return Company::create([
            'name' => $name,
            'code' => $code ?? Company::codeFor($name),
        ]);
    }

    protected function passPreTripCheck(string $tripId): void
    {
        $trip = Trip::query()->findOrFail($tripId);

        $results = collect(app(InspectionService::class)->checklist())
            ->mapWithKeys(static fn (array $item): array => [$item['key'] => true])
            ->all();

        app(InspectionService::class)->submit(
            InspectionData::fromArray([
                'trip_id' => $trip->getKey(),
                'vehicle_id' => $trip->vehicle_id,
                'driver_id' => $trip->driver_id,
                'results' => $results,
            ]),
        );
    }

    /**
     * @template T
     *
     * @param \Closure():
     * @return T
     */
    protected function asCompany(Company $company, \Closure $callback): mixed
    {
        return app(Tenant::class)->use($company, $callback);
    }
}
