<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Position
 */
class PositionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,

            /**
             * Whether registering somebody into this job also asks for a
             * licence, and opens them a `drivers` record.
             *
             * Its own column on the position now rather than something a client
             * infers from the role the job used to suggest. Two clients each
             * deciding what counts as a driving job is two places for the
             * answer to drift from the one the API will accept.
             */
            'drives' => (bool) $this->drives,

            /**
             * The rate card — one basis, three figures.
             *
             * A **default at the moment of hire**: hiring into this job opens a
             * contract and copies the tier's figure onto it. Payroll reads the
             * contract, so editing a position changes what the next hire is
             * offered and nothing about anybody already on it.
             *
             * `has_rate_card` is sent rather than left to a client comparing
             * figures to zero, because zero and "nobody has said" look
             * identical from the outside and only one of them should overwrite
             * a form.
             */
            'pay_basis' => $this->pay_basis?->value,
            'pay_basis_label' => $this->pay_basis?->label(),
            'pay_basis_unit' => $this->pay_basis?->unit(),
            'trainee_amount_cents' => (int) $this->trainee_amount_cents,
            'probationary_amount_cents' => (int) $this->probationary_amount_cents,
            'regular_amount_cents' => (int) $this->regular_amount_cents,
            'has_rate_card' => $this->hasRateCard(),

            /**
             * What this job comes to **on one payslip**, in a sentence.
             *
             * The figure an office actually wants to see and the one they
             * cannot work out from the form: ₱15,000 a month is not ₱15,000 a
             * payslip, it is ₱7,500 twice — and whether it is twice depends on
             * a cutoff setting on a different screen.
             *
             * Composed server-side because that is where the calendar lives. A
             * client dividing by two would be a second implementation of the
             * firm's pay schedule, and the first thing it would get wrong is
             * the office that pays monthly.
             */
            'pay_summary' => $this->paySummary(),

            'position' => $this->position,
            'status' => $this->status->value,
            'employee_count' => $this->whenCounted('employees'),

            ...$this->stamps(),
        ];
    }
}
