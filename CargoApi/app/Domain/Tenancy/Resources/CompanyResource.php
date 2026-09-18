<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\LogoStore;
use Illuminate\Http\Request;

/**
 * The company, as both clients see it.
 *
 * Only what a shell needs to say whose system this is: the name for the header,
 * the mark beside it, and the code for the rare occasion somebody has to quote
 * it to support. The status stays server-side — it has already been acted on by
 * the time a client could read it, because a suspended company cannot sign in.
 *
 * The contact details ride along only on the company's own endpoints, where the
 * caller holds `company.manage`. They are not in `MeResource`, which every
 * signed-in account reads: a driver's handset has no use for the billing
 * contact, and shipping it to one is a detail leaked for no reason.
 *
 * @mixin Company
 */
class CompanyResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,

            // Resolved on read, never stored: moving the install must not
            // orphan every company's mark. Null means the client renders the
            // company's initials, exactly as the user chip does for an account
            // with no avatar.
            'logo_url' => app(LogoStore::class)->url($this->logo_path),

            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,

            // Where the yard is. Read by the office's own settings screen so it
            // can show the pin it has, and by nothing else — a shipper reads
            // these off `CarrierResource`, which is a different and much
            // shorter record.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // Whether this company appears on the carrier list at all. Derived
            // from the pin, said plainly, because "you are not listed" is not
            // something an office should have to infer from two empty fields.
            'discoverable' => $this->isPinned(),

            /**
             * Which cutoff the monthly contributions come off.
             *
             * A payroll setting on the company record, because it is the firm's
             * policy rather than a government rate — see `DeductionSchedule`.
             * The label and the sentence come with it so the screen that offers
             * the choice does not keep its own copy of what each option means.
             */
            'payroll_deduct_on' => $this->payroll_deduct_on?->value,
            'payroll_deduct_on_label' => $this->payroll_deduct_on?->label(),
            'payroll_deduct_on_detail' => $this->payroll_deduct_on?->detail(),

            /**
             * When this firm's pay periods close.
             *
             * The raw column and the calendar it produces, both. The column is
             * what a settings form edits and may be null — "the install
             * default" — while `payroll_calendar` is always the calendar
             * actually in force, with the periods of the current month worked
             * out. A screen showing a firm its own cutoff needs the second: the
             * days on their own are a pair of numbers, and "26 Aug–10 Sep" is
             * the thing an office can check.
             */
            'payroll_cutoff_days' => $this->payroll_cutoff_days,
            'payroll_calendar' => $this->payrollCalendar()->toArray(),

        ];
    }
}
