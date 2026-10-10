<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models\Concerns;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (self $model): void {
            if ($model->company_id !== null) {
                return;
            }

            $tenant = app(Tenant::class);

            if ($tenant->id() !== null && ! $tenant->isNobody()) {
                $model->company_id = $tenant->id();

                return;
            }

            if ($model->mayHaveNoCompany()) {
                return;
            }

            throw new RuntimeException(sprintf(
                'Refusing to create a %s with no company. Wrap the write in Tenant::use($company, ...) '
                .'to say which company it belongs to.',
                static::class,
            ));
        });
    }

    protected function mayHaveNoCompany(): bool
    {
        return false;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function acrossCompanies(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }
}
