<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    use SeedsIntoACompany;

    private const HOME = [8.4856, 124.5808, 'Iponan, Cagayan de Oro'];

    private const NEIGHBOURS = [
        [
            'Bay Coast Logistics',
            'bay-coast-logistics',
            '0917 555 0110',
            'Tibanga Highway, Iligan City',
            8.2280,
            124.2452,
            [
                ['ILI 1120', 'Isuzu Elf 4W', 4000, 'active'],
                ['ILI 3345', 'Hino 300', 3500, 'available'],
                ['ILI 8890', 'Fuso Canter', 6000, 'available'],
            ],
        ],
        [
            'Bukidnon Highland Freight',
            'bukidnon-highland-freight',
            '0918 555 0244',
            'Sayre Highway, Valencia, Bukidnon',
            7.9064,
            125.0944,
            [
                ['BUK 2210', 'Isuzu Forward', 8000, 'active'],
                ['BUK 4460', 'Hino 500', 8000, 'active'],
                ['BUK 7712', 'Hyundai Mighty', 3000, 'maintenance'],
            ],
        ],
    ];

    public function run(): void
    {
        $this->pinTheHomeYard();

        foreach (self::NEIGHBOURS as [$name, $code, $phone, $address, $lat, $lng, $fleet]) {
            $company = Company::withTrashed()->firstWhere('code', $code)
                ?? Company::create(['name' => $name, 'code' => $code]);

            if ($company->trashed()) {
                $company->restore();
            }

            $company->update([
                'contact_name' => 'Operations desk',
                'contact_phone' => $phone,
                'address' => $address,
                'latitude' => $lat,
                'longitude' => $lng,
                'status' => StatusValue::Active->value,
            ]);

            app(CompanyProvisioner::class)->provision($company);

            $this->fleetFor($company, $fleet);
        }

        $this->command?->info('Two neighbouring carriers on the platform, pinned and crewed with units.');
    }

    private function pinTheHomeYard(): void
    {
        $this->intoCompany(function (Company $company): void {
            if ($company->isPinned()) {
                return;
            }

            [$lat, $lng, $address] = self::HOME;

            $company->update([
                'latitude' => $lat,
                'longitude' => $lng,
                'address' => $company->address ?: $address,
            ]);
        });
    }

    /** @param array<int, array{0: string, 1: string, 2: int, 3: string}> $rows */
    private function fleetFor(Company $company, array $rows): void
    {
        app(Tenant::class)->use($company, function () use ($rows): void {
            foreach ($rows as [$plate, $model, $capacity, $status]) {
                Vehicle::updateOrCreate(
                    ['plate' => $plate],
                    [
                        'model' => $model,
                        'registration_no' => 'LTO-2025-'.preg_replace('/\D/', '', $plate),
                        'capacity_kg' => $capacity,
                        'status' => $status,
                    ],
                );
            }
        });
    }
}
