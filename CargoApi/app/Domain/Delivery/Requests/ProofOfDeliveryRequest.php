<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Requests;

use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * Attaching proof to a delivery log that already exists.
 *
 * The same two fields as the driver's hand-off, for the same reasons: the
 * reference is assigned by the model, the signature is a typed name, and the
 * photograph is optional because the door is not a place where a failed upload
 * should be a dead end.
 *
 * This route exists separately from the hand-off because proof sometimes
 * arrives late — a photograph that would not send from the gate, or a log the
 * office closed on the driver's behalf and is now completing.
 */
class ProofOfDeliveryRequest extends ApiFormRequest
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
            'receiver_name.required' => 'Enter the name of whoever received the load.',
            'photo.image' => 'Proof of delivery has to be a photograph.',
            'photo.max' => 'That photograph is too large. Take it at a lower resolution and try again.',
        ];
    }

    public function toProof(): ProofData
    {
        return ProofData::fromRequest($this->validated(), $this->file('photo'));
    }
}
