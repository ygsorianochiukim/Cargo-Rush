<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where the photograph taken at the door is kept.
 *
 * Its own class rather than three lines inside a service, because two callers
 * need it — the driver's hand-off and the office attaching proof to a log
 * afterwards — and a file written to two different places by two code paths is
 * a file the delivery log cannot reliably find again.
 *
 * The disk and folder are configuration (`config/cargo.php`). Only the path is
 * stored; the URL is derived on read, so moving the install does not leave
 * every past delivery pointing at a host that no longer exists.
 */
class ProofStore
{
    /**
     * Keep the photograph and return its path, or null when there was none.
     *
     * A null upload is a real case and not a failure: signal at a warehouse
     * gate is what it is, and a delivery with a signed name but no photograph
     * is still a delivery. Refusing to close the run over it would leave a
     * driver standing at a door with no way forward.
     */
    public function store(?UploadedFile $photo): ?string
    {
        if ($photo === null) {
            return null;
        }

        return $photo->store(
            (string) config('cargo.pod.directory'),
            (string) config('cargo.pod.disk'),
        ) ?: null;
    }

    /** Read back what a stored path resolves to, for a client to fetch. */
    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return Storage::disk((string) config('cargo.pod.disk'))->url($path);
    }
}
