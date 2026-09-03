<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\DTO\PricingZoneData;
use App\Domain\Pricing\Models\DieselPrice;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\DieselPriceRepository;
use App\Domain\Pricing\Repositories\PricingZoneRepository;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
                'min_km' => (int) ($bracket['min_km'] ?? 0),
                'max_km' => isset($bracket['max_km']) && $bracket['max_km'] !== null
                    ? (int) $bracket['max_km']
                    : null,
                'base_cents' => (int) ($bracket['base_cents'] ?? 0),
                'per_km_cents' => (int) ($bracket['per_km_cents'] ?? 0),
                'per_kg_cents' => (int) ($bracket['per_kg_cents'] ?? 0),
                'minimum_cents' => (int) ($bracket['minimum_cents'] ?? 0),
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

    /** Every bracket on every active card, for a client that wants the lot. */
    public function brackets(): array
    {
        return $this->zones->active()
            ->flatMap(fn (PricingZone $zone) => $zone->brackets->map(
                fn (PricingBracket $bracket): array => [
                    'zone' => $zone->name,
                    'bracket' => $bracket->label,
                    'range' => $bracket->range(),
                    'base_cents' => $bracket->base_cents,
                ],
            ))
            ->all();
    }
}
