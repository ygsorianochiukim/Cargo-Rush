<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job the office keeps a list of, and the rate card that goes with it.
 *
 * Two things, and they belong together: the title somebody holds, and what that
 * title pays. One basis — monthly, daily or per trip — and one figure for each
 * of the three tiers somebody moves through.
 *
 * ## It does not say what anybody can open
 *
 * This used to carry a `default_role_id`, suggesting the access a new account in
 * this job would get. It is gone, and the separation is the point: what somebody
 * *is* and what they can *open* are different questions, and conflating them
 * means you cannot have two drivers where one also keeps the books — a person
 * who exists in every small fleet. Access is chosen on the account.
 *
 * The one thing that link genuinely decided survives as `drives`, because it was
 * never really about access: see the cast below.
 *
 * ## It does not say what anybody is paid, either
 *
 * The figures here are the **default at the moment of hire**. Hiring somebody
 * opens a contract and copies the tier's figure onto it; payroll reads the
 * contract and never looks here again.
 *
 * A live reference would have been fewer rows and a serious bug: raising the
 * driver rate for next year's hires would silently restate what every existing
 * driver is owed, including on a run somebody is halfway through checking.
 * Somebody negotiated up keeps their figure, and one person's pay is changed by
 * writing them a new contract.
 */
class Position extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'key', 'name', 'description', 'drives', 'position', 'status', 'pay_basis',
        'trainee_amount_cents', 'probationary_amount_cents', 'regular_amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => StatusValue::class,
            /**
             * Does somebody in this job need a `drivers` record?
             *
             * Which is the same question as *do they use the handset* — every
             * driver endpoint is scoped to a `drivers` row, so an account with
             * the driver's access and no such row signs in and finds five empty
             * screens.
             *
             * Its own column, because it is a fact about the job rather than
             * about access. It used to be inferred from the default role, which
             * read correctly right up to the firm that gave its mechanics a
             * driver login to move units around the yard.
             *
             * It covers the helper as well as the driver, and it should: a
             * helper is a driver record without the keys — they ride along,
             * they are named on the trip, and the roster keeps their licence.
             */
            'drives' => 'boolean',
            'pay_basis' => PayBasis::class,
            'trainee_amount_cents' => 'integer',
            'probationary_amount_cents' => 'integer',
            'regular_amount_cents' => 'integer',
        ];
    }

    /**
     * What this job pays somebody engaged on the given terms.
     *
     * Contractual and part-time read the regular figure rather than a fourth and
     * fifth column — see `EmploymentType::tier()`.
     */
    public function amountFor(EmploymentType $type): int
    {
        return (int) match ($type->tier()) {
            EmploymentType::Trainee => $this->trainee_amount_cents,
            EmploymentType::Probationary => $this->probationary_amount_cents,
            default => $this->regular_amount_cents,
        };
    }

    /**
     * The pay a hire on these terms starts on, or null where none is set.
     *
     * Null rather than a zeroed contract, and the difference matters: a position
     * nobody has priced must leave the hire form alone for the office to fill
     * in, not write ₱0.00 onto somebody's contract — the two look identical
     * afterwards and only one of them is an answer.
     *
     * @return array{pay_basis: string, amount_cents: int, tier: string}|null
     */
    public function startingPay(EmploymentType $type): ?array
    {
        $amount = $this->amountFor($type);

        if ($amount <= 0) {
            return null;
        }

        return [
            'pay_basis' => ($this->pay_basis ?? PayBasis::Monthly)->value,
            'amount_cents' => $amount,
            'tier' => $type->tier()->value,
        ];
    }

    /** Has somebody priced this job at all, on any tier? */
    public function hasRateCard(): bool
    {
        return $this->trainee_amount_cents > 0
            || $this->probationary_amount_cents > 0
            || $this->regular_amount_cents > 0;
    }

    /**
     * The three figures, keyed by tier, for a screen that draws the card.
     *
     * @return array<string, int>
     */
    public function rateCard(): array
    {
        $card = [];

        foreach (EmploymentType::tiers() as $tier) {
            $card[$tier->value] = $this->amountFor($tier);
        }

        return $card;
    }

    /**
     * What this job comes to on **one payslip**, in a sentence.
     *
     * The thing an office cannot work out from the form and most wants to know.
     * ₱15,000 a month is not ₱15,000 a payslip — it is ₱7,500 twice, and whether
     * it is twice at all depends on the firm's cutoff, which is set on a
     * different screen entirely.
     *
     * So the sentence is composed here, where the calendar can be asked. A client
     * dividing by two would be a second implementation of the pay schedule, and
     * the first firm it got wrong would be the one paying monthly.
     *
     * It speaks about the **regular** figure, because that is what the job pays
     * somebody who stays. The other two tiers sit beside it on screen and do not
     * each need a sentence.
     */
    public function paySummary(): string
    {
        $basis = $this->pay_basis ?? PayBasis::Monthly;
        $regular = (int) $this->regular_amount_cents;

        if ($regular <= 0) {
            return 'No rate set yet — the figure is entered on each hire.';
        }

        if ($basis === PayBasis::PerTrip) {
            return sprintf(
                'Each payslip pays %s for every haul delivered in that cutoff.',
                $this->pesos($regular),
            );
        }

        if ($basis === PayBasis::Daily) {
            return sprintf(
                'Each payslip pays %s for every day worked in that cutoff.',
                $this->pesos($regular),
            );
        }

        $runs = PayrollCalendar::for(app(Tenant::class)->company())->runsPerMonth();

        if ($runs === 1) {
            return sprintf('%s a month, paid on one payslip.', $this->pesos($regular));
        }

        /**
         * The first payslip's share, which is the one shown.
         *
         * `intdiv` down, because the **second** cutoff carries the remainder —
         * see `PayrollService::earningsFor()`. Showing the rounded-up half would
         * tell an office a number that only one of their two payslips will
         * actually say.
         */
        $each = intdiv($regular, $runs);

        return sprintf(
            '%s a month — about %s on each of %d payslips.',
            $this->pesos($regular),
            $this->pesos($each),
            $runs,
        );
    }

    /** `₱15,000` — centavos in, a readable figure out. */
    private function pesos(int $cents): string
    {
        return '₱'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
