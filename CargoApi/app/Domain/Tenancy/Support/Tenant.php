<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Company;
use Closure;

class Tenant
{
    public const NOBODY = 'none';

    private ?string $companyId = null;

    private ?Company $company = null;

    public function id(): ?string
    {
        return $this->companyId;
    }

    public function check(): bool
    {
        return $this->companyId !== null;
    }

    public function company(): ?Company
    {
        if ($this->companyId === null || $this->isNobody()) {
            return null;
        }

        if ($this->company?->getKey() !== $this->companyId) {
            $this->company = Company::query()->find($this->companyId);
        }

        return $this->company;
    }

    public function set(Company|string|null $company): void
    {
        $this->companyId = $company instanceof Company ? $company->getKey() : $company;
        $this->company = $company instanceof Company ? $company : null;
    }

    public function forget(): void
    {
        $this->companyId = null;
        $this->company = null;
    }

    public function nobody(): void
    {
        $this->set(self::NOBODY);
    }

    public function isNobody(): bool
    {
        return $this->companyId === self::NOBODY;
    }

    /**
     * @template T
     *
     * @param Closure():
     * @return T
     */
    public function use(Company|string|null $company, Closure $callback): mixed
    {
        $previousId = $this->companyId;
        $previousCompany = $this->company;

        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->companyId = $previousId;
            $this->company = $previousCompany;
        }
    }

    /**
     * @template T
     *
     * @param Closure():
     * @return T
     */
    public function across(Closure $callback): mixed
    {
        return $this->use(null, $callback);
    }
}
