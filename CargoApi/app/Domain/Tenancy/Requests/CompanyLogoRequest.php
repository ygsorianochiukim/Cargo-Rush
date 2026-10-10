<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;

class CompanyLogoRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,gif,webp,bmp',
                'max:'.(int) config('cargo.company.logo_max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required' => 'Choose an image file to use as the logo.',
            'logo.image' => 'The logo has to be an image — a PNG, JPEG, GIF or WebP.',
            'logo.mimes' => 'That format is not supported. Use a PNG, JPEG, GIF or WebP.',
            'logo.max' => 'That file is too large. Anything up to a few megabytes is fine.',
        ];
    }

    public function logo(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('logo');

        return $file;
    }
}
