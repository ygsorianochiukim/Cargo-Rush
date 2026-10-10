<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoStore
{
    public function store(?UploadedFile $file, ?string $folder = null): ?string
    {
        if ($file === null) {
            return null;
        }

        $directory = trim((string) config('cargo.hr.directory'), '/');

        return $file->store(
            $folder === null ? $directory : "$directory/$folder",
            (string) config('cargo.hr.disk'),
        ) ?: null;
    }

    public function replace(?string $existing, ?UploadedFile $file, ?string $folder = null): ?string
    {
        if ($file === null) {
            return $existing;
        }

        $path = $this->store($file, $folder);

        if ($path !== null && $existing !== null) {
            $this->remove($existing);
        }

        return $path ?? $existing;
    }

    public function remove(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk((string) config('cargo.hr.disk'))->delete($path);
    }

    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return Storage::disk((string) config('cargo.hr.disk'))->url($path);
    }
}
