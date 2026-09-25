<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\RateBook;
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
            'payroll_deduct_on_detail' => $this->payroll_deduct_on?->detail(
                $this->payrollCalendar()->runsPerMonth(),
            ),

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

            /**
             * Days between a cutoff and the money going out.
             *
             * The raw column, which may be null — "the install default" — for a
             * settings form to edit. What is actually in force rides along
             * inside `payroll_calendar`, with the release day worked out for
             * each of the month's periods.
             */
            'payroll_release_lag_days' => $this->payroll_release_lag_days,
            'payroll_calendar' => $this->payrollCalendar()->toArray(),

            /**
             * The rates this firm works to, what the install would say
             * instead, and which of them the firm has actually chosen.
             *
             * Three blocks rather than one, because a settings form needs all
             * three and can derive none of them. `rates` is what is in force
             * and is always a complete set of concrete numbers — a screen
             * drawing a tariff never has to decide what a null means.
             * `rate_defaults` is the install's answer, shown beside the form so
             * an office can see what it is departing from. `rate_overrides` is
             * the raw columns, and it is the only way to tell "₱35/km because
             * we chose ₱35" from "₱35/km because nobody has chosen anything" —
             * identical in a number field, different the day the install
             * default moves.
             *
             * Only on the company's own endpoints, where the caller holds
             * `company.manage`. What a firm charges is not something every
             * signed-in account needs, and `MeResource` does not carry it.
             */
            'rates' => $this->rates()->inForce(),
            'rate_defaults' => $this->rates()->defaults(),
            'rate_overrides' => $this->rates()->overrides(),

        ];
    }

    /**
     * This company's rate book.
     *
     * Bound to the row being serialised rather than to the tenant in force.
     * They are the same company on every route that reaches here — there is no
     * id on any of them — and a resource that quietly relied on that would be
     * wrong the first time one is rendered from a console command.
     */
    private function rates(): RateBook
    {
        return app(RateBook::class)->for($this->resource);
    }
}
