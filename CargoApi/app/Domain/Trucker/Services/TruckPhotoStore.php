<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TruckPhotoStore
{
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
