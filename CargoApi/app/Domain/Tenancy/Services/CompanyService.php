<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class CompanyService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    public function current(): Company
    {
        $company = $this->tenant->company();

        if ($company === null) {
            throw new RuntimeException('No company in force. This should have been caught by BindTenant.');
        }

        return $company;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): Company
    {
        $company = $this->current();

        $company->update($attributes);

        return $company->refresh();
    }

    public function setLogo(UploadedFile $file): Company
    {
        $company = $this->current();

        $company->update([
            'logo_path' => $this->logos->replace($company->logo_path, $file),
        ]);

        return $company->refresh();
    }

    public function clearLogo(): Company
    {
        $company = $this->current();
        $existing = $company->logo_path;

        $company->update(['logo_path' => null]);
        $this->logos->remove($existing);

        return $company->refresh();
    }
}
