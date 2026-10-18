<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Shared\Enums\PaymentSourceKind;
use Illuminate\Database\Seeder;

class PaymentSourceSeeder extends Seeder
{
    public function run(): void
    {
        if (PaymentSource::withTrashed()->where('kind', PaymentSourceKind::Cash->value)->exists()) {
            return;
        }

        PaymentSource::create(['name' => 'Cash', 'kind' => PaymentSourceKind::Cash->value]);
    }
}
