<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One haulier on the platform.
 *
 * The only model in the system with no `company_id`, because it is the thing
 * every other `company_id` points at. Nothing scopes it, which is why nothing
 * outside `App\Domain\Tenancy` should be querying it — the rest of the
 * application asks `Tenant` which company is in force and never goes looking
 * for one by hand.
 */
class Company extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'logo_path', 'contact_name', 'contact_email', 'contact_phone', 'address', 'status',
        'latitude', 'longitude',
        'tin', 'vat_registered', 'vat_rate_bp',
        'payroll_deduct_on', 'payroll_cutoff_days',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusValue::class,
            // Read as numbers, not as the strings a decimal column hands back:
            // the clients put these straight on a map, and "8.4856000" is not
            // a latitude to a JSON parser.
            'latitude' => 'float',
            'longitude' => 'float',
            'vat_registered' => 'boolean',
            'vat_rate_bp' => 'integer',
            /**
             * Which cutoff the monthly contributions come off.
             *
             * The firm's own policy rather than a government rate, which is
             * why it is a column here and not a line in `config/cargo.php`.
             * See `DeductionSchedule`.
             */
            'payroll_deduct_on' => DeductionSchedule::class,

            /**
             * The days this firm's pay periods close on.
             *
             * An ascending list of one or two day-of-month numbers — `[15, 31]`
             * for the Philippine norm, `[10, 25]` for a firm that cuts off on
             * those days instead. Null means the install default, which is what
             * every company had before the column existed.
             *
             * A column rather than configuration for the same reason
             * `payroll_deduct_on` is one, and with more force: an environment
             * variable served one cutoff to every haulier on the platform. See
             * `PayrollCalendar`, which is the only thing that should read this
             * — never the raw array.
             */
            'payroll_cutoff_days' => 'array',
        ];
    }

    /**
     * The calendar this company runs payroll on.
     *
     * Here rather than left to each caller to assemble, so nothing outside
     * `PayrollCalendar` ever has to know what a null column means.
     */
    public function payrollCalendar(): PayrollCalendar
    {
        return PayrollCalendar::for($this);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Can this company's people sign in?
     *
     * The only question the status is asked. A suspended company keeps every
     * row it ever had; it simply has nobody in the building.
     */
    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    /**
     * Has this haulier said where it is?
     *
     * The pin is the difference between an address on a letterhead and a place
     * a distance can be measured to. Everything the carrier directory does
     * rests on it.
     */
    public function isPinned(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * The companies a customer can be offered.
     *
     * Active and pinned, and the pin is the opt-in: a firm appears to shippers
     * it has never met once it has said where its yard is, and not before. That
     * beats a separate "list us publicly" flag, which would be a switch nobody
     * finds and which would leave the honest answer — a company with no
     * location — indistinguishable from one that had declined.
     *
     * Suspended firms are absent for the plainer reason that nobody there can
     * sign in to confirm the request.
     */
    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
    }

    /**
     * How far this company's yard is from a point, in kilometres.
     *
     * Null when there is no pin — which is not zero, and the difference
     * matters: zero would sort an unpinned haulier to the top of a list of the
     * nearest ones.
     */
    public function distanceKmFrom(float $lat, float $lng): ?float
    {
        if (! $this->isPinned()) {
            return null;
        }

        return Geo::kmBetween((float) $this->latitude, (float) $this->longitude, $lat, $lng);
    }

    /**
     * A free slug built from the company's name.
     *
     * Codes are unique system-wide, so two firms both called "Southern Freight"
     * cannot both be `southern-freight` — the second becomes
     * `southern-freight-2`. Suffixing beats rejecting the registration: the
     * name is the company's own and is not theirs to change because somebody
     * else got here first, and the code is a handle rather than something they
     * chose.
     *
     * Deleted companies count. Their rows are still in every tenant table, and
     * reusing the code would make a support conversation about "southern-freight"
     * ambiguous between two different sets of books.
     */
    public static function codeFor(string $name): string
    {
        $stem = Str::slug($name) ?: 'company';
        $code = $stem;
        $suffix = 1;

        while (static::withTrashed()->where('code', $code)->exists()) {
            $code = $stem.'-'.(++$suffix);
        }

        return $code;
    }
}
