<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trucker\Services\TruckPhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A partner's truck, added or corrected.
 *
 * Used by the partner for their own record and by the desk for anybody's. The
 * fields are identical either way — a plate is a plate — and who may call it is
 * the route's business.
 */
class TruckerVehicleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Once per company, as the index says — asked here first so a
             * plate already on the books is a sentence, not a 500. Deleted
             * rows count, because the index counts them.
             */
            'plate' => [
                $this->requiredOnCreate(), 'string', 'max:20',
                Rule::unique('trucker_vehicles', 'plate')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($this->route('vehicleId')),
            ],
            'model' => [$this->requiredOnCreate(), 'string', 'max:120'],
            'capacity_kg' => [$this->requiredOnCreate(), 'integer', 'min:100', 'max:100000'],
            'truck_category_id' => ['nullable', 'string', 'max:26'],
            /**
             * Only the two idle states. A truck's `status` here says whether it
             * is running or in the shop, and there is no third thing for a
             * partner to set it to — a unit under a load is described by the
             * trip it is under, not by a column somebody can contradict.
             */
            'status' => ['sometimes', 'string', 'in:'.implode(',', [
                StatusValue::Available->value,
                StatusValue::Maintenance->value,
            ])],

            ...$this->photoRules(),
        ];
    }

    /**
     * The photographs, on a new truck.
     *
     * Required from a trucker adding their own, because they are what the
     * office checks the truck from. Optional from the desk: a truck the office
     * adds is one somebody there has already seen. Not taken on a correction —
     * re-sending photographs is its own call (`TruckerVehiclePhotosRequest`),
     * because it sends the truck back for checking.
     *
     * @return array<string, array<int, string>>
     */
    private function photoRules(): array
    {
        if (! $this->creating()) {
            return [];
        }

        $fromTrucker = $this->route('trucker') === null;

        return collect(TruckPhotoStore::SLOTS)
            ->mapWithKeys(static fn (bool $required, string $slot): array => [
                "photo_{$slot}" => [
                    $required && $fromTrucker ? 'required' : 'nullable',
                    ...self::imageRules(),
                ],
            ])
            ->all();
    }

    /** @return list<string> */
    public static function imageRules(): array
    {
        return ['image', 'mimes:jpeg,jpg,png,webp,heic,heif', 'max:'.(int) config('cargo.trucks.max_kb')];
    }

    /** @return array<string, string> */
    public static function photoMessages(): array
    {
        return [
            'photo_front.required' => 'Take a photo of the front of the truck.',
            'photo_left.required' => 'Take a photo of the left side of the truck.',
            'photo_right.required' => 'Take a photo of the right side of the truck.',
            'photo_back.required' => 'Take a photo of the back of the truck.',
            'photo_plate.required' => 'Take a photo of the plate number.',
            'photo_*.image' => 'That has to be a photograph.',
            'photo_*.max' => 'That photograph is too large. Take it at a lower resolution and try again.',
        ];
    }

    public function messages(): array
    {
        return [
            'capacity_kg.required' => 'How much can it carry? It decides which jobs you are offered.',
            'plate.unique' => 'A truck with that plate is already registered.',
            ...self::photoMessages(),
        ];
    }

    /**
     * The uploaded photographs, by slot.
     *
     * @return array<string, UploadedFile>
     */
    public function photos(): array
    {
        return collect(array_keys(TruckPhotoStore::SLOTS))
            ->mapWithKeys(fn (string $slot): array => [$slot => $this->file("photo_{$slot}")])
            ->filter()
            ->all();
    }

    /**
     * The truck's own fields, without the files.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return collect($this->validated())
            ->reject(static fn ($value, string $key): bool => str_starts_with($key, 'photo_'))
            ->all();
    }
}
