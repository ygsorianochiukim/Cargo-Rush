<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\DTO\PricingZoneData;
use App\Domain\Pricing\Models\DieselPrice;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Repositories\DieselPriceRepository;
use App\Domain\Pricing\Repositories\PricingZoneRepository;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PricingZoneService extends CrudService
{
    public function __construct(
        private readonly PricingZoneRepository $zones,
        private readonly DieselPriceRepository $diesel,
        private readonly FuelIndex $fuel,
    ) {}

    protected function repository(): Repository
    {
        return $this->zones;
    }

    /** @return Collection<int, PricingZone> */
    public function list(array $filters = [])
    {
        return $this->zones->all($filters);
    }

    public function create(Data $data): Model
    {
        return DB::transaction(function () use ($data): PricingZone {
            /** @var PricingZone $zone */
            $zone = $this->zones->create($data);

            if ($data instanceof PricingZoneData && $data->brackets !== null) {
                $this->syncBrackets($zone, $data->brackets);
            }

            return $zone->load('brackets');
        });
    }

    public function update(Model $model, Data $data): Model
    {
        return DB::transaction(function () use ($model, $data): PricingZone {
            /** @var PricingZone $zone */
            $zone = $this->zones->update($model, $data);

            if ($data instanceof PricingZoneData && $data->brackets !== null) {
                $this->syncBrackets($zone, $data->brackets);
            }

            return $zone->load('brackets');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createTruckCategory(array $attributes): TruckCategory
    {
        $name = (string) ($attributes['name'] ?? '');

        return TruckCategory::create([
            ...$attributes,
            'key' => $this->uniqueCategoryKey(Str::slug($name) ?: 'category'),
        ])->refresh();
    }

    public function deleteTruckCategory(TruckCategory $category): bool
    {
        $inUse = $category->brackets()->exists() || $category->vehicles()->exists();

        if ($inUse) {
            $category->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $category->delete();

        return true;
    }

    private function uniqueCategoryKey(string $stem): string
    {
        $key = $stem;
        $suffix = 1;

        while (TruckCategory::withTrashed()->where('key', $key)->exists()) {
            $key = $stem.'-'.(++$suffix);
        }

        return $key;
    }

    /**
     * @param  array<int, array<string, mixed>>  $brackets
     * @return \Illuminate\Support\Collection<int, PricingBracket>
     */
    public function saveCard(array $brackets): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($brackets): \Illuminate\Support\Collection {
            $kept = [];

            foreach (array_values($brackets) as $position => $bracket) {
                $attributes = $this->bracketAttributes($bracket, $position);

                $id = $bracket['id'] ?? null;

                $existing = $id === null
                    ? null
                    : PricingBracket::query()->whereNull('zone_id')->whereKey($id)->first();

                $row = $existing !== null
                    ? tap($existing)->update($attributes)
                    : PricingBracket::create([...$attributes, 'zone_id' => null]);

                $kept[] = $row->id;
            }

            PricingBracket::query()
                ->whereNull('zone_id')
                ->whereNotIn('id', $kept === [] ? ['-'] : $kept)
                ->delete();

            return $this->card();
        });
    }

    /** @return \Illuminate\Support\Collection<int, PricingBracket> */
    public function card(): \Illuminate\Support\Collection
    {
        return PricingBracket::query()
            ->with('truckCategory')
            ->whereNull('zone_id')
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $bracket
     * @return array<string, mixed>
     */
    private function bracketAttributes(array $bracket, int $position): array
    {
        return [
            'label' => (string) ($bracket['label'] ?? ''),
            'min_km' => (int) ($bracket['min_km'] ?? 0),
            'max_km' => isset($bracket['max_km']) && $bracket['max_km'] !== null
                ? (int) $bracket['max_km']
                : null,
            'base_cents' => (int) ($bracket['base_cents'] ?? 0),
            'per_km_cents' => (int) ($bracket['per_km_cents'] ?? 0),
            'per_kg_cents' => (int) ($bracket['per_kg_cents'] ?? 0),
            'minimum_cents' => (int) ($bracket['minimum_cents'] ?? 0),
            'diesel_step_cents' => (int) ($bracket['diesel_step_cents'] ?? 0),
            'truck_category_id' => $bracket['truck_category_id'] ?? null,
            'position' => $position,
        ];
    }

    /** @param array<int, array<string, mixed>> $brackets */
    private function syncBrackets(PricingZone $zone, array $brackets): void
    {
        $kept = [];

        foreach (array_values($brackets) as $position => $bracket) {
            $attributes = [
                'label' => (string) ($bracket['label'] ?? ''),
                'min_km' => null,
                'max_km' => null,
                'base_cents' => (int) ($bracket['base_cents'] ?? 0),
                'per_km_cents' => (int) ($bracket['per_km_cents'] ?? 0),
                'per_kg_cents' => (int) ($bracket['per_kg_cents'] ?? 0),
                'minimum_cents' => (int) ($bracket['minimum_cents'] ?? 0),
                'diesel_step_cents' => (int) ($bracket['diesel_step_cents'] ?? 0),
                'truck_category_id' => $bracket['truck_category_id'] ?? null,
                'position' => $position,
            ];

            $id = $bracket['id'] ?? null;

            $existing = $id === null
                ? null
                : $zone->brackets()->whereKey($id)->first();

            $row = $existing !== null
                ? tap($existing)->update($attributes)
                : $zone->brackets()->create($attributes);

            $kept[] = $row->id;
        }

        $zone->brackets()->whereNotIn('id', $kept === [] ? ['-'] : $kept)->delete();

        $zone->unsetRelation('brackets');
    }

    public function delete(Model $model): void
    {
        $this->zones->delete($model);
    }

    /** @return array<string, mixed> */
    public function dieselState(): array
    {
        $current = $this->diesel->current();
        $adjustmentBp = $this->fuel->adjustmentBp();

        return [
            'current' => $current === null ? null : [
                'effective_on' => $current->effective_on?->toDateString(),
                'price_per_litre_cents' => $current->price_per_litre_cents,
                'source' => $current->source,
            ],
            'baseline_cents' => $this->fuel->baselineFor(),
            'band_floor_cents' => (int) config('cargo.diesel.band_floor_cents'),
            'sensitivity' => (float) config('cargo.diesel.sensitivity'),
            'cap_bp' => (int) config('cargo.diesel.cap_bp'),
            'adjustment_bp' => $adjustmentBp,
            'capped' => abs($adjustmentBp) >= abs((int) config('cargo.diesel.cap_bp')),
            'currency' => (string) config('cargo.currency', 'PHP'),
        ];
    }

    public function recordDiesel(array $validated, ?int $userId)
    {
        $date = isset($validated['effective_on'])
            ? Carbon::parse($validated['effective_on'])->toDateString()
            : Carbon::now()->toDateString();

        return $this->diesel->record(
            date: $date,
            centsPerLitre: (int) $validated['price_per_litre_cents'],
            source: $validated['source'] ?? null,
            userId: $userId,
        );
    }

    /** @return Collection<int, DieselPrice> */
    public function dieselHistory(int $days = 60)
    {
        return $this->diesel->history($days);
    }

    public function brackets(): array
    {
        return $this->zones->active()
            ->flatMap(fn (PricingZone $zone) => $zone->brackets->map(
                fn (PricingBracket $bracket): array => [
                    'zone' => $zone->code.' — '.$zone->name,
                    'band' => $zone->band(),
                    'bracket' => $bracket->label,
                    'base_cents' => $bracket->base_cents,
                    'diesel_step_cents' => $bracket->diesel_step_cents,
                ],
            ))
            ->all();
    }
}
