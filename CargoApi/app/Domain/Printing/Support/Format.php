<?php

declare(strict_types=1);

namespace App\Domain\Printing\Support;

use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Carbon;

final class Format
{
    public static function money(?int $cents, string $currency = 'PHP'): string
    {
        $cents ??= 0;

        return ($cents < 0 ? '-' : '').$currency.' '.number_format(abs($cents) / 100, 2);
    }

    public static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Carbon::parse($value)->format('M j, Y');
    }

    public static function rate(?int $basisPoints): string
    {
        if (! $basisPoints) {
            return '';
        }

        return rtrim(rtrim(number_format($basisPoints / 100, 2), '0'), '.').'%';
    }

    /** @return array<string, string> */
    public static function companyFields(): array
    {
        return [
            'company.name' => 'Company name',
            'company.address' => 'Company address',
            'company.phone' => 'Company phone',
            'company.email' => 'Company email',
            'company.contact_name' => 'Company contact person',
            'company.tin' => 'Company TIN',
            'company.vat' => 'VAT registered (blank when not)',
        ];
    }

    /** @return array<string, string> */
    public static function companyValues(): array
    {
        $company = app(Tenant::class)->company();

        return [
            'company.name' => (string) ($company?->name ?? ''),
            'company.address' => (string) ($company?->address ?? ''),
            'company.phone' => (string) ($company?->contact_phone ?? ''),
            'company.email' => (string) ($company?->contact_email ?? ''),
            'company.contact_name' => (string) ($company?->contact_name ?? $company?->name ?? ''),
            'company.tin' => (string) ($company?->tin ?? ''),
            'company.vat' => $company?->vat_registered ? 'VAT registered' : '',
            'today' => now()->format('M j, Y'),
        ];
    }
}
