<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

use Illuminate\Http\UploadedFile;

/**
 * What a driver actually has at the door.
 *
 * The hand-off used to ask for a `pod_ref` — a number the driver had no source
 * for and therefore invented. What they genuinely have is a photograph of the
 * load where it was left, and the name of the person who took it. The
 * reference is the system's to assign (`DeliveryLog::nextPodRef()`), so it is
 * absent here on purpose.
 *
 * The signature is a typed name for now, deliberately: a drawn signature needs
 * a canvas, a stroke format and somewhere to keep it, and a typed name against
 * a photograph is already better evidence than an invented reference. When the
 * canvas arrives it replaces this field's *capture*, not its meaning.
 *
 * Not a `Shared\DTO\Data`, and that is not an oversight: `Data` exists to tell
 * "not sent" from "sent as null" so a PATCH can clear a column. This is not a
 * patch of a record — it is one event, captured once, and both of its fields
 * are required to mean anything.
 */
final class ProofData
{
    public function __construct(
        /** The name signed for the load. */
        public readonly string $receiver_name,
        /** The photograph, when the handset had one to give. */
        public readonly ?UploadedFile $photo = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromRequest(array $validated, ?UploadedFile $photo = null): self
    {
        return new self(
            receiver_name: trim((string) $validated['receiver_name']),
            photo: $photo,
        );
    }
}
