<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogoStore
{
    /** @throws ValidationException */
    public function store(UploadedFile $file): string
    {
        $source = $this->decode($file);

        try {
            $square = $this->square($source, $this->size());
        } finally {
            imagedestroy($source);
        }

        try {
            $path = sprintf('%s/%s.png', $this->directory(), Str::ulid());

            Storage::disk($this->disk())->put($path, $this->encode($square));
        } finally {
            imagedestroy($square);
        }

        return $path;
    }

    public function replace(?string $existing, UploadedFile $file): string
    {
        $path = $this->store($file);

        if ($existing !== null) {
            $this->remove($existing);
        }

        return $path;
    }

    public function remove(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return Storage::disk($this->disk())->url($path);
    }

    /** @throws ValidationException */
    private function decode(UploadedFile $file): GdImage
    {
        $bytes = @file_get_contents($file->getRealPath());

        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        if ($image === false) {
            throw ValidationException::withMessages([
                'logo' => ['That file could not be read as an image. Try a PNG, JPEG, GIF or WebP.'],
            ]);
        }

        return $image;
    }

    private function square(GdImage $source, int $size): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor($size, $size);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle(
            $canvas, 0, 0, $size - 1, $size - 1,
            (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127),
        );

        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $size, $size,
            $side, $side,
        );

        return $canvas;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    private function size(): int
    {
        return max(16, (int) config('cargo.company.logo_px', 64));
    }

    private function disk(): string
    {
        return (string) config('cargo.company.disk');
    }

    private function directory(): string
    {
        return trim((string) config('cargo.company.directory'), '/');
    }
}
