<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trucker\Services\TruckPhotoStore;
use Illuminate\Http\UploadedFile;

/**
 * Re-sending some of a truck's photographs — usually because the office turned
 * the truck down over one of them.
 *
 * Any subset of the six, at least one. Sending them puts the truck back in the
 * office's queue, whatever it was before.
 */
class TruckerVehiclePhotosRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $slots = array_map(static fn (string $slot): string => "photo_{$slot}", array_keys(TruckPhotoStore::SLOTS));

        return collect($slots)
            ->mapWithKeys(static fn (string $field): array => [
                $field => ['required_without_all:'.implode(',', array_diff($slots, [$field])), 'nullable', ...TruckerVehicleRequest::imageRules()],
            ])
            ->all();
    }

    public function messages(): array
    {
        return [
            'photo_*.required_without_all' => 'Choose at least one photo to send.',
            ...TruckerVehicleRequest::photoMessages(),
        ];
    }

    /** @return array<string, UploadedFile> */
    public function photos(): array
    {
        return collect(array_keys(TruckPhotoStore::SLOTS))
            ->mapWithKeys(fn (string $slot): array => [$slot => $this->file("photo_{$slot}")])
            ->filter()
            ->all();
    }
}
