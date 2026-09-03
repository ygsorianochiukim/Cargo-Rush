<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * What the driver sends to hand a run over — the proof, not the status.
 *
 * The status is not a field. The only transition this endpoint performs is
 * in transit → delivered, so letting the handset name the target would be a
 * value the API has to validate against the one it was always going to use.
 *
 * **`pod_ref` is gone, and its absence is the point.** It used to be required
 * here, which meant every delivery needed a reference the driver had no source
 * for — so it was invented in the cab, and two runs could carry the same one.
 * The reference is now assigned by `DeliveryLog` the way a trip's `CR-` number
 * is assigned by `Trip`. A `pod_ref` sent to this endpoint is not in the rules
 * and is therefore stripped, not honoured.
 *
 * What is asked for instead is what the driver actually has at the door:
 *
 *  - **`receiver_name`** — required. The signature, typed. It is what makes
 *    the hand-off attributable to a person, which is the job the invented
 *    reference was pretending to do.
 *  - **`photo`** — optional. The load where it was left. Optional because
 *    signal and camera permissions at a warehouse gate are what they are, and
 *    refusing to close a run over a failed upload leaves a driver stuck at a
 *    door with nothing they can do.
 */
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

    /**
     * The proof, as one value.
     *
     * The file does not travel in `validated()` in any shape a service can
     * use, so it is picked off the request here — the one place that already
     * knows the field exists.
     */
    public function toProof(): ProofData
    {
        return ProofData::fromRequest($this->validated(), $this->file('photo'));
    }
}
