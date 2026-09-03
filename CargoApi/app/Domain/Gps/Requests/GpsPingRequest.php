<?php

declare(strict_types=1);

namespace App\Domain\Gps\Requests;

use App\Domain\Gps\DTO\GpsPingData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class GpsPingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'string', 'exists:trips,id'],
            'location' => ['required', 'string', 'max:160'],
            'speed_kph' => ['required', 'integer', 'min:0', 'max:200'],
            'heading' => ['sometimes', 'string', 'max:16'],
            'progress_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'distance_done_m' => ['sometimes', 'integer', 'min:0'],
            // The handset stamps this, because it may have been offline when
            // the reading was taken and is only posting it now.
            'recorded_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function toData(): GpsPingData
    {
        return GpsPingData::fromArray($this->validated());
    }
}
