<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * Which modules an account sees.
 *
 * `present` rather than `required`, because an empty array is the meaningful
 * instruction that clears the assignment and restores the default — everything
 * the role allows. `required` rejects `[]`, which would make switching an
 * employee back off a custom menu impossible.
 */
class ModuleAssignmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'modules' => ['present', 'array', 'max:60'],
            // Validated against the nav table, so a typo is a 422 rather than a
            // row nobody will ever match. Whether the *role* permits each one
            // is decided by the service, which reports back what it dropped.
            'modules.*' => ['string', 'exists:nav_items,key'],
        ];
    }

    /** @return string[] */
    public function modules(): array
    {
        return array_values(array_filter((array) $this->input('modules', [])));
    }
}
