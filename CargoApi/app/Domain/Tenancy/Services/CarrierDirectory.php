<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\DTO\CarrierListing;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CarrierDirectory
{
    public const DEFAULT_RADIUS_KM = 150.0;

    public const MAX_RADIUS_KM = 1000.0;

    public const MAX_RESULTS = 25;

    /**
     * @param  string[]  $linkedCompanyIds
     * @return Collection<int, CarrierListing>
     */
    public function near(
        ?float $lat = null,
        ?float $lng = null,
        ?float $radiusKm = null,
        ?string $search = null,
        array $linkedCompanyIds = [],
        int $limit = self::MAX_RESULTS,
    ): Collection {
        $radius = min(self::MAX_RADIUS_KM, max(1.0, $radiusKm ?? self::DEFAULT_RADIUS_KM));
        $located = $lat !== null && $lng !== null;

        $found = $this->discoverable($lat, $lng, $radius, $search);

        if ($located) {
            $found = $found->filter(
                fn (Company $company): bool => (float) $company->distanceKmFrom($lat, $lng) <= $radius,
            );
        }

        $found = $found->take($limit);

        $linked = $search === null || trim($search) === ''
            ? $this->linked($linkedCompanyIds, $found->keys()->all())
            : new Collection;

        return $this->listings($found->concat($linked), $lat, $lng, $linkedCompanyIds)
            ->sortBy(fn (CarrierListing $listing): array => [$listing->sortKey(), $listing->company->name])
            ->values();
    }

    /** @param string[] $linkedCompanyIds */
    public function resolve(string $companyId, array $linkedCompanyIds = []): Company
    {
        $company = Company::query()
            ->where('id', $companyId)
            ->where(function (Builder $query) use ($linkedCompanyIds): void {
                $query->where(function (Builder $pinned): void {
                    $pinned->whereNotNull('latitude')->whereNotNull('longitude');
                });

                if ($linkedCompanyIds !== []) {
                    $query->orWhereIn('id', $linkedCompanyIds);
                }
            })
            ->first();

        abort_if($company === null, 404, 'No carrier of that id is taking requests.');

        abort_unless(
            $company->isActive(),
            422,
            sprintf('%s is not taking requests at the moment. Choose another carrier.', $company->name),
        );

        return $company;
    }

    /** @return Collection<string, Company> */
    private function discoverable(?float $lat, ?float $lng, float $radius, ?string $search): Collection
    {
        $term = trim((string) $search);

        return Company::query()
            ->discoverable()
            ->when($lat !== null && $lng !== null, function (Builder $query) use ($lat, $lng, $radius): void {
                $box = Geo::boundingBox((float) $lat, (float) $lng, $radius);

                $query->whereBetween('latitude', [$box['min_lat'], $box['max_lat']])
                    ->whereBetween('longitude', [$box['min_lng'], $box['max_lng']]);
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('name', 'like', '%'.$term.'%')
                        ->orWhere('address', 'like', '%'.$term.'%');
                });
            })
            ->orderBy('name')
            ->limit(self::MAX_RESULTS * 4)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  string[]  $ids
     * @param  string[]  $except
     * @return Collection<string, Company>
     */
    private function linked(array $ids, array $except): Collection
    {
        $wanted = array_values(array_diff($ids, $except));

        if ($wanted === []) {
            return new Collection;
        }

        return Company::query()
            ->whereIn('id', $wanted)
            ->where('status', StatusValue::Active->value)
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<string, Company>  $companies
     * @param  string[]  $linkedCompanyIds
     * @return Collection<int, CarrierListing>
     */
    private function listings(
        Collection $companies,
        ?float $lat,
        ?float $lng,
        array $linkedCompanyIds,
    ): Collection {
        $fleet = $this->fleet($companies->keys()->all());

        return $companies->map(fn (Company $company): CarrierListing => new CarrierListing(
            company: $company,
            distance_km: $lat === null || $lng === null
                ? null
                : $this->round($company->distanceKmFrom((float) $lat, (float) $lng)),
            vehicles_ready: (int) ($fleet[$company->id]['units'] ?? 0),
            capacity_kg: (int) ($fleet[$company->id]['capacity_kg'] ?? 0),
            linked: in_array($company->id, $linkedCompanyIds, true),
        ))->values();
    }

    /**
     * @param  string[]  $companyIds
     * @return array<string, array{units: int, capacity_kg: int}>
     */
    private function fleet(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        return Vehicle::acrossCompanies()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', [StatusValue::Active->value, StatusValue::Available->value])
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as units, coalesce(sum(capacity_kg), 0) as capacity_kg')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->company_id => [
                    'units' => (int) $row->units,
                    'capacity_kg' => (int) $row->capacity_kg,
                ],
            ])
            ->all();
    }

    private function round(?float $km): ?float
    {
        return $km === null ? null : round($km, 1);
    }
}
