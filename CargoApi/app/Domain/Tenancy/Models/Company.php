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

class Company extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'logo_path', 'contact_name', 'contact_email', 'contact_phone', 'address', 'status',
        'latitude', 'longitude',
        'tin', 'vat_registered', 'vat_rate_bp', 'withholding_rate_bp', 'prices_include_vat',
        'payroll_deduct_on', 'payroll_benefits_enabled', 'payroll_cutoff_days', 'payroll_release_lag_days',
        'trucker_commission_bp',
        'billing_terms_days',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusValue::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'vat_registered' => 'boolean',
            'vat_rate_bp' => 'integer',
            'withholding_rate_bp' => 'integer',
            'prices_include_vat' => 'boolean',
            'trucker_commission_bp' => 'integer',
            'tariff_base_cents' => 'integer',
            'tariff_per_km_cents' => 'integer',
            'tariff_per_kg_cents' => 'integer',
            'tariff_minimum_cents' => 'integer',
            'billing_terms_days' => 'integer',
            'payroll_deduct_on' => DeductionSchedule::class,

            'payroll_benefits_enabled' => 'boolean',

            'payroll_cutoff_days' => 'array',

            'payroll_release_lag_days' => 'integer',
        ];
    }

    public function payrollCalendar(): PayrollCalendar
    {
        return PayrollCalendar::for($this);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function isPinned(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
    }

    public function distanceKmFrom(float $lat, float $lng): ?float
    {
        if (! $this->isPinned()) {
            return null;
        }

        return Geo::kmBetween((float) $this->latitude, (float) $this->longitude, $lat, $lng);
    }

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
