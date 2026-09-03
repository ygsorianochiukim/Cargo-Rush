<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where a staff photograph or a CV is kept.
 *
 * The same shape as `ProofStore` and for the same reasons: three callers write
 * these files — registering an employee, editing one, and taking an
 * application — and a file written to two different places by two code paths is
 * a file the record cannot reliably find again. Only the path is stored and the
 * URL is derived on read, so moving the install does not leave every employee
 * photograph pointing at a host that no longer exists.
 */
class PhotoStore
{
    /**
     * Keep an upload and return its path, or null when there was none.
     *
     * A null upload is ordinary rather than a failure: a driver hired at the
     * gate has no photograph that afternoon, and refusing to register them over
     * it would mean the roster is missing the person who is already driving.
     */
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

    /**
     * Replace a stored file, removing the one it supersedes.
     *
     * The delete matters more here than for proof of delivery: a photograph is
     * re-taken whenever somebody does not like theirs, and without this every
     * attempt stays on disk forever with nothing pointing at it.
     */
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

    /** Read back what a stored path resolves to, for a client to fetch. */
    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return Storage::disk((string) config('cargo.hr.disk'))->url($path);
    }
}
