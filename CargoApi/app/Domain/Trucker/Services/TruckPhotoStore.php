<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where a trucker's truck photographs are kept.
 *
 * The same shape as `PhotoStore` and `ProofStore`: only the path goes on the
 * row and the URL is derived on read, so moving the install does not leave
 * every truck pointing at a host that no longer exists.
 */
class TruckPhotoStore
{
    /**
     * The photographs a truck is checked from, as `slot => required`.
     *
     * The order is the order the app asks for them in and the office reads
     * them in: walk round the truck, then the plate, then under the bonnet.
     */
    public const SLOTS = [
        'front' => true,
        'left' => true,
        'right' => true,
        'back' => true,
        'plate' => true,
        'engine' => false,
    ];

    public static function column(string $slot): string
    {
        return "photo_{$slot}_path";
    }

    public function store(UploadedFile $file, string $truckerId): ?string
    {
        $directory = trim((string) config('cargo.trucks.directory'), '/');

        return $file->store("$directory/$truckerId", (string) config('cargo.trucks.disk')) ?: null;
    }

    /** Replace a stored photograph, removing the one it supersedes. */
    public function replace(?string $existing, UploadedFile $file, string $truckerId): ?string
    {
        $path = $this->store($file, $truckerId);

        if ($path !== null && $existing !== null) {
            Storage::disk((string) config('cargo.trucks.disk'))->delete($existing);
        }

        return $path ?? $existing;
    }

    public function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk((string) config('cargo.trucks.disk'))->url($path);
    }
}
