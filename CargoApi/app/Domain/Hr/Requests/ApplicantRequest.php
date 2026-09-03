<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Hr\DTO\ApplicantData;
use App\Domain\Shared\Enums\ApplicantStage;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ApplicantRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'first_name' => [$required, 'string', 'max:60'],
            'last_name' => [$required, 'string', 'max:60'],
            'position_applied' => [$required, 'string', 'max:60'],
            'contact' => [$required, 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:60'],
            // Defaults to today in the service: most applications are entered
            // on the day they arrive.
            'applied_on' => ['sometimes', 'date'],
            'stage' => ['sometimes', Rule::in(ApplicantStage::values())],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:'.(int) config('cargo.hr.photo_max_kb')],
            // A CV is a PDF or a scan of one, so both are accepted.
            'resume' => [
                'nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png',
                'max:'.(int) config('cargo.hr.resume_max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'resume.mimes' => 'A CV as a PDF, a Word document or a photograph of one.',
            'rating.max' => 'Rate an applicant out of five.',
        ];
    }

    public function toData(): ApplicantData
    {
        return ApplicantData::fromArray(
            collect($this->validated())->except(['photo', 'resume'])->all()
        );
    }

    public function photo(): ?UploadedFile
    {
        $photo = $this->file('photo');

        return $photo instanceof UploadedFile ? $photo : null;
    }

    public function resume(): ?UploadedFile
    {
        $resume = $this->file('resume');

        return $resume instanceof UploadedFile ? $resume : null;
    }
}
