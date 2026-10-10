<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class DeliverTripRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'receiver_name' => ['required', 'string', 'max:120'],
            'photo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp,heic,heif',
                'max:'.(int) config('cargo.pod.max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_name.required' => 'Enter the name of whoever received the load before marking this delivered.',
            'photo.image' => 'Proof of delivery has to be a photograph.',
            'photo.max' => 'That photograph is too large. Take it at a lower resolution and try again.',
        ];
    }

    public function toProof(): ProofData
    {
        return ProofData::fromRequest($this->validated(), $this->file('photo'));
    }
}
