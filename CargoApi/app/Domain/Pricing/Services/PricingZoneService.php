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

/**
 * The rate card editor, server side.
 *
 * The one thing here that is not plain CRUD is the card itself. A zone and its
 * brackets are edited as a single thing — somebody adds a bracket, corrects the
 * rate on another, deletes a third and presses save once — so the payload
 * carries the whole card and this reconciles it against what is stored.
 *
 * Replace-by-delete-and-reinsert would have been three lines shorter and wrong:
 * a bracket's id is on every trip it ever priced (`trips.pricing_bracket_id`),
 * and recreating the rows would orphan that trace on every save. So rows are
 * matched by id and updated in place; only a bracket genuinely dropped from the
 * card is deleted, and the trips that used it keep a null rather than a
 * pointer to a bracket holding somebody else's rates.
 */
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

            // Absent means "not part of this edit". A PATCH renaming a zone
            // must not be read as an instruction to empty its card.
            if ($data instanceof PricingZoneData && $data->brackets !== null) {
                $this->syncBrackets($zone, $data->brackets);
            }

            return $zone->load('brackets');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTruckCategory(array $attributes): TruckCategory
    {
        $name = (string) ($attributes['name'] ?? '');

        return TruckCategory::create([
            ...$attributes,
            // The handle a seeder and an import find it by. Derived rather than
            // asked for: it is an identifier, and asking an office to invent
            // one is asking a question they have no basis to answer.
            'key' => $this->uniqueCategoryKey(Str::slug($name) ?: 'category'),
        ])->refresh();
    }

    /**
     * Remove a category, or retire it where anything depends on it.
     *
     * Deleting one that a rate-card line names would null that line's category
     * and silently widen it to "any truck" — changing what a freezer run is
     * quoted, which nobody notices until an invoice is short. Retiring stops it
     * being offered and leaves every price exactly as it was.
     *
     * @return bool true when it was deleted, false when it was retired instead
     */
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

    /** `freezer`, `freezer-2` — unique within the company. */
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
     * The firm's plain distance card — the lines that belong to no zone.
     *
     * The rate card used to be reachable only through a zone, which made the
     * place compulsory: a haulier whose price is simply "450 km is ₱5,000" had
     * to invent a zone per town before it could say so. These lines apply
     * wherever nothing more specific does, and for most firms they are the
     * whole card.
     *
     * Reconciled by id exactly as a zone's card is, and for the same reason: a
     * bracket's id is on every trip it ever priced, so rows are matched and
     * updated rather than dropped and recreated.
     *
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

                // Scoped to the zoneless card: an id belonging to a zone's card
                // is either a client bug or somebody dragging a row between
                // cards, and quietly reparenting it would rewrite what a zone
                // quotes from.
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

    /**
     * @return \Illuminate\Support\Collection<int, PricingBracket>
     */
    public function card(): \Illuminate\Support\Collection
    {
        return PricingBracket::query()
            ->with('truckCategory')
            ->whereNull('zone_id')
            ->orderBy('position')
            ->get();
    }

    /**
     * One bracket's columns, from whatever the form sent.
     *
     * Shared by the zone card and the plain one, so a line means the same
     * thing on both — two copies of this is how a `per_kg` rate ends up
     * honoured in one editor and ignored in the other.
     *
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

    /**
     * Reconcile a zone's brackets against the card as sent.
     *
     * `position` is taken from the order of the array rather than from a field,
     * because the editor is a list somebody reorders by dragging — the order
     * they see is the order they sent, and asking a client to also maintain an
     * index is asking for the two to drift.
     *
     * @param  array<int, array<string, mixed>>  $brackets
     */
    private function syncBrackets(PricingZone $zone, array $brackets): void
    {
        $kept = [];

        foreach (array_values($brackets) as $position => $bracket) {
            $attributes = [
                'label' => (string) ($bracket['label'] ?? ''),
                /**
                 * Null, always, on a line inside a zone.
                 *
                 * The band belongs to the zone. Storing a copy on each of its
                 * lines would give a 1–40 km zone three lines that each think
                 * they know what 1–40 means, and correcting the band in the
                 * editor would then leave them all quoting the old one. A
                 * banded line reads the band off its zone — `covers()` does —
                 * so there is exactly one place it is written down.
                 */
                'min_km' => null,
                'max_km' => null,
                'base_cents' => (int) ($bracket['base_cents'] ?? 0),
                'per_km_cents' => (int) ($bracket['per_km_cents'] ?? 0),
                'per_kg_cents' => (int) ($bracket['per_kg_cents'] ?? 0),
                'minimum_cents' => (int) ($bracket['minimum_cents'] ?? 0),
                // The printed table's rightmost column: pesos per ₱1/L above
                // the baseline. Zero leaves the line on the percentage model.
                'diesel_step_cents' => (int) ($bracket['diesel_step_cents'] ?? 0),
                // Null means "the fleet", which is what the table's own
                // `Current Price` column is.
                'truck_category_id' => $bracket['truck_category_id'] ?? null,
                'position' => $position,
            ];

            $id = $bracket['id'] ?? null;

            // Scoped to this zone: an id from another zone's card is either a
            // client bug or somebody moving rows between zones, and quietly
            // reparenting a bracket would rewrite the rates a different zone
            // quotes from.
            $existing = $id === null
                ? null
                : $zone->brackets()->whereKey($id)->first();

            $row = $existing !== null
                ? tap($existing)->update($attributes)
                : $zone->brackets()->create($attributes);

            $kept[] = $row->id;
        }

        // Whatever the card no longer mentions is gone. `trips` points at these
        // with `nullOnDelete`, so a deleted bracket costs a past trip its
        // bracket trace and nothing else — no price is touched.
        $zone->brackets()->whereNotIn('id', $kept === [] ? ['-'] : $kept)->delete();

        $zone->unsetRelation('brackets');
    }

    public function delete(Model $model): void
    {
        // Soft delete, so the zone stops pricing new work but the trips that
        // name it can still say where their figure came from.
        $this->zones->delete($model);
    }

    /* ------------------------------------------------------------- Diesel */

    /**
     * The pump price panel: what it is now, what the card assumes, and what
     * that difference is doing to every quote.
     *
     * @return array<string, mixed>
     */
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
            // The bottom of the band the printed figures cover. Display only —
            // only the top of the band is arithmetic. See `config/cargo.php`.
            'band_floor_cents' => (int) config('cargo.diesel.band_floor_cents'),
            'sensitivity' => (float) config('cargo.diesel.sensitivity'),
            'cap_bp' => (int) config('cargo.diesel.cap_bp'),
            'adjustment_bp' => $adjustmentBp,
            // Whether the guard rail is what is holding the figure back. If it
            // is, the card needs redrawing rather than the surcharge stretching.
            'capped' => abs($adjustmentBp) >= abs((int) config('cargo.diesel.cap_bp')),
            'currency' => (string) config('cargo.tariff.currency'),
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

    /** Every rate line on every active band, for a client that wants the lot. */
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
