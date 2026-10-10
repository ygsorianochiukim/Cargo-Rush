<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProofStore
{
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

    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return Storage::disk((string) config('cargo.pod.disk'))->url($path);
    }
}
