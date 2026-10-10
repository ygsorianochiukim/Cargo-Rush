<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTO;

use Illuminate\Http\UploadedFile;

final class ProofData
{
    public function __construct(
        public readonly string $receiver_name,
        public readonly ?UploadedFile $photo = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated, ?UploadedFile $photo = null): self
    {
        return new self(
            receiver_name: trim((string) $validated['receiver_name']),
            photo: $photo,
        );
    }
}
